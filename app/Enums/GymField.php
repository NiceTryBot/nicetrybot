<?php

namespace App\Enums;

use App\Data\GymVisitor;
use App\Models\Team;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A visitor field a gym routine's entry conditions can test. Each field knows which operators make
 * sense for it, which values it offers, how to test a live visitor, and how to compile into the
 * Rules engine's expression tree so a routine can be backtested against logged traffic.
 */
enum GymField: string
{
    case Network = 'network';
    case Country = 'country';
    case Browser = 'browser';
    case UserAgent = 'ua';
    case Ip = 'ip';
    case Ja4 = 'ja4';
    case Domain = 'domain';
    case Category = 'category';
    case Path = 'path';
    case Verdict = 'verdict';
    case Tag = 'tag';

    /**
     * The value that stands for every hosting network, alongside the named providers.
     */
    public const string ANY_HOSTING = 'hosting';

    /**
     * The value that stands for a verdict from any rule.
     */
    public const string ANY_RULE = 'any';

    /**
     * Get the display label for the field.
     */
    public function label(): string
    {
        return match ($this) {
            self::Network => __('Network'),
            self::Country => __('Country'),
            self::Browser => __('Browser'),
            self::UserAgent => __('User agent'),
            self::Ip => __('IP address'),
            self::Ja4 => __('TLS fingerprint'),
            self::Domain => __('Domain'),
            self::Category => __('Path category'),
            self::Path => __('Path'),
            self::Verdict => __('Rule verdict'),
            self::Tag => __('Edge tag'),
        };
    }

    /**
     * Get the field as it reads mid-sentence, e.g. "whose network".
     */
    public function phrase(): string
    {
        return match ($this) {
            self::Network => __('whose network'),
            self::Country => __('whose country'),
            self::Browser => __('whose browser'),
            self::UserAgent => __('whose user agent'),
            self::Ip => __('whose IP'),
            self::Ja4 => __('whose TLS fingerprint'),
            self::Domain => __('whose domain'),
            self::Category => __('whose path category'),
            self::Path => __('whose path'),
            self::Verdict => __('who'),
            self::Tag => __('whose edge tag'),
        };
    }

    /**
     * Get the icon for the field.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Network => 'server-stack',
            self::Country => 'globe-americas',
            self::Browser => 'window',
            self::UserAgent => 'identification',
            self::Ip => 'map-pin',
            self::Ja4 => 'finger-print',
            self::Domain => 'globe-alt',
            self::Category => 'funnel',
            self::Path => 'link',
            self::Verdict => 'scale',
            self::Tag => 'tag',
        };
    }

    /**
     * Get the heading the field is listed under in the "add condition" menu.
     */
    public function group(): string
    {
        return match ($this) {
            self::Network, self::Country, self::Ip => __('Where they come from'),
            self::Browser, self::UserAgent, self::Ja4 => __('What they run'),
            self::Domain, self::Category, self::Path => __('What they ask for'),
            self::Verdict, self::Tag => __('What you already know'),
        };
    }

    /**
     * Get the operators the field supports, the default first.
     *
     * @return list<GymOperator>
     */
    public function operators(): array
    {
        return match ($this) {
            self::UserAgent, self::Path => [GymOperator::Contains, GymOperator::NotContains, GymOperator::StartsWith],
            self::Ja4 => [GymOperator::In, GymOperator::NotIn, GymOperator::StartsWith],
            self::Browser => [GymOperator::NotIn, GymOperator::In],
            default => [GymOperator::In, GymOperator::NotIn],
        };
    }

    /**
     * Get how the operator reads after the field's name, e.g. "Network is one of".
     */
    public function operatorLabel(GymOperator $operator): string
    {
        return match (true) {
            $this === self::Verdict => $operator === GymOperator::In ? __('has a verdict from') : __('has no verdict from'),
            $this === self::Ip => $operator === GymOperator::In ? __('is in') : __('is not in'),
            default => match ($operator) {
                GymOperator::In => __('is one of'),
                GymOperator::NotIn => __('is not one of'),
                GymOperator::Contains => __('contains'),
                GymOperator::NotContains => __('doesn\'t contain'),
                GymOperator::StartsWith => __('starts with'),
            },
        };
    }

    /**
     * Determine if values are picked from a list rather than typed.
     */
    public function isPicked(): bool
    {
        return in_array($this, [self::Network, self::Country, self::Browser, self::Domain, self::Category, self::Verdict], true);
    }

    /**
     * Get the values a picked field offers, as value => label.
     *
     * @return array<string, string>
     */
    public function options(Team $team): array
    {
        return match ($this) {
            self::Network => [self::ANY_HOSTING => __('Any hosting network')]
                + collect(HostingProvider::cases())->mapWithKeys(fn (HostingProvider $provider) => [$provider->value => $provider->label()])->sortBy(fn (string $label) => strtolower($label))->all(),
            self::Country => self::countries(),
            self::Browser => collect(BrowserFamily::cases())->mapWithKeys(fn (BrowserFamily $family) => [$family->value => $family->label()])->all(),
            self::Domain => $team->domains()->orderBy('hostname')->pluck('hostname', 'hostname')->all(),
            self::Category => collect(['probe' => __('Any probe')])
                ->merge(collect(PathCategory::cases())->mapWithKeys(fn (PathCategory $category) => [$category->slug() => $category->slug()]))
                ->all(),
            self::Verdict => [self::ANY_RULE => __('Any rule')]
                + $team->edgeRules()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn (string $name, int $id) => [(string) $id => $name])->all(),
            default => [],
        };
    }

    /**
     * Get the placeholder for typing a value.
     */
    public function placeholder(): string
    {
        return match ($this) {
            self::UserAgent => __('e.g. python-requests'),
            self::Ip => __('203.0.113.7 or 198.51.100.0/24'),
            self::Ja4 => __('e.g. t13d1516h2_8daaf6152771'),
            self::Path => __('e.g. /wp-login.php'),
            self::Tag => __('e.g. ua-tls-mismatch'),
            default => '',
        };
    }

    /**
     * Clean up a typed or picked value, or return null when it can't be a value of this field.
     */
    public function normalize(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 255) {
            return null;
        }

        return match ($this) {
            self::Ip => self::isIpOrRange($value) ? strtolower($value) : null,
            self::Country => preg_match('/^[A-Za-z]{2}$/', $value) === 1 ? strtoupper($value) : null,
            self::UserAgent, self::Path, self::Ja4, self::Tag => $value,
            self::Domain => strtolower($value),
            default => $value,
        };
    }

    /**
     * Get the message shown when a typed value is refused.
     */
    public function invalidValueMessage(): string
    {
        return match ($this) {
            self::Ip => __('Use an IP address or a CIDR range, like 198.51.100.0/24.'),
            self::Country => __('Use a two-letter country code.'),
            default => __('That value is too long.'),
        };
    }

    /**
     * Test a visitor against the field with a positive operator.
     *
     * @param  list<string>  $values
     */
    public function test(GymVisitor $visitor, GymOperator $operator, array $values): bool
    {
        return match ($this) {
            self::Network => $visitor->asn !== null && collect($values)->contains(fn (string $value) => $value === self::ANY_HOSTING
                ? NetworkKind::forAsn($visitor->asn) === NetworkKind::Hosting
                : HostingProvider::forAsn($visitor->asn)?->value === $value),
            self::Country => $visitor->countryCode !== null && in_array(strtoupper($visitor->countryCode), $values, true),
            self::Browser => collect($values)->contains(fn (string $value) => BrowserFamily::tryFrom($value)?->matches($visitor->userAgent) ?? false),
            self::UserAgent => self::testText($visitor->userAgent, $operator, $values),
            self::Path => self::testText($visitor->path, $operator, $values),
            self::Ip => IpUtils::checkIp($visitor->ip, $values),
            self::Ja4 => $visitor->ja4 !== null && ($operator === GymOperator::StartsWith
                ? collect($values)->contains(fn (string $value) => str_starts_with($visitor->ja4, $value))
                : in_array($visitor->ja4, $values, true)),
            self::Domain => $visitor->host !== null && in_array(strtolower($visitor->host), $values, true),
            self::Category => ($category = $visitor->category()) !== null
                && (in_array($category->slug(), $values, true) || in_array($category->family(), $values, true)),
            self::Verdict => in_array(self::ANY_RULE, $values, true)
                ? $visitor->verdictRuleIds !== []
                : array_intersect(array_map('strval', $visitor->verdictRuleIds), $values) !== [],
            self::Tag => $visitor->tag !== null && in_array($visitor->tag, $values, true),
        };
    }

    /**
     * Compile the field with a positive operator into a Rules expression node.
     *
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    public function expression(GymOperator $operator, array $values): array
    {
        return match ($this) {
            self::Network => self::any(array_map(fn (string $value) => $value === self::ANY_HOSTING
                ? ['kind' => 'compare', 'field' => RuleField::NetworkKind, 'operator' => '=', 'value' => 'hosting']
                : ['kind' => 'in', 'field' => RuleField::Asn, 'values' => HostingProvider::from($value)->asns(), 'negated' => false], $values)),
            self::Country => ['kind' => 'in', 'field' => RuleField::Country, 'values' => $values, 'negated' => false],
            self::Browser => self::any(array_values(collect($values)->flatMap(fn (string $value) => BrowserFamily::from($value) === BrowserFamily::None
                ? [['kind' => 'null', 'field' => RuleField::UserAgent, 'negated' => false]]
                : array_map(fn (string $pattern) => ['kind' => 'like', 'field' => RuleField::UserAgent, 'pattern' => $pattern, 'negated' => false], BrowserFamily::from($value)->likePatterns()))->all())),
            self::UserAgent, self::Path => self::any(array_map(fn (string $value) => [
                'kind' => 'like',
                'field' => $this === self::Path ? RuleField::Path : RuleField::UserAgent,
                'pattern' => ($operator === GymOperator::StartsWith ? '' : '%').self::escapeLike($value).'%',
                'negated' => false,
            ], $values)),
            self::Ip => self::any([
                ...(($exact = array_values(array_filter($values, fn (string $value) => ! str_contains($value, '/')))) === [] ? [] : [['kind' => 'in', 'field' => RuleField::Ip, 'values' => $exact, 'negated' => false]]),
                ...array_map(fn (string $range) => ['kind' => 'cidr', 'range' => $range], array_values(array_filter($values, fn (string $value) => str_contains($value, '/')))),
            ]),
            self::Ja4 => $operator === GymOperator::StartsWith
                ? self::any(array_map(fn (string $value) => ['kind' => 'like', 'field' => RuleField::Ja4, 'pattern' => self::escapeLike($value).'%', 'negated' => false], $values))
                : ['kind' => 'in', 'field' => RuleField::Ja4, 'values' => $values, 'negated' => false],
            self::Domain => ['kind' => 'in', 'field' => RuleField::Host, 'values' => $values, 'negated' => false],
            self::Category => ['kind' => 'in', 'field' => RuleField::Category, 'values' => $values, 'negated' => false],
            self::Verdict => ['kind' => 'verdict', 'rules' => in_array(self::ANY_RULE, $values, true) ? [] : array_map('intval', $values)],
            self::Tag => ['kind' => 'in', 'field' => RuleField::Tag, 'values' => $values, 'negated' => false],
        };
    }

    /**
     * Get the node that holds when the field is unknown for a request, so a negated condition can let
     * those requests in: a visitor with no user agent is certainly "not Chrome". Null when the field is
     * always known.
     *
     * @return array<string, mixed>|null
     */
    public function unknownExpression(): ?array
    {
        $field = match ($this) {
            self::Network => RuleField::Asn,
            self::Country => RuleField::Country,
            self::Browser, self::UserAgent => RuleField::UserAgent,
            self::Ja4 => RuleField::Ja4,
            self::Category => RuleField::Category,
            self::Path => RuleField::Path,
            self::Tag => RuleField::Tag,
            default => null,
        };

        return $field === null ? null : ['kind' => 'null', 'field' => $field, 'negated' => false];
    }

    /**
     * Get every country, as ISO code => English name.
     *
     * @return array<string, string>
     */
    public static function countries(): array
    {
        return once(function () {
            $countries = [];

            foreach (range('A', 'Z') as $first) {
                foreach (range('A', 'Z') as $second) {
                    $name = \Locale::getDisplayRegion('-'.$first.$second, 'en');

                    if (is_string($name) && $name !== '' && $name !== $first.$second && ! str_starts_with($name, 'Unknown')) {
                        $countries[$first.$second] = $name;
                    }
                }
            }

            asort($countries);

            return $countries;
        });
    }

    /**
     * Join nodes with OR, or return the only node.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    public static function any(array $nodes): array
    {
        return array_reduce(array_slice($nodes, 1), fn (array $left, array $right) => ['kind' => 'or', 'left' => $left, 'right' => $right], $nodes[0]);
    }

    /**
     * Join nodes with AND, or return the only node.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    public static function all(array $nodes): array
    {
        return array_reduce(array_slice($nodes, 1), fn (array $left, array $right) => ['kind' => 'and', 'left' => $left, 'right' => $right], $nodes[0]);
    }

    /**
     * @param  list<string>  $values
     */
    private static function testText(?string $subject, GymOperator $operator, array $values): bool
    {
        if ($subject === null) {
            return false;
        }

        $subject = strtolower($subject);

        return collect($values)->contains(fn (string $value) => $operator === GymOperator::StartsWith
            ? str_starts_with($subject, strtolower($value))
            : str_contains($subject, strtolower($value)));
    }

    private static function isIpOrRange(string $value): bool
    {
        [$address, $bits] = array_pad(explode('/', $value, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $maxBits = str_contains($address, ':') ? 128 : 32;

        return $bits === null || (ctype_digit($bits) && (int) $bits <= $maxBits);
    }

    /**
     * Lowercase a value for LIKE and drop any "%" in it. An "_" is left alone: as a LIKE wildcard it
     * still matches itself, and TLS fingerprints are full of them.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace('%', '', strtolower($value));
    }
}
