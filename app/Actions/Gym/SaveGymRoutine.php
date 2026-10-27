<?php

namespace App\Actions\Gym;

use App\Data\GymCondition;
use App\Data\GymEntry;
use App\Enums\GymExit;
use App\Gym\Contracts\GymAddon;
use App\Gym\GymAddons;
use App\Gym\ObstacleRegistry;
use App\Models\GymRoutine;
use App\Models\Team;

class SaveGymRoutine
{
    public function __construct(private ObstacleRegistry $registry, private GymAddons $gymAddons)
    {
        //
    }

    /**
     * Save a routine: a new one at the end of the team's gym, or a change to one of its routines.
     * Incomplete condition rows are dropped and every obstacle's config is brought within its settings'
     * bounds. Gym add-ons are handed their setting for the routine when one is given, by add-on key.
     *
     * @param  array{name: string, enabled: bool, match_all: bool, conditions: array<int, array<string, mixed>>, obstacles: list<array{id: string, type: string, config: array<string, mixed>, escalate?: string|null}>, exit_action: GymExit, pass_seconds: int, one_try: bool}  $attributes
     * @param  array<string, mixed>|null  $addons  Each add-on's setting by key, or null to leave them as they are.
     */
    public function handle(Team $team, ?GymRoutine $routine, array $attributes, ?array $addons = null): GymRoutine
    {
        $attributes = [
            'name' => $attributes['name'],
            'enabled' => $attributes['enabled'],
            'match_all' => $attributes['match_all'],
            'conditions' => array_map(fn (GymCondition $condition) => $condition->toArray(), GymEntry::fromArray($attributes['conditions'], $attributes['match_all'])->conditions),
            'obstacles' => $this->normalizeObstacles($attributes['obstacles']),
            'exit_action' => $attributes['exit_action'],
            'pass_seconds' => $attributes['pass_seconds'],
            'one_try' => $attributes['one_try'],
        ];

        if ($routine === null) {
            $routine = $team->gymRoutines()->create([...$attributes, 'position' => (int) $team->gymRoutines()->max('position') + 1]);
        } else {
            $routine->update($attributes);
        }

        if ($addons !== null) {
            foreach ($this->gymAddons->all() as $addon) {
                $addon->save($routine, $addons[$addon->key()] ?? null);
            }
        }

        return $routine;
    }

    /**
     * Each gym add-on's setting for a routine not yet saved.
     *
     * @return array<string, mixed>
     */
    public function defaultAddons(): array
    {
        return collect($this->gymAddons->all())->mapWithKeys(fn (GymAddon $addon) => [$addon->key() => $addon->load(null)])->all();
    }

    /**
     * Get obstacles with each config brought within its settings' bounds, and an escalation kept only
     * when its obstacle is still registered. Entries whose obstacle is no longer registered are kept as
     * they are, so saving never loses them.
     *
     * @param  list<array{id: string, type: string, config: array<string, mixed>, escalate?: string|null}>  $obstacles
     * @return list<array{id: string, type: string, config: array<string, mixed>, escalate: string|null}>
     */
    public function normalizeObstacles(array $obstacles): array
    {
        return array_map(function (array $entry) {
            $obstacle = $this->registry->find($entry['type']);

            return [
                'id' => $entry['id'],
                'type' => $entry['type'],
                'config' => $obstacle?->configure($entry['config']) ?? $entry['config'],
                'escalate' => filled($entry['escalate'] ?? null) && $this->registry->find($entry['escalate']) !== null ? $entry['escalate'] : null,
            ];
        }, $obstacles);
    }
}
