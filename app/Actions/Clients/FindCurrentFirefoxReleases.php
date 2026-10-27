<?php

namespace App\Actions\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Find the Firefox versions people run now, from the release list Mozilla publishes: the newest major,
 * and the Extended Support Releases still supported (enterprises and Tor Browser run those well behind
 * it, and aren't outdated for it).
 *
 * The list is downloaded on a schedule (clients:refresh-firefox) and read from the cache, so a page
 * never waits on Mozilla. Until the first download, Firefox's version is unknown.
 */
class FindCurrentFirefoxReleases
{
    public const string URL = 'https://product-details.mozilla.org/1.0/firefox_versions.json';

    private const string CACHE_KEY = 'clients.current-firefox-releases';

    /**
     * Get the newest Firefox major and the supported ESR majors, as last downloaded.
     *
     * @return array{current: ?int, esr: list<int>}
     */
    public function handle(): array
    {
        $releases = Cache::get(self::CACHE_KEY);

        return is_array($releases) ? $releases : ['current' => null, 'esr' => []];
    }

    /**
     * Download Mozilla's release list and keep it until the next download.
     *
     * @return array{current: ?int, esr: list<int>}
     *
     * @throws ConnectionException
     * @throws RequestException When Mozilla answers with an error.
     */
    public function refresh(): array
    {
        $versions = Http::timeout(10)->acceptJson()->get(self::URL)->throw()->json();

        $releases = [
            'current' => self::major($versions['LATEST_FIREFOX_VERSION'] ?? null),
            'esr' => collect($versions)
                ->filter(fn (mixed $version, string $key) => str_starts_with($key, 'FIREFOX_ESR'))
                ->map(fn (mixed $version) => self::major($version))
                ->filter()
                ->unique()
                ->sortDesc()
                ->values()
                ->all(),
        ];

        if ($releases['current'] !== null) {
            Cache::forever(self::CACHE_KEY, $releases);
        }

        return $releases;
    }

    /**
     * Read the major version from a version like "153.5.0esr".
     */
    private static function major(mixed $version): ?int
    {
        return is_string($version) && preg_match('/^(\d+)\./', $version, $matches) === 1 ? (int) $matches[1] : null;
    }
}
