<?php

namespace NiceTry\Tarpit\Rules;

use App\Data\RuleScript;
use App\Enums\RequestAction;
use App\Enums\RuleField;

/**
 * The statuses a rule has its tarpit hold the origin's answer on. A rule whose WHERE reads nothing but
 * the status (status = 404, status IN (403, 404, 429), or any OR of those), optionally ANDed with
 * action = 'passed', is decided by the answer itself, so the edge can hold that very answer before it
 * goes back, rather than wait a minute for the verdict. action = 'passed' changes nothing at the edge,
 * which only ever holds the origin's answers (never the gym's or the Bouncer's own), but it keeps the
 * minute's run from counting those against the client too. Any other rule feeds its tarpit through its
 * verdicts and, when one request decides it, the request it matches.
 */
class StatusHolds
{
    /**
     * The statuses the rule names, or null when it reads anything besides the status, counts over a
     * window, or names no status.
     *
     * @return list<int>|null
     */
    public static function of(RuleScript $script): ?array
    {
        if ($script->isAggregate() || $script->where === null) {
            return null;
        }

        $statuses = self::statuses(self::withoutPassedAction($script->where));

        return $statuses === null || $statuses === [] ? null : array_values(array_unique($statuses));
    }

    /**
     * Drop an `action = 'passed'` ANDed onto the status condition, which asks for the origin's answers:
     * the only ones the edge holds anyway.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function withoutPassedAction(array $node): array
    {
        if ($node['kind'] !== 'and') {
            return $node;
        }

        foreach (['left' => 'right', 'right' => 'left'] as $side => $other) {
            if ($node[$side]['kind'] === 'compare' && $node[$side]['field'] === RuleField::Action && $node[$side]['operator'] === '='
                && $node[$side]['field']->storedValues($node[$side]['value']) === [RequestAction::Passed->value]) {
                return $node[$other];
            }
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<int>|null
     */
    private static function statuses(array $node): ?array
    {
        return match (true) {
            $node['kind'] === 'or' => ($left = self::statuses($node['left'])) === null || ($right = self::statuses($node['right'])) === null
                ? null
                : [...$left, ...$right],
            $node['kind'] === 'compare' && $node['field'] === RuleField::Status && $node['operator'] === '=' && is_int($node['value']) => [$node['value']],
            $node['kind'] === 'in' && $node['field'] === RuleField::Status && ! $node['negated'] => array_values(array_filter($node['values'], 'is_int')),
            default => null,
        };
    }
}
