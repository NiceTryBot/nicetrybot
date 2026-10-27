<?php

namespace App\Data;

use App\Enums\HttpMethod;
use App\Enums\RuleField;
use App\Support\RuleFieldDefinition;
use InvalidArgumentException;

/**
 * A single-request rule's WHERE in the form the edge evaluates (nicetry_gym, in the edge repo): plain
 * fields the edge reads itself, values as a request carries them (a method's verb rather than its
 * number), and path categories by number, which the edge works out from the patterns the trigger feed
 * sends. The edge only uses it to decide whether to ask NiceTryBot; the gym check judges the rule again
 * with LiveRequest, so a slip at the edge costs a round trip or a late verdict, never a wrong one.
 *
 * Nodes, as JSON:
 *
 *     {"k":"and"|"or","l":node,"r":node}    {"k":"not","e":node}    {"k":"true"}    {"k":"false"}
 *     {"k":"cmp","f":field,"op":"="|"!="|"<"|"<="|">"|">=","v":value}
 *     {"k":"like","f":field,"p":pattern,"neg":bool}    {"k":"in","f":field,"vs":[value…],"neg":bool}
 *     {"k":"cidr","range":range}    {"k":"null","f":field,"neg":bool}
 *
 * A field is a RuleField value. Unknown values make a node unknown, as in LiveRequest: NOT of unknown
 * is unknown, AND is false if either side is, OR is true if either side is.
 */
final class EdgeExpression
{
    /**
     * Get the rule's WHERE for the edge, or null when the rule can't be decided from one request.
     *
     * @return array<string, mixed>|null
     */
    public static function forScript(RuleScript $script): ?array
    {
        if (! $script->isLive()) {
            return null;
        }

        return $script->where === null ? ['k' => 'true'] : self::lower($script->where);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function lower(array $node): array
    {
        return match ($node['kind']) {
            'and', 'or' => ['k' => $node['kind'], 'l' => self::lower($node['left']), 'r' => self::lower($node['right'])],
            'not' => ['k' => 'not', 'e' => self::lower($node['expr'])],
            'compare' => self::compare($node['field'], $node['operator'], $node['value']),
            'like' => $node['field'] === RuleField::Category
                ? ['k' => 'in', 'f' => RuleField::Category->value, 'vs' => $node['field']->categoryValues($node['pattern'], isPattern: true), 'neg' => $node['negated']]
                : ['k' => 'like', 'f' => $node['field']->value, 'p' => $node['pattern'], 'neg' => $node['negated']],
            'in' => ['k' => 'in', 'f' => $node['field']->value, 'vs' => self::values($node['field'], $node['values']), 'neg' => $node['negated']],
            'cidr' => ['k' => 'cidr', 'range' => $node['range']],
            'null' => ['k' => 'null', 'f' => $node['field']->value, 'neg' => $node['negated']],
            default => ['k' => 'false'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function compare(RuleFieldDefinition $field, string $operator, int|string $literal): array
    {
        try {
            $stored = $field->storedValues($literal);
        } catch (InvalidArgumentException) {
            return ['k' => 'false'];
        }

        // A category names a family or a slug, so it may stand for several.
        if ($field === RuleField::Category) {
            return ['k' => 'in', 'f' => $field->value, 'vs' => $stored, 'neg' => $operator === '!='];
        }

        return ['k' => 'cmp', 'f' => $field->value, 'op' => $operator, 'v' => self::edgeValue($field, $stored[0])];
    }

    /**
     * @param  list<int|string>  $literals
     * @return list<int|string>
     */
    private static function values(RuleFieldDefinition $field, array $literals): array
    {
        $values = [];

        foreach ($literals as $literal) {
            try {
                foreach ($field->storedValues($literal) as $stored) {
                    $values[] = self::edgeValue($field, $stored);
                }
            } catch (InvalidArgumentException) {
                //
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * Get a stored value as the edge reads the field: a method's verb.
     */
    private static function edgeValue(RuleFieldDefinition $field, int|string $stored): int|string
    {
        return $field === RuleField::Method ? HttpMethod::from((int) $stored)->label() : $stored;
    }
}
