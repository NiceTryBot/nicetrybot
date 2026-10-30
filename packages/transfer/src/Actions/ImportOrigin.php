<?php

namespace NiceTry\Transfer\Actions;

use App\Actions\Gym\SaveGymRoutine;
use App\Actions\Rules\SaveEdgeRule;
use App\Data\GymCondition;
use App\Data\GymEntry;
use App\Data\RuleScript;
use App\Data\RuleScriptSyntaxError;
use App\Enums\DomainStatus;
use App\Enums\GymExit;
use App\Enums\GymField;
use App\Enums\HostHeaderMode;
use App\Enums\HstsPolicy;
use App\Enums\ProtectionMode;
use App\Gym\ObstacleRegistry;
use App\Models\Domain;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use App\Rules\ReachableOrigin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use NiceTry\Origins\Models\DomainProtectionOverride;
use NiceTry\Origins\Models\Origin;
use NiceTry\Transfer\ImportSummary;
use NiceTry\Transfer\InvalidTransferBundle;
use NiceTry\Transfer\OriginsAddon;
use NiceTry\Transfer\TransferBundle;

class ImportOrigin
{
    public function __construct(
        private SaveEdgeRule $saveRule,
        private SaveGymRoutine $saveRoutine,
        private ObstacleRegistry $obstacles,
    ) {
        //
    }

    /**
     * Bring a validated transfer bundle into the team: its origin (created, or updated when the team
     * already has one at that URL), the origin's groups and sites, and the team's rules and routines,
     * matched by name. Run again, it updates and never duplicates. All of it happens or none of it.
     *
     * A hostname another team here already has stays theirs, and one this team has outside the origin
     * is left as it is; both are reported. Sites behind an origin don't count toward the domain limit,
     * so the limit never stops an import. Nothing in the bundle is assigned without being named here.
     *
     * @param  array<string, mixed>  $bundle  As TransferBundle::validate() returned it.
     *
     * @throws InvalidTransferBundle when the Origins add-on isn't here and on.
     */
    public function handle(Team $team, array $bundle): ImportSummary
    {
        if (! OriginsAddon::isOn()) {
            throw new InvalidTransferBundle(__('A transfer brings an origin, so it needs the Origins add-on on this panel, installed and on.'));
        }

        $originUrl = Validator::make(['origin_url' => $bundle['origin']['origin_url'] ?? ''], ['origin_url' => [new ReachableOrigin]]);

        if ($originUrl->fails()) {
            throw new InvalidTransferBundle($originUrl->errors()->first('origin_url'));
        }

        $summary = new ImportSummary;
        $summary->source = $bundle['source'] ?? null;

        DB::transaction(function () use ($team, $bundle, $summary) {
            $origin = $this->origin($team, $bundle['origin'], $summary);
            $this->zones($origin, $bundle['origin']['zones']);
            $this->sites($team, $origin, $bundle['origin']['sites'], $summary);
            $origin->syncDomains();

            $ruleIds = $this->rules($team, $bundle['rules'], $summary);
            $this->routines($team, $bundle['routines'], $ruleIds, $summary);
        });

        return $summary;
    }

    /**
     * The origin: the team's origin at the bundle's URL (by name when it has several there), or a new one.
     *
     * @param  array<string, mixed>  $data
     */
    private function origin(Team $team, array $data, ImportSummary $summary): Origin
    {
        $url = rtrim((string) $data['origin_url'], '/');
        $atUrl = Origin::query()->where('team_id', $team->id)->where('origin_url', $url)->orderBy('id')->get();
        $origin = $atUrl->firstWhere('name', $data['name']) ?? ($atUrl->count() === 1 ? $atUrl->first() : null);

        $attributes = [
            'name' => $data['name'],
            'origin_url' => $url,
            'host_header_mode' => HostHeaderMode::from($data['host_header_mode']),
            'origin_host_header' => $data['origin_host_header'] ?? null,
            'forwarded_host_header' => $data['forwarded_host_header'] ?? null,
            'origin_timeout_seconds' => (int) $data['origin_timeout_seconds'],
            'server_header' => $data['server_header'] ?? null,
            'hsts_policy' => HstsPolicy::from($data['hsts_policy']),
            'cache_assets' => (bool) $data['cache_assets'],
            'fold_www' => (bool) $data['fold_www'],
            'protection_mode' => ProtectionMode::from($data['protection_mode']),
            'origin_secret' => $data['origin_secret'],
            // Vouching for any hostname is the installation's call: only an administrator brings one in.
            'authorize_url' => $this->byAdministrator() ? ($data['authorize_url'] ?? null) : $origin?->authorize_url,
        ];

        if ($origin === null) {
            $origin = new Origin($attributes);
            $origin->team()->associate($team)->save();
            $summary->origin = 'created';
        } else {
            $origin->fill($attributes);
            $summary->origin = $origin->isDirty() ? 'updated' : 'unchanged';
            $origin->save();
        }

        $summary->originId = $origin->id;
        $summary->originName = $origin->name;

        return $origin;
    }

    /**
     * The origin's groups, made to match the bundle's: a group the bundle doesn't list follows the origin.
     *
     * @param  list<array{suffix: string, serves_www: bool}>  $zones
     */
    private function zones(Origin $origin, array $zones): void
    {
        $suffixes = array_column($zones, 'suffix');

        $origin->zones()->whereNotIn('suffix', $suffixes)->delete();

        foreach ($zones as $zone) {
            $origin->zones()->updateOrCreate(['suffix' => $zone['suffix']], ['serves_www' => (bool) $zone['serves_www']]);
        }
    }

    /**
     * The origin's sites, by hostname: new ones are live straight away, as sites an origin vouched for
     * are, and a site's own protection mode comes across with it. Sites of the origin the bundle doesn't
     * list are left as they are.
     *
     * @param  list<array{hostname: string, protection_mode: string|null}>  $sites
     */
    private function sites(Team $team, Origin $origin, array $sites, ImportSummary $summary): void
    {
        $existing = Domain::query()
            ->whereIn('hostname', array_column($sites, 'hostname'))
            ->get(['id', 'team_id', 'origin_id', 'hostname', 'protection_mode'])
            ->keyBy('hostname');

        foreach ($sites as $site) {
            $hostname = $site['hostname'];
            $ownMode = $site['protection_mode'] !== null ? ProtectionMode::from($site['protection_mode']) : null;
            $domain = $existing->get($hostname);

            if ($domain !== null && $domain->team_id !== $team->id) {
                $summary->sitesTaken[] = $hostname;

                continue;
            }

            if ($domain !== null && $domain->origin_id !== $origin->id) {
                $summary->sitesElsewhere[] = $hostname;

                continue;
            }

            if ($domain === null) {
                $domain = $team->domains()->create([
                    ...$origin->domainSettings(),
                    'origin_id' => $origin->id,
                    'hostname' => $hostname,
                    'status' => $this->byAdministrator() ? DomainStatus::Active : DomainStatus::PendingVerification,
                    'verification_token' => Str::lower(Str::random(32)),
                    'verified_at' => $this->byAdministrator() ? now() : null,
                ]);
                $summary->sitesAdded++;
                $this->ownMode($domain, $ownMode);

                continue;
            }

            $this->ownMode($domain, $ownMode) ? $summary->sitesUpdated++ : $summary->sitesUnchanged++;
        }
    }

    /**
     * Keep or drop a site's own protection mode, returning whether anything changed. Without one, the
     * site follows its origin's (syncDomains() puts it back).
     */
    private function ownMode(Domain $domain, ?ProtectionMode $mode): bool
    {
        $override = DomainProtectionOverride::query()->find($domain->id);

        if ($mode === null) {
            if ($override === null) {
                return false;
            }

            $override->delete();

            return true;
        }

        if ($override?->protection_mode === $mode && $domain->protection_mode === $mode) {
            return false;
        }

        DomainProtectionOverride::query()->updateOrCreate(['domain_id' => $domain->id], ['protection_mode' => $mode]);
        $domain->forceFill(['protection_mode' => $mode])->save();

        return true;
    }

    /**
     * The team's rules, by name, through the same save the Rules page uses. A rule whose script doesn't
     * parse here (it tests a field from an add-on this panel hasn't got) can't come across.
     *
     * @param  list<array{name: string, source: string, enabled: bool}>  $rules
     * @return array<string, int> the team's rule ids by name, for routines' verdict conditions
     */
    private function rules(Team $team, array $rules, ImportSummary $summary): array
    {
        $existing = $team->edgeRules()->get()->keyBy('name');

        foreach ($rules as $data) {
            try {
                $script = RuleScript::parse($data['source']);
            } catch (RuleScriptSyntaxError) {
                $summary->rules['unavailable'][] = $data['name'];

                continue;
            }

            /** @var EdgeRule|null $rule */
            $rule = $existing->get($data['name']);

            if ($rule !== null && $rule->source === $data['source'] && $rule->enabled === (bool) $data['enabled']) {
                $summary->rules['unchanged'][] = $data['name'];

                continue;
            }

            $saved = $this->saveRule->handle($team, $rule, ['name' => $data['name'], 'source' => $data['source'], 'enabled' => (bool) $data['enabled']], $script);
            $existing->put($data['name'], $saved);
            $summary->rules[$rule === null ? 'added' : 'updated'][] = $data['name'];
        }

        return $existing->map(fn (EdgeRule $rule) => $rule->id)->all();
    }

    /**
     * The team's routines, by name, through the same save the gym editor uses. A routine that waits for
     * a verdict from a rule that didn't come across is left out rather than let everyone in. Obstacles
     * from an add-on this panel hasn't got are kept (the gym skips them) and reported.
     *
     * @param  list<array<string, mixed>>  $routines
     * @param  array<string, int>  $ruleIds
     */
    private function routines(Team $team, array $routines, array $ruleIds, ImportSummary $summary): void
    {
        $existing = $team->gymRoutines()->get()->keyBy('name');

        foreach ($routines as $data) {
            $conditions = $this->conditions($data['conditions'], $ruleIds);

            if ($conditions === null) {
                $summary->routines['skipped'][] = $data['name'];

                continue;
            }

            foreach ($data['obstacles'] as $obstacle) {
                foreach (array_filter([$obstacle['type'], $obstacle['escalate'] ?? null]) as $key) {
                    if ($this->obstacles->find($key) === null && ! in_array($key, $summary->missingObstacles, true)) {
                        $summary->missingObstacles[] = $key;
                    }
                }
            }

            // The bundle was validated as strings and plain values; these shape it for the save.
            $obstacles = [];

            foreach ($data['obstacles'] as $obstacle) {
                $obstacles[] = [
                    'id' => (string) $obstacle['id'],
                    'type' => (string) $obstacle['type'],
                    'config' => (array) $obstacle['config'],
                    'escalate' => isset($obstacle['escalate']) ? (string) $obstacle['escalate'] : null,
                ];
            }

            $attributes = [
                'name' => (string) $data['name'],
                'enabled' => (bool) $data['enabled'],
                'match_all' => (bool) $data['match_all'],
                'conditions' => $conditions,
                'obstacles' => $obstacles,
                'exit_action' => GymExit::from($data['exit_action']),
                'pass_seconds' => (int) $data['pass_seconds'],
                'one_try' => (bool) $data['one_try'],
            ];

            /** @var GymRoutine|null $routine */
            $routine = $existing->get($data['name']);

            if ($routine !== null && $this->sameRoutine($routine, $attributes)) {
                $summary->routines['unchanged'][] = $data['name'];

                continue;
            }

            $this->saveRoutine->handle($team, $routine, $attributes);
            $summary->routines[$routine === null ? 'added' : 'updated'][] = $data['name'];
        }
    }

    /**
     * A routine's conditions with each verdict condition's "rule:<name>" back to this team's rule ids,
     * or null when a rule it names isn't here.
     *
     * @param  list<array{field: string, operator: string, values: list<string>}>  $conditions
     * @param  array<string, int>  $ruleIds
     * @return list<array{field: string, operator: string, values: list<string>}>|null
     */
    private function conditions(array $conditions, array $ruleIds): ?array
    {
        $mapped = [];

        foreach ($conditions as $condition) {
            if ($condition['field'] === GymField::Verdict->value) {
                $values = [];

                foreach ($condition['values'] as $value) {
                    if ($value === GymField::ANY_RULE) {
                        $values[] = $value;
                    } elseif (str_starts_with($value, TransferBundle::RULE_PREFIX) && isset($ruleIds[substr($value, strlen(TransferBundle::RULE_PREFIX))])) {
                        $values[] = (string) $ruleIds[substr($value, strlen(TransferBundle::RULE_PREFIX))];
                    } else {
                        return null;
                    }
                }

                $condition['values'] = $values;
            }

            $mapped[] = ['field' => $condition['field'], 'operator' => $condition['operator'], 'values' => $condition['values']];
        }

        return $mapped;
    }

    /**
     * Whether saving these attributes would leave the routine as it is, comparing them as the save
     * would store them.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function sameRoutine(GymRoutine $routine, array $attributes): bool
    {
        $conditions = array_map(fn (GymCondition $condition) => $condition->toArray(), GymEntry::fromArray($attributes['conditions'], $attributes['match_all'])->conditions);

        return $routine->enabled === $attributes['enabled']
            && $routine->match_all === $attributes['match_all']
            && $routine->exit_action === $attributes['exit_action']
            && $routine->pass_seconds === $attributes['pass_seconds']
            && (bool) $routine->one_try === $attributes['one_try']
            && json_encode($routine->conditions) === json_encode($conditions)
            && json_encode($routine->obstacles) === json_encode($this->saveRoutine->normalizeObstacles($attributes['obstacles']));
    }

    /**
     * Whether an installation administrator is importing: only they bring sites in verified (the bundle
     * says another panel verified them, which this one can't check) and an authorize URL with them.
     * Anyone else's new sites prove ownership with their TXT record, like any added domain.
     */
    private function byAdministrator(): bool
    {
        return Auth::user()?->can('administer') === true;
    }
}
