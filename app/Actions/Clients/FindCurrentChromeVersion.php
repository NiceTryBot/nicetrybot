<?php

namespace App\Actions\Clients;

use App\Enums\ClientBrowser;
use App\Enums\ClientPlatform;
use App\Enums\TlsStack;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Find the newest Chrome major version people are running, from the edge's own traffic, so "outdated"
 * keeps up with Chrome's four-week releases without anyone editing a number.
 *
 * Only real Chrome counts: a Chromium TLS handshake carrying client hints, off iOS. Scripts with a copied
 * user agent fail that, and a version needs enough distinct clients that a beta tester or a single
 * farm can't move it.
 */
class FindCurrentChromeVersion
{
    /**
     * The fewest distinct client IPs a major version needs this week to count as current.
     */
    public const int MIN_CLIENTS = 25;

    private const string CACHE_KEY = 'clients.current-chrome-version';

    /**
     * Get the current Chrome major version, or null when there isn't enough real Chrome traffic to tell.
     */
    public function handle(): ?int
    {
        // Cached as 0 when unknown, since the cache can't hold a null.
        $version = Cache::remember(self::CACHE_KEY, now()->addHours(6), fn () => $this->fromTraffic() ?? 0);

        return $version === 0 ? null : $version;
    }

    private function fromTraffic(): ?int
    {
        $version = DB::table('edge_requests')
            ->join('user_agent_profiles', 'user_agent_profiles.user_agent_id', '=', 'edge_requests.user_agent_id')
            ->join('tls_fingerprint_profiles', 'tls_fingerprint_profiles.tls_fingerprint_id', '=', 'edge_requests.ja4_id')
            ->where('edge_requests.occurred_at', '>=', now()->subDays(7))
            ->whereNotNull('edge_requests.client_hints_id')
            ->where('user_agent_profiles.browser', ClientBrowser::Chromium)
            ->where('user_agent_profiles.platform', '!=', ClientPlatform::Ios)
            ->whereNotNull('user_agent_profiles.major_version')
            ->where('tls_fingerprint_profiles.stack', TlsStack::Chromium)
            ->groupBy('user_agent_profiles.major_version')
            ->havingRaw('count(distinct edge_requests.client_ip) >= ?', [self::MIN_CLIENTS])
            ->orderByDesc('user_agent_profiles.major_version')
            ->value('user_agent_profiles.major_version');

        return $version === null ? null : (int) $version;
    }
}
