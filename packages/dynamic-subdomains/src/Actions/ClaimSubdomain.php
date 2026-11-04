<?php

namespace NiceTry\DynamicSubdomains\Actions;

use App\Addons\DomainLimit;
use App\Enums\DomainStatus;
use App\Enums\HostHeaderMode;
use App\Models\Domain;
use App\Support\HostnameClaimer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use NiceTry\DynamicSubdomains\Models\DynamicSubdomain;
use NiceTry\DynamicSubdomains\Models\DynamicSubdomainParent;
use NiceTry\RequestMonitor\Models\ResponseCapture;

class ClaimSubdomain implements HostnameClaimer
{
    public function __construct(private DomainLimit $limit)
    {
        //
    }

    /**
     * Add a hostname one label under a domain with dynamic subdomains, like foo.example.com under
     * example.com, as a domain of its own, live straight away: the domain's owner verified the name
     * it's under. It takes the domain's settings, with its origin moved to the same name under the
     * origin's host (https://origin.example → https://foo.origin.example). Null when no such domain
     * is live, it has added as many subdomains as it may, or the installation is at its domain limit.
     */
    public function claim(string $hostname): ?Domain
    {
        [$label, $parentHostname] = array_pad(explode('.', $hostname, 2), 2, '');

        if ($label === '' || ! str_contains($parentHostname, '.')) {
            return null;
        }

        $parent = Domain::query()
            ->where('hostname', $parentHostname)
            ->whereIn('id', DynamicSubdomainParent::query()->select('domain_id'))
            ->first();

        if (! $parent?->isServable() || $this->isFull($parent)) {
            return null;
        }

        try {
            $this->limit->ensureRoom();
        } catch (ValidationException) {
            return null;
        }

        return $this->add($parent, $label, $hostname);
    }

    /**
     * The origin URL a subdomain of the domain gets: the domain's, with the label in front of its host.
     * An origin reached by IP address can't have names under it, so its subdomains share it.
     */
    public static function originUrlFor(Domain $parent, string $label): string
    {
        $host = (string) parse_url($parent->origin_url, PHP_URL_HOST);

        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return $parent->origin_url;
        }

        // A callback, not a replacement string: a label starting with digits ("66bc…") would read as $16.
        return (string) preg_replace_callback(
            '#^([a-z][a-z0-9+.-]*://(?:[^@/]*@)?)'.preg_quote($host, '#').'#i',
            fn (array $match): string => $match[1].$label.'.'.$host,
            $parent->origin_url,
            1,
        );
    }

    /**
     * Determine if the domain has added as many subdomains as it may.
     */
    private function isFull(Domain $parent): bool
    {
        $full = DynamicSubdomain::query()->where('parent_domain_id', $parent->id)->count() >= (int) config('dynamic-subdomains.limit');

        if ($full) {
            Log::info('Dynamic subdomain refused: the domain is at its limit', ['domain' => $parent->id]);
        }

        return $full;
    }

    private function add(Domain $parent, string $label, string $hostname): Domain
    {
        try {
            return DB::transaction(function () use ($parent, $label, $hostname): Domain {
                $domain = $parent->team->domains()->create([
                    'hostname' => $hostname,
                    'origin_url' => self::originUrlFor($parent, $label),
                    'host_header_mode' => $parent->host_header_mode,
                    'origin_host_header' => $parent->host_header_mode === HostHeaderMode::Rewrite && $parent->origin_host_header
                        ? $label.'.'.$parent->origin_host_header
                        : null,
                    'forwarded_host_header' => $parent->forwarded_host_header,
                    'server_header' => $parent->server_header,
                    'origin_timeout_seconds' => $parent->origin_timeout_seconds,
                    'hsts_policy' => $parent->hsts_policy,
                    'cache_assets' => $parent->cache_assets,
                    'protection_mode' => $parent->protection_mode,
                    'status' => $parent->status === DomainStatus::Paused ? DomainStatus::Paused : DomainStatus::Active,
                    'verification_token' => Str::lower(Str::random(32)),
                    'origin_secret' => $parent->origin_secret,
                    'verified_at' => now(),
                ]);

                DynamicSubdomain::query()->create(['parent_domain_id' => $parent->id, 'domain_id' => $domain->id]);
                $this->copyCaptures($parent, $domain);

                return $domain;
            });
        } catch (UniqueConstraintViolationException) {
            // The edge asked twice at once (certificate and request); the other call added it.
            return Domain::query()->where('hostname', $hostname)->sole();
        }
    }

    /**
     * With Request Monitor installed, start the subdomain's own copy of each capture still recording on
     * the domain, until the same time and with the same limit, so what's watched on the domain is
     * watched under it too (like flags on every level of a capture-the-flag).
     */
    private function copyCaptures(Domain $parent, Domain $subdomain): void
    {
        if (! class_exists(ResponseCapture::class)) {
            return;
        }

        ResponseCapture::query()
            ->where('domain_id', $parent->id)
            ->recording()
            ->get()
            ->each(fn (ResponseCapture $capture) => $capture->replicate()->fill(['domain_id' => $subdomain->id])->save());
    }
}
