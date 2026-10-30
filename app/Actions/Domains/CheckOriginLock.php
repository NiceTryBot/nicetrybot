<?php

namespace App\Actions\Domains;

use App\Data\OriginLockCheck;
use App\Models\Domain;
use App\Support\PublicAddress;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class CheckOriginLock
{
    /**
     * How long to wait for the origin each time.
     */
    private const int TIMEOUT_SECONDS = 8;

    public function __construct(private LookUpDnsRecords $lookUpDnsRecords)
    {
        //
    }

    /**
     * Ask the domain's origin for its home page the way the edge does (same host and forwarded-host
     * headers), once without the origin secret and once with it.
     *
     * The origin URL is whatever the team typed, so this only ever calls a public address: the host
     * is resolved here, refused if any address is private, reserved or loopback, and the request is
     * pinned to the address that was checked, so a DNS answer can't change in between. Redirects
     * aren't followed: a 3xx with the secret counts as served.
     */
    public function handle(Domain $domain): OriginLockCheck
    {
        $route = $domain->edgeRoute();
        $port = (int) substr($route['upstream'], strrpos($route['upstream'], ':') + 1);
        $url = "{$route['scheme']}://{$route['server_name']}:{$port}/";

        $address = $this->publicAddress($route['server_name']);

        if ($address === null) {
            return OriginLockCheck::failed($domain->origin_url, __('The origin\'s host doesn\'t resolve to a public address, so it can\'t be checked from here.'));
        }

        $headers = array_filter([
            'Host' => $route['host_header'],
            $route['forwarded_host_header'] ?? 'X-Forwarded-Host' => $route['site_host'],
            'X-Forwarded-Proto' => 'https',
        ]);

        try {
            $withoutSecret = $this->status($url, $headers, $route['server_name'], $port, $address);
            $withSecret = $this->status($url, [...$headers, Domain::ORIGIN_SECRET_HEADER => $route['secret']], $route['server_name'], $port, $address);
        } catch (ConnectionException) {
            return OriginLockCheck::failed($domain->origin_url, __('The origin didn\'t answer within :seconds seconds.', ['seconds' => self::TIMEOUT_SECONDS]));
        }

        return new OriginLockCheck($domain->origin_url, $withoutSecret, $withSecret);
    }

    /**
     * The address to call the host on, or null when it has none or any of its addresses isn't public.
     */
    private function publicAddress(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            $records = $this->lookUpDnsRecords->handle($host);
            $addresses = [...$records['ipv4'], ...$records['ipv6']];
        }

        foreach ($addresses as $address) {
            if (! PublicAddress::isPublic($address)) {
                return null;
            }
        }

        return $addresses[0] ?? null;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function status(string $url, array $headers, string $host, int $port, string $address): int
    {
        $pinned = str_contains($address, ':') ? "[{$address}]" : $address;

        return Http::withHeaders($headers)
            ->withoutRedirecting()
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::TIMEOUT_SECONDS)
            ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinned}"]]])
            ->get($url)
            ->status();
    }
}
