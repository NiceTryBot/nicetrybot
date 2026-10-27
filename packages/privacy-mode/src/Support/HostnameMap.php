<?php

namespace NiceTry\PrivacyMode\Support;

use App\Models\Team;
use App\Support\PreviewHostname;
use App\Support\PrivacyHostname;
use App\Support\SharedOrigin;
use App\Support\SharedOrigins;
use Illuminate\Support\Facades\Cache;

/**
 * Every real host privacy mode hides for a team, with the stand-in it shows: each domain's own (the
 * core gives every domain one), its preview's, and a made-up name for each host its origins live on,
 * its shared origins' (App\Support\SharedOrigins) among them.
 */
class HostnameMap
{
    /**
     * Origin hosts get a fixed stand-in under their own zone, so they never collide with a domain's.
     */
    public const string ORIGIN_SUFFIX = 'hidden-origin.com';

    /**
     * The same made-up name for an origin host every time, e.g. sites.example.com → curious-otter.hidden-origin.com.
     */
    public static function forOrigin(string $host): string
    {
        $seed = crc32(strtolower($host));
        $adjective = PrivacyHostname::ADJECTIVES[$seed % count(PrivacyHostname::ADJECTIVES)];
        $noun = PrivacyHostname::NOUNS[intdiv($seed, count(PrivacyHostname::ADJECTIVES)) % count(PrivacyHostname::NOUNS)];

        return $adjective.'-'.$noun.'-'.substr(md5(strtolower($host)), 0, 4).'.'.self::ORIGIN_SUFFIX;
    }

    /**
     * A short fingerprint of a team's domains and origins, which changes whenever its host map could.
     */
    public static function versionFor(Team $team): string
    {
        $domains = $team->domains()->toBase()->selectRaw('count(*) as total, max(updated_at) as updated')->first();
        $origins = app(SharedOrigins::class)->origins($team);

        return substr(md5(implode('|', [$team->id, $domains?->total, $domains?->updated, $origins->count(), $origins->max(fn (SharedOrigin $origin) => $origin->updatedAt?->getTimestamp())])), 0, 16);
    }

    /**
     * The team's host map as base64 JSON, so the source doesn't list every real hostname in plain text.
     * Cached per version, so it's built once each time the team's domains or origins change.
     */
    public static function encodedMapFor(Team $team): string
    {
        return Cache::remember(
            'privacy-hostnames:'.$team->id.':'.self::versionFor($team),
            now()->addDay(),
            fn (): string => base64_encode(json_encode((object) self::mapFor($team), JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * Every real host privacy mode hides for a team, with its stand-in: the team's domains, their
     * previews (as previews of the stand-ins), then the hosts their origins (and the team's shared
     * origins) live on.
     *
     * @return array<string, string>
     */
    public static function mapFor(Team $team): array
    {
        $map = $team->domains()->whereNotNull('privacy_hostname')->pluck('privacy_hostname', 'hostname')->all();

        $previews = $team->domains()->whereNotNull('privacy_hostname')->whereNotNull('preview_hostname')->pluck('privacy_hostname', 'preview_hostname');

        foreach ($previews as $preview => $standIn) {
            // With previews since turned off, a preview left on a domain hides behind the stand-in itself.
            $map[$preview] = PreviewHostname::derive($standIn) ?? $standIn;
        }

        $origins = app(SharedOrigins::class)->origins($team);

        $originHosts = $team->domains()->pluck('origin_url')
            ->merge($origins->map(fn (SharedOrigin $origin): string => $origin->originUrl))
            ->merge($origins->map(fn (SharedOrigin $origin): ?string => $origin->authorizeUrl)->filter())
            ->map(fn (string $url): string => (string) parse_url($url, PHP_URL_HOST))
            ->merge($team->domains()->whereNotNull('origin_host_header')->pluck('origin_host_header'))
            ->merge($origins->map(fn (SharedOrigin $origin): ?string => $origin->originHostHeader)->filter());

        foreach ($originHosts as $host) {
            $host = strtolower(trim($host, '[]'));

            if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false && ! isset($map[$host])) {
                $map[$host] = self::forOrigin($host);
            }
        }

        return $map;
    }
}
