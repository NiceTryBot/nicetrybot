<?php

namespace App\Enums;

use App\Data\BrowserReleases;
use App\Data\ClientProfile;
use App\Models\EdgeRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Something a client's own parts give away about it, whichever request it sends. Spoofed clients
 * claim to be a browser their TLS or client hints contradict; outdated ones run a Chrome or Firefox
 * far older than people do (a Firefox ESR Mozilla still supports doesn't count).
 *
 * Each trait is checked two ways that must agree: matches() on one request's profile, for showing it,
 * and constrain() in SQL against the profile side tables, for filtering and counting.
 */
enum ClientTrait: string
{
    case IosOnChromeTls = 'spoofed/ios-tls';
    case IosClientHints = 'spoofed/ios-hints';
    case ChromeOnOtherTls = 'spoofed/chrome-tls';
    case ChromeWithoutHints = 'spoofed/chrome-no-hints';
    case HintsPlatform = 'spoofed/hints-platform';
    case Outdated = 'outdated';

    /**
     * The oldest Chrome counted on to send client hints. Chrome has sent them on every secure request
     * since 89; older ones are left alone.
     */
    private const int CHROME_SENDS_HINTS_FROM = 100;

    /**
     * Get the traits that mean a client isn't the browser it claims to be.
     *
     * @return list<self>
     */
    public static function spoofed(): array
    {
        return array_values(array_filter(self::cases(), fn (self $trait) => $trait->isSpoofed()));
    }

    /**
     * Get the traits a filter value ("spoofed" or "outdated") stands for.
     *
     * @return list<self>
     */
    public static function forFilter(string $filter): array
    {
        return array_values(array_filter(self::cases(), fn (self $trait) => $trait->family() === $filter));
    }

    /**
     * Keep only the requests from clients showing any of the given traits.
     *
     * @param  list<self>  $traits
     */
    public static function constrainAny(Builder|Relation $query, array $traits, BrowserReleases $releases): Builder|Relation
    {
        return $query->where(function ($query) use ($traits, $releases) {
            $query->whereRaw('1 = 0');

            foreach ($traits as $trait) {
                $query->orWhere(fn ($query) => $trait->constrain($query, $releases));
            }
        });
    }

    public function isSpoofed(): bool
    {
        return $this !== self::Outdated;
    }

    /**
     * Get the group the trait is shown and filtered under: "spoofed" or "outdated".
     */
    public function family(): string
    {
        return $this->isSpoofed() ? 'spoofed' : 'outdated';
    }

    /**
     * Determine if a client shows this trait.
     */
    public function matches(ClientProfile $client, BrowserReleases $releases): bool
    {
        return match ($this) {
            self::IosOnChromeTls => $client->platform === ClientPlatform::Ios
                && $client->browser !== ClientBrowser::Bot
                && $client->tlsStack === TlsStack::Chromium
                && ! $client->isBehindInspectingProxy,
            self::IosClientHints => $client->hintsPlatform === ClientPlatform::Ios,
            self::ChromeOnOtherTls => $client->browser === ClientBrowser::Chromium
                && $client->platform !== ClientPlatform::Ios
                && in_array($client->tlsStack, self::nonChromiumStacks(), true)
                && ! $client->isBehindInspectingProxy,
            self::ChromeWithoutHints => $client->browser === ClientBrowser::Chromium
                && in_array($client->platform, self::hintingPlatforms(), true)
                && $client->majorVersion !== null && $client->majorVersion >= self::CHROME_SENDS_HINTS_FROM
                && $client->sentClientHints === false
                && ! $client->isBehindInspectingProxy,
            self::HintsPlatform => $client->browser !== ClientBrowser::Bot
                && $client->platform !== ClientPlatform::Unknown
                && ! in_array($client->hintsPlatform, [ClientPlatform::Unknown, ClientPlatform::Ios, $client->platform], true)
                && ! self::isDesktopSiteOnAndroid($client->platform, $client->hintsPlatform),
            self::Outdated => $client->versionsBehind($releases) !== null
                && $client->versionsBehind($releases) >= $client->platform->outdatedAfter()
                && ! $client->runsSupportedOlderVersion($releases),
        };
    }

    /**
     * Keep only the requests whose clients show this trait.
     */
    public function constrain(Builder|Relation $query, BrowserReleases $releases): Builder|Relation
    {
        return match ($this) {
            self::IosOnChromeTls => self::outsideInspectingProxies($query
                ->whereIn('user_agent_id', DB::table('user_agent_profiles')->where('platform', ClientPlatform::Ios)->where('browser', '!=', ClientBrowser::Bot)->select('user_agent_id'))
                ->whereIn('ja4_id', DB::table('tls_fingerprint_profiles')->where('stack', TlsStack::Chromium)->select('tls_fingerprint_id'))),
            self::IosClientHints => $query
                ->whereIn('client_hints_id', DB::table('client_hint_profiles')->where('platform', ClientPlatform::Ios)->select('client_hint_id')),
            self::ChromeOnOtherTls => self::outsideInspectingProxies($query
                ->whereIn('user_agent_id', DB::table('user_agent_profiles')->where('browser', ClientBrowser::Chromium)->where('platform', '!=', ClientPlatform::Ios)->select('user_agent_id'))
                ->whereIn('ja4_id', DB::table('tls_fingerprint_profiles')->whereIn('stack', self::nonChromiumStacks())->select('tls_fingerprint_id'))),
            self::ChromeWithoutHints => self::withoutHintsHeard(self::outsideInspectingProxies($query
                ->whereIn('user_agent_id', DB::table('user_agent_profiles')
                    ->where('browser', ClientBrowser::Chromium)
                    ->whereIn('platform', self::hintingPlatforms())
                    ->where('major_version', '>=', self::CHROME_SENDS_HINTS_FROM)
                    ->select('user_agent_id')))),
            // One set of hints and one of user agents per platform the hints name, so the database hashes
            // each set once rather than looking up both profiles for every request.
            self::HintsPlatform => $query->where(function ($query) {
                $query->whereRaw('1 = 0');

                foreach (array_filter(ClientPlatform::cases(), fn (ClientPlatform $platform) => ! in_array($platform, [ClientPlatform::Unknown, ClientPlatform::Ios], true)) as $hinted) {
                    $query->orWhere(fn ($query) => $query
                        ->whereIn('client_hints_id', DB::table('client_hint_profiles')->where('platform', $hinted)->select('client_hint_id'))
                        ->whereIn('user_agent_id', DB::table('user_agent_profiles')
                            ->where('browser', '!=', ClientBrowser::Bot)
                            ->whereNotIn('platform', array_filter([ClientPlatform::Unknown, $hinted, $hinted === ClientPlatform::Android ? ClientPlatform::Linux : null]))
                            ->select('user_agent_id')));
                }
            }),
            self::Outdated => $query->whereIn('user_agent_id', DB::table('user_agent_profiles')
                ->where(function ($query) use ($releases) {
                    $query->whereRaw('1 = 0');

                    foreach ([ClientBrowser::Chromium, ClientBrowser::Firefox] as $browser) {
                        if (($current = $releases->current($browser)) === null) {
                            continue;
                        }

                        $query->orWhere(fn ($query) => $query->where('browser', $browser)
                            ->whereNotIn('major_version', $releases->supportedOlderVersions($browser))
                            ->where(function ($query) use ($current) {
                                foreach (ClientPlatform::cases() as $platform) {
                                    $query->orWhere(fn ($query) => $query->where('platform', $platform)->where('major_version', '<=', $current - $platform->outdatedAfter()));
                                }
                            }));
                    }
                })
                ->select('user_agent_id')),
        };
    }

    public function label(): string
    {
        return $this->isSpoofed() ? __('Spoofed') : __('Outdated');
    }

    public function color(): string
    {
        return $this->isSpoofed() ? 'rose' : 'amber';
    }

    /**
     * Say what gave this client away, in a sentence.
     */
    public function explain(ClientProfile $client, BrowserReleases $releases): string
    {
        return match ($this) {
            self::IosOnChromeTls => __('Its user agent says iOS, but it connected with Chrome\'s TLS. Every iOS browser uses Apple\'s.'),
            self::IosClientHints => __('Its client hints claim iOS, but no iOS browser sends client hints.'),
            self::ChromeOnOtherTls => __('Its user agent says Chrome, but it connected with :stack.', ['stack' => $client->tlsStack->label()]),
            self::ChromeWithoutHints => __('Its user agent says Chrome :version, but it sent no client hints. Chrome sends them with every request.', ['version' => $client->majorVersion]),
            self::HintsPlatform => __('Its user agent says :platform, but its client hints say :hints.', ['platform' => $client->platform->label(), 'hints' => $client->hintsPlatform->label()]),
            self::Outdated => __(':browser :version on :platform, :behind versions behind the current :current. :browser updates itself, so people are rarely this far back.', [
                'browser' => $client->browser->label(),
                'version' => $client->majorVersion,
                'platform' => $client->platform->label(),
                'behind' => $client->versionsBehind($releases),
                'current' => $releases->current($client->browser),
            ]),
        };
    }

    /**
     * Get the TLS stacks a real Chrome off iOS never connects with. QUIC and unknown handshakes
     * can't be told apart, so they're left out.
     *
     * @return list<TlsStack>
     */
    private static function nonChromiumStacks(): array
    {
        return [TlsStack::Apple, TlsStack::Firefox, TlsStack::Other];
    }

    /**
     * Get the platforms Chrome sends client hints on: all but iOS, where every browser is WebKit.
     *
     * @return list<ClientPlatform>
     */
    private static function hintingPlatforms(): array
    {
        return [ClientPlatform::Windows, ClientPlatform::Mac, ClientPlatform::Linux, ClientPlatform::ChromeOs, ClientPlatform::Android];
    }

    /**
     * Determine if a client is Chrome on Android asking for the desktop site: its user agent then says
     * Linux, while its client hints still say Android.
     */
    private static function isDesktopSiteOnAndroid(ClientPlatform $platform, ClientPlatform $hintsPlatform): bool
    {
        return $platform === ClientPlatform::Linux && $hintsPlatform === ClientPlatform::Android;
    }

    /**
     * Keep the requests that came without client hints when the edge was listening for them: since it
     * began logging them, and not CORS preflights, which browsers send without. Only edge requests know
     * that; other tables keeping a client's lookups (JS fingerprint sightings) can't tell, so none match.
     */
    private static function withoutHintsHeard(Builder|Relation $query): Builder|Relation
    {
        if (! $query->getModel() instanceof EdgeRequest) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereNull('edge_requests.client_hints_id')
            ->where('edge_requests.occurred_at', '>=', EdgeRequest::CLIENT_HINTS_SINCE)
            ->where('edge_requests.method', '!=', HttpMethod::Options);
    }

    /**
     * Leave out requests from corporate gateways that replace the browser's TLS handshake with their own.
     * On edge_requests the column is qualified, since the Activity search joins networks, which has an
     * asn too; other tables keeping a client's lookups (JS fingerprint sightings) name it alone.
     */
    private static function outsideInspectingProxies(Builder|Relation $query): Builder|Relation
    {
        $asn = $query->getModel() instanceof EdgeRequest ? 'edge_requests.asn' : 'asn';

        return $query->where(fn ($query) => $query->whereNull($asn)->orWhereNotIn($asn, InspectingProxy::allAsns()));
    }
}
