<?php

namespace NiceTry\Transfer;

use App\Support\PublicAddress;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Another NiceTryBot panel to pull an origin from, asked over HTTPS with an API token made there. The
 * address is someone's input, so it's held to a public HTTPS host: every address its name resolves to
 * must be public (no private, loopback, link-local, carrier-grade NAT or reserved ranges, so not this
 * server's network or a cloud's metadata service), the connection is pinned to the address that was
 * checked (a name can't resolve somewhere else between the check and the request), and redirects
 * aren't followed. Only a panel on this machine's own network, in the local environment, is let off.
 *
 * The token goes in the Authorization header of these two requests and nowhere else: never stored,
 * logged or put in a message.
 */
class TransferSource
{
    /** How long a pull may take, in seconds. */
    public const int TIMEOUT = 30;

    /**
     * @param  Closure(string): list<string>|null  $resolve  A hostname's addresses (A and AAAA); DNS by default.
     */
    public function __construct(private ?Closure $resolve = null)
    {
        //
    }

    /**
     * The panel's origins the token may transfer.
     *
     * @return list<array{id: int, name: string, origin_url: string, sites: int}>
     *
     * @throws InvalidTransferBundle
     */
    public function origins(string $panelUrl, string $token): array
    {
        $response = $this->get($panelUrl, $token, 'transfer/v1/origins');
        $origins = $response->json('origins');

        if (! is_array($origins) || ! array_is_list($origins)) {
            throw new InvalidTransferBundle(__('That panel didn\'t answer like a NiceTryBot panel with Transfer.'));
        }

        $list = [];

        foreach (array_slice($origins, 0, 500) as $origin) {
            if (! is_array($origin) || ! is_int($origin['id'] ?? null) || ! is_string($origin['name'] ?? null) || ! is_string($origin['origin_url'] ?? null)) {
                throw new InvalidTransferBundle(__('That panel didn\'t answer like a NiceTryBot panel with Transfer.'));
            }

            $list[] = [
                'id' => $origin['id'],
                'name' => mb_substr($origin['name'], 0, 100),
                'origin_url' => mb_substr($origin['origin_url'], 0, 255),
                'sites' => is_int($origin['sites'] ?? null) ? $origin['sites'] : 0,
            ];
        }

        return $list;
    }

    /**
     * One origin's transfer bundle, validated.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidTransferBundle
     */
    public function bundle(string $panelUrl, string $token, int $originId): array
    {
        return TransferBundle::decode($this->get($panelUrl, $token, "transfer/v1/origins/{$originId}")->body());
    }

    /**
     * The panel's base URL (scheme, host and port), once it's a public HTTPS host.
     *
     * @return array{base: string, host: string, port: int, address: string}
     *
     * @throws InvalidTransferBundle
     */
    public function check(string $panelUrl): array
    {
        $local = app()->environment('local');
        $parts = parse_url(trim($panelUrl));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '' || isset($parts['user']) || isset($parts['pass']) || ! in_array($scheme, $local ? ['https', 'http'] : ['https'], true)) {
            throw new InvalidTransferBundle(__('Enter the other panel\'s address as https://, like https://panel.example.com.'));
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve ?? self::dns(...))($host);

        if ($addresses === []) {
            throw new InvalidTransferBundle(__('Couldn\'t find :host. Check the address.', ['host' => $host]));
        }

        if (! $local) {
            foreach ($addresses as $address) {
                if (! self::isPublic($address)) {
                    throw new InvalidTransferBundle(__('That address isn\'t a public server, so a transfer can\'t be pulled from it.'));
                }
            }
        }

        $hostPart = str_contains($host, ':') ? "[{$host}]" : $host;
        $default = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return [
            'base' => "{$scheme}://{$hostPart}".($default ? '' : ":{$port}"),
            'host' => $host,
            'port' => $port,
            'address' => $addresses[0],
        ];
    }

    /**
     * Determine if an IP address is one the open internet reaches.
     */
    public static function isPublic(string $address): bool
    {
        return PublicAddress::isPublic($address);
    }

    /**
     * A hostname's IPv4 and IPv6 addresses, from DNS.
     *
     * @return list<string>
     */
    private static function dns(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_unique(array_filter(array_map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records))));
    }

    /**
     * Ask the panel, answering only a 200 with JSON of at most TransferBundle::MAX_BYTES.
     *
     * @throws InvalidTransferBundle
     */
    private function get(string $panelUrl, string $token, string $path): Response
    {
        $panel = $this->check($panelUrl);

        if (trim($token) === '') {
            throw new InvalidTransferBundle(__('Paste an API token from the other panel.'));
        }

        try {
            $response = $this->client($panel, trim($token))->get("{$panel['base']}/{$path}");
        } catch (ConnectionException) {
            throw new InvalidTransferBundle(__('Couldn\'t reach :host in :seconds seconds.', ['host' => $panel['host'], 'seconds' => self::TIMEOUT]));
        }

        if ($response->status() === 404 || $response->status() === 401 || $response->status() === 403) {
            throw new InvalidTransferBundle(__('That panel didn\'t accept the token, or doesn\'t have Transfer and Origins on. The token needs "Add and change domains", and must be for the one team with the origin, not all teams.'));
        }

        if (! $response->successful()) {
            throw new InvalidTransferBundle(__('That panel answered :status.', ['status' => $response->status()]));
        }

        if (strlen($response->body()) > TransferBundle::MAX_BYTES) {
            throw new InvalidTransferBundle(__('That transfer is larger than :size MB.', ['size' => TransferBundle::MAX_BYTES / 1024 / 1024]));
        }

        return $response;
    }

    /**
     * @param  array{base: string, host: string, port: int, address: string}  $panel
     */
    private function client(array $panel, string $token): PendingRequest
    {
        $address = str_contains($panel['address'], ':') ? "[{$panel['address']}]" : $panel['address'];

        return Http::withToken($token)
            ->acceptJson()
            ->timeout(self::TIMEOUT)
            ->connectTimeout(10)
            ->withoutRedirecting()
            ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$panel['host']}:{$panel['port']}:{$address}"]]]);
    }
}
