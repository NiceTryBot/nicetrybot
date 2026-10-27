<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Which NiceTryBot release this panel runs, and the newest one published. `nicetry:check-release`
 * asks once a day and keeps the answer under CACHE_KEY; the Admin section shows administrators an
 * update notice while it's newer than this one.
 */
final class Releases
{
    /**
     * Where the newest release is kept: a plain array, ['version' => '1.3.0', 'url' => ..., 'checked_at' => <unix time>].
     */
    public const string CACHE_KEY = 'nicetry.latest-release';

    /**
     * How long an answer is kept, so a few days of failed checks don't drop it.
     */
    public const int CACHE_SECONDS = 3 * 24 * 60 * 60;

    /**
     * The release this panel runs (bare, like 1.2.0), or null when it runs from source or as the hosted service.
     */
    public static function current(): ?string
    {
        $release = config('nicetry.release');

        return is_string($release) && $release !== '' ? $release : null;
    }

    /**
     * The newest release the last check found, or null when there hasn't been one.
     *
     * @return array{version: string, url: string, checked_at: int}|null
     */
    public static function latest(): ?array
    {
        $latest = Cache::get(self::CACHE_KEY);

        if (! is_array($latest) || ! is_string($latest['version'] ?? null) || ! is_string($latest['url'] ?? null)) {
            return null;
        }

        return ['version' => $latest['version'], 'url' => $latest['url'], 'checked_at' => (int) ($latest['checked_at'] ?? 0)];
    }

    /**
     * The newest release when it's newer than the one this panel runs, otherwise null.
     *
     * @return array{version: string, url: string, checked_at: int}|null
     */
    public static function newer(): ?array
    {
        $current = self::current();
        $latest = self::latest();

        if ($current === null || $latest === null) {
            return null;
        }

        return version_compare($latest['version'], $current, '>') ? $latest : null;
    }

    /**
     * Keep the newest release a check found.
     */
    public static function remember(string $version, string $url): void
    {
        Cache::put(self::CACHE_KEY, ['version' => $version, 'url' => $url, 'checked_at' => now()->getTimestamp()], self::CACHE_SECONDS);
    }
}
