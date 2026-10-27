<?php

namespace NiceTry\RulePacks\Support;

use App\Data\GymCondition;
use App\Data\GymEntry;
use App\Enums\GymExit;
use App\Gym\ObstacleRegistry;
use App\Models\EdgeRule;
use App\Models\GymRoutine;

/**
 * What a pack's rule or routine looks like, as a short hash: its name and what it does, but not
 * whether it's switched on, nor the ids the gym gives each obstacle. Conditions and obstacle settings
 * are read the way the routine editor saves them, so saving a routine without changing it keeps its
 * fingerprint.
 */
final class PackFingerprint
{
    public static function of(EdgeRule|GymRoutine $item): string
    {
        return $item instanceof EdgeRule
            ? self::ofRule($item->name, $item->source)
            : self::ofRoutine([
                'name' => $item->name,
                'match_all' => $item->match_all,
                'conditions' => $item->conditions,
                'obstacles' => $item->obstacles,
                'exit_action' => $item->exit_action,
                'pass_seconds' => $item->pass_seconds,
                'one_try' => (bool) $item->one_try,
            ]);
    }

    public static function ofRule(string $name, string $source): string
    {
        return md5((string) json_encode([$name, str_replace("\r\n", "\n", $source)]));
    }

    /**
     * @param  array{name: string, match_all: bool, conditions: list<array<string, mixed>>, obstacles: list<array<string, mixed>>, exit_action: GymExit, pass_seconds: int, one_try?: bool}  $routine
     */
    public static function ofRoutine(array $routine): string
    {
        $registry = app(ObstacleRegistry::class);

        return md5((string) json_encode([
            $routine['name'],
            (bool) $routine['match_all'],
            array_map(fn (GymCondition $condition): array => $condition->toArray(), GymEntry::fromArray($routine['conditions'])->conditions),
            array_map(fn (array $obstacle): array => [
                (string) $obstacle['type'],
                $registry->find((string) $obstacle['type'])?->configure((array) ($obstacle['config'] ?? [])) ?? (array) ($obstacle['config'] ?? []),
                filled($obstacle['escalate'] ?? null) && $registry->find((string) $obstacle['escalate']) !== null ? $obstacle['escalate'] : null,
            ], $routine['obstacles']),
            $routine['exit_action']->value,
            (int) $routine['pass_seconds'],
            (bool) ($routine['one_try'] ?? false),
        ]));
    }
}
