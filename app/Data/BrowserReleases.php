<?php

namespace App\Data;

use App\Actions\Clients\FindCurrentChromeVersion;
use App\Actions\Clients\FindCurrentFirefoxReleases;
use App\Enums\ClientBrowser;

/**
 * The browser versions people run now, which "outdated" is measured against: Chrome's newest major
 * (read from traffic), and Firefox's newest major with the Extended Support Releases Mozilla still
 * ships, which enterprises and Tor Browser run well behind it. Null when unknown.
 */
readonly class BrowserReleases
{
    /**
     * @param  list<int>  $firefoxEsr  The major versions of the Firefox ESR lines still supported, like [153, 115].
     */
    public function __construct(
        public ?int $chrome = null,
        public ?int $firefox = null,
        public array $firefoxEsr = [],
    ) {
        //
    }

    /**
     * Get the versions as the edge's traffic and Mozilla's release list tell them now (both cached).
     */
    public static function now(): self
    {
        $firefox = app(FindCurrentFirefoxReleases::class)->handle();

        return new self(app(FindCurrentChromeVersion::class)->handle(), $firefox['current'], $firefox['esr']);
    }

    /**
     * Get the newest major version of a browser people run, if it's one whose version is tracked.
     */
    public function current(ClientBrowser $browser): ?int
    {
        return match ($browser) {
            ClientBrowser::Chromium => $this->chrome,
            ClientBrowser::Firefox => $this->firefox,
            default => null,
        };
    }

    /**
     * Get the older major versions of a browser that are still supported, and so never outdated.
     *
     * @return list<int>
     */
    public function supportedOlderVersions(ClientBrowser $browser): array
    {
        return $browser === ClientBrowser::Firefox ? $this->firefoxEsr : [];
    }
}
