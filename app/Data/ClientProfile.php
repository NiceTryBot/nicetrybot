<?php

namespace App\Data;

use App\Enums\ClientBrowser;
use App\Enums\ClientPlatform;
use App\Enums\ClientTrait;
use App\Enums\HttpMethod;
use App\Enums\InspectingProxy;
use App\Enums\TlsStack;
use App\Models\EdgeRequest;

/**
 * What a client claims to be (its user agent and client hints) beside what it is (its TLS stack and
 * network), read from one request. A real browser's parts agree; a disguise usually gives itself away
 * in one of them.
 */
readonly class ClientProfile
{
    public function __construct(
        public ClientPlatform $platform,
        public ClientBrowser $browser,
        public ?int $majorVersion,
        public TlsStack $tlsStack,
        public ClientPlatform $hintsPlatform,
        public bool $isBehindInspectingProxy,
        public ?bool $sentClientHints = null,
    ) {
        //
    }

    /**
     * Read a client from its parts. Pass $hintsHeard when the client hints were listened for, so their
     * absence means the client sent none rather than that nobody recorded them.
     */
    public static function of(?string $userAgent, ?string $ja4, ?string $clientHints, ?int $asn, bool $hintsHeard = false): self
    {
        $userAgent = (string) $userAgent;
        $browser = $userAgent === '' ? ClientBrowser::Unknown : ClientBrowser::fromUserAgent($userAgent);

        return new self(
            platform: $userAgent === '' ? ClientPlatform::Unknown : ClientPlatform::fromUserAgent($userAgent),
            browser: $browser,
            majorVersion: $browser->majorVersionIn($userAgent),
            tlsStack: TlsStack::fromJa4($ja4),
            hintsPlatform: (string) $clientHints === '' ? ClientPlatform::Unknown : ClientPlatform::fromClientHints((string) $clientHints),
            isBehindInspectingProxy: InspectingProxy::forAsn($asn) !== null,
            sentClientHints: (string) $clientHints !== '' ? true : ($hintsHeard ? false : null),
        );
    }

    /**
     * Read the client of one request. The edge has logged client hints since EdgeRequest::CLIENT_HINTS_SINCE,
     * on every request but a CORS preflight, which browsers send without them.
     */
    public static function forRequest(EdgeRequest $request): self
    {
        $hintsHeard = $request->occurred_at?->greaterThanOrEqualTo(EdgeRequest::CLIENT_HINTS_SINCE) && $request->method !== HttpMethod::Options;

        return self::of($request->user_agent, $request->ja4, $request->client_hints, $request->asn, (bool) $hintsHeard);
    }

    /**
     * Get the traits this client shows, given the browser versions people run now.
     *
     * @return list<ClientTrait>
     */
    public function traits(BrowserReleases $releases): array
    {
        return array_values(array_filter(ClientTrait::cases(), fn (ClientTrait $trait) => $trait->matches($this, $releases)));
    }

    /**
     * Get the one family the client is counted under, as charts split clients: "spoofed" when it shows
     * any spoofed trait, else "outdated" when it runs an old browser, else null.
     */
    public function family(BrowserReleases $releases): ?string
    {
        $traits = $this->traits($releases);

        return match (true) {
            collect($traits)->contains(fn (ClientTrait $trait) => $trait->isSpoofed()) => 'spoofed',
            $traits !== [] => 'outdated',
            default => null,
        };
    }

    /**
     * Get how many major versions behind the current one this client's browser is, if it's a Chrome or
     * Firefox whose current version is known.
     */
    public function versionsBehind(BrowserReleases $releases): ?int
    {
        $current = $releases->current($this->browser);

        return $this->majorVersion !== null && $current !== null ? $current - $this->majorVersion : null;
    }

    /**
     * Determine if this client runs a browser version that's still supported however far behind it is,
     * like a Firefox ESR.
     */
    public function runsSupportedOlderVersion(BrowserReleases $releases): bool
    {
        return in_array($this->majorVersion, $releases->supportedOlderVersions($this->browser), true);
    }
}
