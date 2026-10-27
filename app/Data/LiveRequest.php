<?php

namespace App\Data;

use App\Enums\HttpMethod;
use App\Enums\NetworkKind;
use App\Enums\PathCategory;
use App\Enums\RuleField;
use App\Support\RuleFieldDefinition;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * One request as the edge sees it while it's happening, before it's logged: enough to test a rule
 * expression (such as a rule's UNTIL, or a single-request rule) against it right away. Fields the edge
 * doesn't know yet, like the status before the origin answers, are unknown, and an expression that
 * depends on them evaluates to null rather than true or false. An edge that describes the client
 * ($described) also tells its language, place, network and edge tag, each of which may be missing.
 */
readonly class LiveRequest
{
    public function __construct(
        public string $method,
        public string $host,
        public string $path,
        public ?int $status = null,
        public ?string $ip = null,
        public ?string $ja4 = null,
        public ?string $userAgent = null,
        public bool $described = false,
        public ?string $language = null,
        public ?string $countryCode = null,
        public ?string $region = null,
        public ?string $city = null,
        public ?int $asn = null,
        public ?string $network = null,
        public ?string $tag = null,
    ) {
        //
    }

    /**
     * Evaluate a rule expression node: true, false, or null when it hinges on something unknown.
     *
     * @param  array<string, mixed>  $node
     */
    public function evaluate(array $node): ?bool
    {
        return match ($node['kind']) {
            'and' => $this->and($this->evaluate($node['left']), $this->evaluate($node['right'])),
            'or' => $this->or($this->evaluate($node['left']), $this->evaluate($node['right'])),
            'not' => ($value = $this->evaluate($node['expr'])) === null ? null : ! $value,
            'compare' => $this->compare($node['field'], $node['operator'], $node['value']),
            'like' => $this->like($node['field'], $node['pattern'], $node['negated']),
            'in' => $this->in($node['field'], $node['values'], $node['negated']),
            'cidr' => $this->ip === null ? null : IpUtils::checkIp($this->ip, $node['range']),
            'null' => $this->isKnown($node['field']) ? ($this->value($node['field']) === null) !== $node['negated'] : null,
            default => null,
        };
    }

    /**
     * Determine if the field's value is known for a live request.
     */
    private function isKnown(RuleFieldDefinition $field): bool
    {
        return match ($field) {
            RuleField::Method, RuleField::Host, RuleField::Path, RuleField::Category, RuleField::Ip, RuleField::Ja4, RuleField::Ja4Alpn, RuleField::UserAgent => true,
            RuleField::Language, RuleField::Country, RuleField::Region, RuleField::City, RuleField::Asn, RuleField::Network, RuleField::NetworkKind, RuleField::Tag => $this->described,
            RuleField::Status => $this->status !== null,
            default => false,
        };
    }

    /**
     * Get the field's value in the form rules store it (method and category as their numbers).
     */
    private function value(RuleFieldDefinition $field): int|string|null
    {
        return match ($field) {
            RuleField::Method => HttpMethod::fromVerb($this->method)->value,
            RuleField::Host => Str::lower($this->host),
            RuleField::Path => $this->path,
            RuleField::Category => PathCategory::classify($this->path)?->value,
            RuleField::Status => $this->status,
            RuleField::Ip => $this->ip,
            RuleField::Ja4 => $this->ja4,
            RuleField::Ja4Alpn => $this->ja4 === null ? null : substr($this->ja4, 8, 2),
            RuleField::UserAgent => $this->userAgent,
            RuleField::Language => $this->language,
            RuleField::Country => $this->countryCode === null ? null : Str::upper($this->countryCode),
            RuleField::Region => $this->region,
            RuleField::City => $this->city,
            RuleField::Asn => $this->asn,
            RuleField::Network => $this->network,
            RuleField::NetworkKind => $this->asn === null ? null : NetworkKind::forAsn($this->asn)->value,
            RuleField::Tag => $this->tag,
            default => null,
        };
    }

    private function compare(RuleFieldDefinition $field, string $operator, int|string $literal): ?bool
    {
        if (! $this->isKnown($field)) {
            return null;
        }

        $value = $this->value($field);

        if ($value === null) {
            return false;
        }

        try {
            $stored = $field->storedValues($literal);
        } catch (InvalidArgumentException) {
            return false;
        }

        if ($field === RuleField::Category) {
            return in_array($value, $stored, true) === ($operator === '=');
        }

        return match ($operator) {
            '=' => $value == $stored[0],
            '!=' => $value != $stored[0],
            '<' => $value < $stored[0],
            '<=' => $value <= $stored[0],
            '>' => $value > $stored[0],
            '>=' => $value >= $stored[0],
        };
    }

    private function like(RuleFieldDefinition $field, string $pattern, bool $negated): ?bool
    {
        if (! $this->isKnown($field)) {
            return null;
        }

        $value = $this->value($field);

        if ($value === null) {
            return false;
        }

        // A category is stored as its number; a pattern like 'probe/%' names slugs.
        if ($field === RuleField::Category) {
            return in_array($value, $field->categoryValues($pattern, isPattern: true), true) !== $negated;
        }

        $regex = '/^'.str_replace(['%', '_'], ['.*', '.'], preg_quote(Str::lower($pattern), '/')).'$/s';

        return (preg_match($regex, Str::lower((string) $value)) === 1) !== $negated;
    }

    /**
     * @param  list<int|string>  $literals
     */
    private function in(RuleFieldDefinition $field, array $literals, bool $negated): ?bool
    {
        if (! $this->isKnown($field)) {
            return null;
        }

        $value = $this->value($field);

        if ($value === null) {
            return false;
        }

        $stored = [];

        foreach ($literals as $literal) {
            try {
                $stored = [...$stored, ...$field->storedValues($literal)];
            } catch (InvalidArgumentException) {
                //
            }
        }

        return in_array($value, $stored) !== $negated;
    }

    private function and(?bool $left, ?bool $right): ?bool
    {
        return match (true) {
            $left === false, $right === false => false,
            $left === null, $right === null => null,
            default => true,
        };
    }

    private function or(?bool $left, ?bool $right): ?bool
    {
        return match (true) {
            $left === true, $right === true => true,
            $left === null, $right === null => null,
            default => false,
        };
    }
}
