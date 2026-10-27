<?php

namespace NiceTry\RulePacks\Actions;

use App\Data\GymCondition;
use App\Data\RuleScriptSyntaxError;
use App\Enums\GymExit;
use App\Enums\GymField;
use App\Enums\GymOperator;
use App\Gym\ObstacleRegistry;
use App\Models\GymRoutine;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use NiceTry\RulePacks\Data\PackChanges;
use NiceTry\RulePacks\Models\RulePackInstall;
use NiceTry\RulePacks\RulePack;
use NiceTry\RulePacks\Support\PackFingerprint;

class InstallRulePack
{
    public function __construct(private ObstacleRegistry $obstacles)
    {
        //
    }

    /**
     * Install a pack for a team, or bring an installed one up to date, in observe mode: nothing it adds
     * sends anyone anywhere. Its rules only tag, and start switched on so they do, unless one of the
     * team's routines takes every rule's verdicts, when they start switched off. Its routines start
     * switched off. Items already installed are skipped, or rewritten when the pack has changed them
     * and the team hasn't; one the team has edited is left alone. A rule that doesn't parse (it reads a
     * field of an add-on that isn't installed) is left out, and so is a routine left with none of its
     * rules; one already installed that stops parsing is kept as it is. New rules watch from now on, as a
     * rule saved on the Rules page does.
     */
    public function handle(Team $team, RulePack $pack): PackChanges
    {
        return DB::transaction(function () use ($team, $pack): PackChanges {
            $installs = RulePackInstall::query()
                ->where('team_id', $team->id)
                ->where('pack', $pack->key())
                ->with('rule', 'routine')
                ->lockForUpdate()
                ->get()
                ->keyBy('item');
            $rulesStartOn = $this->routinesTakingEveryVerdict($team)->isEmpty();
            $lastRequestId = (int) DB::table('edge_requests')->max('id');
            $changes = ['added' => [], 'updated' => [], 'kept' => [], 'unavailable' => []];
            $ruleIds = [];
            $addedRules = false;

            foreach ($pack->rules() as $key => $rule) {
                $install = $installs->get($key);

                if ($install?->rule !== null) {
                    $ruleIds[$key] = $install->rule->id;
                }

                try {
                    $script = $pack->script($key);
                } catch (RuleScriptSyntaxError) {
                    if ($install?->rule === null) {
                        $changes['unavailable'][] = $rule['name'];
                    } else {
                        $install->update(['version' => $pack->version()]);
                    }

                    continue;
                }

                $attributes = ['name' => $rule['name'], 'source' => $rule['source'], 'action' => $script->action, 'duration_seconds' => $script->durationSeconds];

                if ($install?->rule !== null) {
                    $this->refresh($install, $attributes, PackFingerprint::ofRule($rule['name'], $rule['source']), $pack, $changes);

                    continue;
                }

                $edgeRule = $team->edgeRules()->create([...$attributes, 'enabled' => $rulesStartOn, 'last_request_id' => $lastRequestId]);
                $ruleIds[$key] = $edgeRule->id;
                $this->record($team, $pack, $key, $install, ['edge_rule_id' => $edgeRule->id, 'fingerprint' => PackFingerprint::of($edgeRule)]);
                $changes['added'][] = $rule['name'];
                $addedRules = true;
            }

            foreach ($pack->routines() as $key => $routine) {
                if (isset($pack->rules()[$key])) {
                    continue;
                }

                $install = $installs->get($key);
                $attributes = $this->routineAttributes($routine, $ruleIds);

                if ($attributes === null) {
                    if ($install?->routine === null) {
                        $changes['unavailable'][] = $routine['name'];
                    } else {
                        $install->update(['version' => $pack->version()]);
                    }

                    continue;
                }

                if ($install?->routine !== null) {
                    $this->refresh($install, $attributes, PackFingerprint::ofRoutine($attributes), $pack, $changes);

                    continue;
                }

                $gymRoutine = $team->gymRoutines()->create([...$attributes, 'enabled' => false, 'position' => (int) $team->gymRoutines()->max('position') + 1]);
                $this->record($team, $pack, $key, $install, ['gym_routine_id' => $gymRoutine->id, 'fingerprint' => PackFingerprint::of($gymRoutine)]);
                $changes['added'][] = $routine['name'];
            }

            return new PackChanges(...$changes, rulesStartOff: $addedRules && ! $rulesStartOn);
        });
    }

    /**
     * The team's switched-on routines that take a verdict from any rule, so a new rule's clients would
     * go through them as soon as it tags one.
     *
     * @return Collection<int, GymRoutine>
     */
    public function routinesTakingEveryVerdict(Team $team): Collection
    {
        return $team->gymRoutines()
            ->where('enabled', true)
            ->get()
            ->filter(fn (GymRoutine $routine): bool => collect($routine->entry()->conditions)->contains(fn (GymCondition $condition): bool => $condition->field === GymField::Verdict
                && $condition->operator === GymOperator::In
                && in_array(GymField::ANY_RULE, $condition->values, true)))
            ->values();
    }

    /**
     * Bring an installed item up to date: rewrite it when the pack has changed it and the team hasn't,
     * keep it when the team has. Either way it's recorded at the pack's version.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array{added: list<string>, updated: list<string>, kept: list<string>, unavailable: list<string>}  $changes
     */
    private function refresh(RulePackInstall $install, array $attributes, string $fingerprint, RulePack $pack, array &$changes): void
    {
        $item = $install->rule ?? $install->routine;

        if ($install->fingerprint !== $fingerprint) {
            if ($install->isEdited()) {
                $changes['kept'][] = $item->name;
            } else {
                $item->update($attributes);
                $install->fingerprint = PackFingerprint::of($item);
                $changes['updated'][] = $item->name;
            }
        }

        $install->update(['version' => $pack->version()]);
    }

    /**
     * Record that the pack installed an item, replacing a row whose item is gone.
     *
     * @param  array{edge_rule_id?: int, gym_routine_id?: int, fingerprint: string}  $item
     */
    private function record(Team $team, RulePack $pack, string $key, ?RulePackInstall $stale, array $item): void
    {
        $stale?->delete();

        RulePackInstall::query()->create([
            'team_id' => $team->id,
            'pack' => $pack->key(),
            'item' => $key,
            'version' => $pack->version(),
            ...$item,
        ]);
    }

    /**
     * A pack routine as the routine's attributes: entered by the verdicts of those of its rules the team
     * has (joined to its own conditions), each obstacle with a fresh id and its settings as the editor
     * saves them. Null when it names rules and the team has none of them.
     *
     * @param  array{rules: list<string>, match_all: bool, conditions: list<array{field: string, operator: string, values: list<string>}>, obstacles: list<array{type: string, config: array<string, mixed>, escalate: string|null}>, name: string, exit: GymExit, pass_seconds: int}  $routine
     * @param  array<string, int>  $ruleIds
     * @return array{name: string, match_all: bool, conditions: list<array<string, mixed>>, obstacles: list<array<string, mixed>>, exit_action: GymExit, pass_seconds: int, one_try: bool}|null
     */
    private function routineAttributes(array $routine, array $ruleIds): ?array
    {
        $verdictRuleIds = array_values(array_map(fn (string $rule): string => (string) $ruleIds[$rule], array_filter($routine['rules'], fn (string $rule): bool => isset($ruleIds[$rule]))));

        if ($routine['rules'] !== [] && $verdictRuleIds === []) {
            return null;
        }

        return [
            'name' => $routine['name'],
            'match_all' => $routine['match_all'],
            'conditions' => [
                ...($verdictRuleIds === [] ? [] : [['field' => GymField::Verdict->value, 'operator' => GymOperator::In->value, 'values' => $verdictRuleIds]]),
                ...$routine['conditions'],
            ],
            'obstacles' => array_map(fn (array $obstacle): array => [
                'id' => Str::lower(Str::random(10)),
                'type' => $obstacle['type'],
                'config' => $this->obstacles->find($obstacle['type'])?->configure($obstacle['config']) ?? $obstacle['config'],
                'escalate' => $obstacle['escalate'],
            ], $routine['obstacles']),
            'exit_action' => $routine['exit'],
            'pass_seconds' => $routine['pass_seconds'],
            'one_try' => false,
        ];
    }
}
