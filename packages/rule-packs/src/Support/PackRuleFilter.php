<?php

namespace NiceTry\RulePacks\Support;

use App\Enums\GymExit;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use App\Support\RuleFilter;
use App\Support\RuleFilterCandidate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use NiceTry\RulePacks\Actions\InstallRulePack;
use NiceTry\RulePacks\Models\RulePackInstall;
use NiceTry\RulePacks\RulePack;
use NiceTry\RulePacks\RulePacks;

/**
 * The Rules page's "Rule pack" dropdown: every pack on offer, each with how many of its rules the team
 * has installed. Picking one shows the rules it installed, and the ones it hasn't as cards to install
 * one at a time, each in the column the pack's routine for it acts in. A rule counts as installed while
 * rule_pack_installs says it came from the pack, however the team has edited it since; deleting it makes
 * it a candidate again.
 *
 * Installing one rule here is a choice to act on its clients, unlike installing a whole pack: a routine
 * it brings starts switched on, after the page asks. A routine the team already has keeps its switch,
 * and the card says where it stands, unless none of the rules it took are left: then it's switched on
 * like a new one.
 */
class PackRuleFilter implements RuleFilter
{
    public function key(): string
    {
        return 'pack';
    }

    public function label(): string
    {
        return __('Rule pack');
    }

    public function options(Team $team): array
    {
        $installed = $this->installedItems($team);

        return array_map(fn (RulePack $pack) => __(':name (:installed/:total installed)', [
            'name' => __($pack->name()),
            'installed' => count(array_intersect($installed[$pack->key()] ?? [], array_keys($this->offered($pack)))),
            'total' => count($this->offered($pack)),
        ]), app(RulePacks::class)->all());
    }

    public function apply(Builder $rules, Team $team, string $value): void
    {
        $rules->whereIn($rules->qualifyColumn('id'), RulePackInstall::query()
            ->where('team_id', $team->id)
            ->where('pack', $value)
            ->whereNotNull('edge_rule_id')
            ->select('edge_rule_id'));
    }

    public function labels(Team $team, array $ruleIds): array
    {
        $packs = app(RulePacks::class)->all();

        return RulePackInstall::query()
            ->where('team_id', $team->id)
            ->whereIn('edge_rule_id', $ruleIds)
            ->get(['pack', 'edge_rule_id'])
            ->filter(fn (RulePackInstall $install) => isset($packs[$install->pack]))
            ->mapWithKeys(fn (RulePackInstall $install) => [$install->edge_rule_id => ['value' => $install->pack, 'label' => __($packs[$install->pack]->name())]])
            ->all();
    }

    public function candidates(Team $team, string $value): array
    {
        $pack = app(RulePacks::class)->find($value);
        $installed = $this->installedItems($team)[$value] ?? [];
        $routines = RulePackInstall::query()
            ->where('team_id', $team->id)
            ->where('pack', $value)
            ->whereNotNull('gym_routine_id')
            ->with('routine')
            ->get()
            ->keyBy('item');
        $candidates = [];

        foreach ($pack?->rules() ?? [] as $key => $rule) {
            if (in_array($key, $installed, true)) {
                continue;
            }

            [$column, $destination, $confirm] = [null, null, null];

            // A rule that names where its clients go (BOUNCER, TARPIT…) acts there itself, in the board
            // column of that name.
            $named = rescue(fn () => array_key_first($pack->script($key)->destinations), report: false);

            if (is_string($named) && $named !== 'GYM') {
                $column = strtolower($named);
                $destination = Str::title(strtolower($named));
                $confirm = __('Install “:rule” switched on? Clients it matches on your enforcing sites go to the :destination straight away.', ['rule' => __($rule['name']), 'destination' => $destination]);
            }

            foreach ($column === null ? $pack->routines() : [] as $item => $routine) {
                if (isset($pack->rules()[$item]) || ! in_array($key, $routine['rules'], true)) {
                    continue;
                }

                // A routine left behind by rules the team has since deleted was only waiting for them.
                $existing = array_intersect($routine['rules'], $installed) === [] ? null : $routines->get($item)?->routine;
                $column = $existing?->enabled === false ? null : ($routine['exit'] === GymExit::Continue ? 'gym' : 'blocked');
                $destination = $existing?->enabled === false ? __(':name · off', ['name' => $existing->name]) : __($routine['name']);
                $confirm = $existing === null
                    ? __('Install “:rule” and switch on “:routine”? Clients it tags on your enforcing sites go through the routine straight away.', ['rule' => __($rule['name']), 'routine' => __($routine['name'])])
                    : null;

                break;
            }

            $candidates[] = new RuleFilterCandidate($key, __($rule['name']), __($rule['blurb']), $rule['source'], $pack->unavailableBecause($key), $column, $destination, $confirm);
        }

        return $candidates;
    }

    public function add(Team $team, string $value, string $candidate): array
    {
        // A rule can bring the pack's routine with it, switched on: the card said where its clients go.
        Gate::authorize('create', [EdgeRule::class, $team]);
        Gate::authorize('create', [GymRoutine::class, $team]);

        return app(InstallRulePack::class)->handle($team, app(RulePacks::class)->find($value), [$candidate], routinesStartOn: true)->added;
    }

    /**
     * The pack's rules that can be installed now: those whose fields are all here.
     *
     * @return array<string, array{name: string, blurb: string, source: string}>
     */
    private function offered(RulePack $pack): array
    {
        return array_filter($pack->rules(), fn (string $key) => $pack->unavailableBecause($key) === null, ARRAY_FILTER_USE_KEY);
    }

    /**
     * The rules each pack has installed for the team that it still has, by pack key.
     *
     * @return array<string, list<string>>
     */
    private function installedItems(Team $team): array
    {
        return RulePackInstall::query()
            ->where('team_id', $team->id)
            ->whereNotNull('edge_rule_id')
            ->get(['pack', 'item'])
            ->groupBy('pack')
            ->map(fn ($installs) => $installs->pluck('item')->all())
            ->all();
    }
}
