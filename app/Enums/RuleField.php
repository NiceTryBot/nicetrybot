<?php

namespace App\Enums;

use App\Support\RuleFieldDefinition;
use Closure;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * A request field a rule script can test, one of the core's (add-ons bring theirs through
 * App\Support\RuleFields). Each field knows the SQL expression it reads, how to turn a script literal
 * into the stored value, and how to say itself in plain English.
 */
enum RuleField: string implements RuleFieldDefinition
{
    case Ip = 'ip';
    case Ja4 = 'ja4';
    case Ja4Alpn = 'ja4.alpn';
    case Ja3 = 'ja3';
    case UserAgent = 'ua';
    case Ja4h = 'ja4h';
    case ClientHints = 'hints';
    case Client = 'client';
    case Language = 'lang';
    case Host = 'host';
    case Path = 'path';
    case Category = 'category';
    case Status = 'status';
    case Method = 'method';
    case Duration = 'duration';
    case Action = 'action';
    case Tag = 'tag';
    case Asn = 'asn';
    case Network = 'network';
    case NetworkKind = 'network.kind';
    case Country = 'country';
    case Region = 'region';
    case City = 'city';

    /**
     * The values the client field takes: what a client's own parts give away (ClientTrait's families),
     * and whether it sits behind a corporate security gateway (InspectingProxy).
     *
     * @var list<string>
     */
    public const array CLIENT_VALUES = ['spoofed', 'outdated', 'corporate'];

    /**
     * Get the SQL expression the field reads, against edge_requests and its joined lookup tables. The
     * client field has no column of its own: BuildRuleQuery matches it against the profile side tables.
     */
    public function column(): string
    {
        return match ($this) {
            self::Ip => 'edge_requests.client_ip',
            self::Ja4 => 'ja4_fingerprints.value',
            self::Ja4Alpn => 'substr(ja4_fingerprints.value, 9, 2)',
            self::Ja3 => 'ja3_fingerprints.value',
            self::UserAgent => 'user_agents.value',
            self::Ja4h => 'ja4h_fingerprints.value',
            self::ClientHints => 'client_hints.value',
            self::Client => 'edge_requests.user_agent_id',
            self::Language => 'edge_requests.accept_language',
            self::Host => 'domains.hostname',
            self::Path => 'request_paths.path',
            self::Category => 'request_paths.category',
            self::Status => 'edge_requests.status_code',
            self::Method => 'edge_requests.method',
            self::Duration => 'edge_requests.duration_ms',
            self::Action => 'edge_requests.action',
            self::Tag => 'bot_tags.name',
            self::Asn => 'edge_requests.asn',
            self::Network => 'networks.name',
            self::NetworkKind => 'networks.kind',
            self::Country => 'locations.country_code',
            self::Region => 'locations.region',
            self::City => 'locations.city',
        };
    }

    /**
     * Get the joins the field reads through: none, since the core's lookups are joined into every rule
     * query (BuildRuleQuery::constrain()).
     *
     * @return array<string, Closure(Builder): mixed>
     */
    public function joins(): array
    {
        return [];
    }

    /**
     * Get the expression LIKE compares against: a lowercased string, so matching ignores case.
     */
    public function likeColumn(): string
    {
        return $this === self::Ip ? 'lower(cast(edge_requests.client_ip as text))' : 'lower('.$this->column().')';
    }

    /**
     * Determine if the field holds free text that LIKE can search.
     */
    public function isText(): bool
    {
        return in_array($this, [self::Ip, self::Ja4, self::Ja4Alpn, self::Ja3, self::UserAgent, self::Ja4h, self::ClientHints, self::Language, self::Host, self::Path, self::Tag, self::Network, self::Country, self::Region, self::City], true);
    }

    /**
     * Determine if the field is known while a request is happening, before the site answers: what the
     * client sent and where it comes from, but not the status, timing or what the edge did.
     */
    public function isLive(): bool
    {
        return ! in_array($this, [self::Ja3, self::Ja4h, self::ClientHints, self::Client, self::Status, self::Duration, self::Action], true);
    }

    /**
     * Determine if the field is a number that supports < and >.
     */
    public function isNumeric(): bool
    {
        return in_array($this, [self::Status, self::Duration, self::Asn], true);
    }

    /**
     * Convert a script literal into the value(s) stored for this field. Category matches a family
     * ("probe") or a slug ("probe/creds") and so may resolve to several stored values.
     *
     * @return list<int|string>
     *
     * @throws InvalidArgumentException When the literal can't be a value of this field.
     */
    public function storedValues(int|string $literal): array
    {
        return match ($this) {
            self::Status, self::Duration, self::Asn => is_int($literal)
                ? [$literal]
                : throw new InvalidArgumentException(__(':field takes a number, like :example.', ['field' => $this->value, 'example' => $this === self::Status ? '404' : '1000'])),
            self::Category => $this->categoryValues((string) $literal),
            self::Method => [HttpMethod::fromVerb((string) $literal) !== HttpMethod::Other || strtoupper((string) $literal) === 'OTHER'
                ? HttpMethod::fromVerb((string) $literal)->value
                : throw new InvalidArgumentException(__('Unknown method :value. Use GET, POST, HEAD, PUT, PATCH, DELETE or OPTIONS.', ['value' => $literal]))],
            self::Action => [(RequestAction::fromSlug((string) $literal) ?? throw new InvalidArgumentException(__('Unknown action :value. Use passed, observed, diverted or blocked.', ['value' => $literal])))->value],
            self::NetworkKind => [match (strtolower((string) $literal)) {
                'hosting' => NetworkKind::Hosting->value,
                'unknown', 'network' => NetworkKind::Unknown->value,
                default => throw new InvalidArgumentException(__('network.kind is \'hosting\' or \'unknown\'.')),
            }],
            self::Ip => [str_contains((string) $literal, '/') && ! self::isIpRange((string) $literal)
                ? throw new InvalidArgumentException(__(':value isn\'t an IP range. Write one like \'66.249.64.0/19\'.', ['value' => $literal]))
                : (string) $literal],
            self::Client => [in_array(strtolower((string) $literal), self::CLIENT_VALUES, true)
                ? strtolower((string) $literal)
                : throw new InvalidArgumentException(__('client is \'spoofed\', \'outdated\' or \'corporate\'.'))],
            self::Country => [strlen((string) $literal) === 2 ? strtoupper((string) $literal) : throw new InvalidArgumentException(__('country takes a two-letter code, like \'US\'.'))],
            default => [is_string($literal) ? $literal : (string) $literal],
        };
    }

    /**
     * Get every category a literal names: a whole family ("probe"), one slug, or slugs matching a
     * LIKE pattern ("probe/%").
     *
     * @return list<int>
     */
    public function categoryValues(string $literal, bool $isPattern = false): array
    {
        $literal = strtolower($literal);

        $matches = array_filter(PathCategory::cases(), function (PathCategory $category) use ($literal, $isPattern) {
            if ($isPattern) {
                return preg_match('#^'.str_replace(['%', '_'], ['.*', '.'], preg_quote($literal, '#')).'$#', $category->slug()) === 1;
            }

            return $category->slug() === $literal || $category->family() === $literal;
        });

        if ($matches === [] && ! $isPattern) {
            throw new InvalidArgumentException(__('Unknown category :value. Try a family (probe, asset, edge) or a slug like probe/creds.', ['value' => $literal]));
        }

        return array_values(array_map(fn (PathCategory $category) => $category->value, $matches));
    }

    /**
     * Determine if a value is an IP range in CIDR notation, e.g. "66.249.64.0/19".
     */
    public static function isIpRange(string $value): bool
    {
        [$address, $bits] = array_pad(explode('/', $value, 2), 2, null);

        if (! is_string($bits) || ! ctype_digit($bits) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return (int) $bits <= (str_contains($address, ':') ? 128 : 32);
    }

    /**
     * Get the field as people say it.
     */
    public function englishName(): string
    {
        return match ($this) {
            self::Ip => __('IP'),
            self::Ja4 => __('TLS fingerprint'),
            self::Ja4Alpn => __('offered ALPN'),
            self::Ja3 => __('JA3'),
            self::UserAgent => __('user agent'),
            self::Ja4h => __('header fingerprint'),
            self::ClientHints => __('client hints'),
            self::Client => __('client'),
            self::Language => __('accept-language'),
            self::Host => __('host'),
            self::Path => __('path'),
            self::Category => __('path category'),
            self::Status => __('status'),
            self::Method => __('method'),
            self::Duration => __('response time (ms)'),
            self::Action => __('edge action'),
            self::Tag => __('edge tag'),
            self::Asn => __('ASN'),
            self::Network => __('network'),
            self::NetworkKind => __('network kind'),
            self::Country => __('country'),
            self::Region => __('region'),
            self::City => __('city'),
        };
    }

    /**
     * Get the palette group the field is listed under.
     */
    public function group(): string
    {
        return match ($this) {
            self::Ip, self::Ja4, self::Ja4Alpn, self::Ja3, self::UserAgent, self::Ja4h, self::ClientHints, self::Client, self::Language => __('Client'),
            self::Host, self::Path, self::Category, self::Status, self::Method, self::Duration, self::Action, self::Tag => __('Request'),
            self::Asn, self::Network, self::NetworkKind, self::Country, self::Region, self::City => __('Network & place'),
        };
    }

    /**
     * Get a one-line hint shown in the field palette.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Ja4Alpn => __("'00' means no ALPN: a script, not a browser"),
            self::Ja4h => __('JA4H-style: method, version, cookie/referer, header count, language, then hashes'),
            self::ClientHints => __("Sec-CH-UA | platform, e.g. LIKE '%HeadlessChrome%'"),
            self::Client => __("'spoofed', 'outdated' or 'corporate' (behind a corporate security gateway)"),
            self::Language => __("e.g. 'en-US,en;q=0.9'; missing is a script tell"),
            self::Category => __("a family like 'probe' or a slug like 'probe/creds'"),
            self::NetworkKind => __("'hosting' for cloud and datacenter networks"),
            self::Status => __('e.g. 404, 429'),
            self::Duration => __('milliseconds'),
            default => '',
        };
    }

    /**
     * Get what Claude should know before writing a rule with the field, beyond its hint: nothing, for
     * the core's.
     */
    public function ruleNote(): string
    {
        return '';
    }
}
