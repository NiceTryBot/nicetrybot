<?php

namespace NiceTry\Transfer\Actions;

use App\Enums\GymField;
use App\Models\Domain;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use NiceTry\Origins\Models\DomainProtectionOverride;
use NiceTry\Origins\Models\Origin;
use NiceTry\Origins\Models\OriginZone;
use NiceTry\Transfer\TransferBundle;

class ExportOrigin
{
    /**
     * The origin as a transfer bundle: its settings and secret, its groups of sites, each site's
     * hostname (and its own protection mode, if it keeps one), and its team's rules and gym routines.
     * Ids don't travel: sites go by hostname, rules by name (a routine's "has a verdict from" a rule
     * names the rule), obstacles by their keys. Nothing else about the team or its people goes in.
     *
     * @return array<string, mixed>
     */
    public function handle(Origin $origin): array
    {
        $team = $origin->team;
        $rules = $team->edgeRules()->orderBy('id')->get(['id', 'name', 'source', 'enabled']);
        $ruleNames = $rules->mapWithKeys(fn (EdgeRule $rule) => [$rule->id => $rule->name])->all();
        $sites = $origin->domains()->orderBy('hostname')->get(['id', 'hostname']);
        $ownModes = DomainProtectionOverride::query()->whereIn('domain_id', $sites->modelKeys())->get()
            ->mapWithKeys(fn (DomainProtectionOverride $override) => [$override->domain_id => $override->protection_mode->value])
            ->all();

        return [
            'format' => TransferBundle::FORMAT,
            'version' => TransferBundle::VERSION,
            'exported_at' => now()->toIso8601String(),
            'source' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
            'origin' => [
                'name' => $origin->name,
                'origin_url' => $origin->origin_url,
                'host_header_mode' => $origin->host_header_mode->value,
                'origin_host_header' => $origin->origin_host_header,
                'forwarded_host_header' => $origin->forwarded_host_header,
                'origin_timeout_seconds' => $origin->origin_timeout_seconds,
                'server_header' => $origin->server_header,
                'hsts_policy' => $origin->hsts_policy->value,
                'cache_assets' => $origin->cache_assets,
                'fold_www' => $origin->fold_www,
                'protection_mode' => $origin->protection_mode->value,
                'origin_secret' => $origin->origin_secret,
                'authorize_url' => $origin->authorize_url,
                'zones' => $origin->zones()->orderBy('suffix')->get()
                    ->map(fn (OriginZone $zone) => ['suffix' => $zone->suffix, 'serves_www' => $zone->serves_www])
                    ->all(),
                'sites' => $sites->map(fn (Domain $site) => ['hostname' => $site->hostname, 'protection_mode' => $ownModes[$site->id] ?? null])->values()->all(),
            ],
            'rules' => $rules->map(fn (EdgeRule $rule) => ['name' => $rule->name, 'source' => $rule->source, 'enabled' => $rule->enabled])->all(),
            'routines' => $this->routines($team, $ruleNames),
        ];
    }

    /**
     * The team's routines in gym order, a verdict condition's rule ids swapped for "rule:<name>".
     *
     * @param  array<int, string>  $ruleNames
     * @return list<array<string, mixed>>
     */
    private function routines(Team $team, array $ruleNames): array
    {
        return array_values($team->gymRoutines()->orderBy('position')->orderBy('id')->get()
            ->map(fn (GymRoutine $routine) => [
                'name' => $routine->name,
                'enabled' => $routine->enabled,
                'match_all' => $routine->match_all,
                // A rule that's gone becomes "rule:" with no name, which never matches one here: the
                // routine is then left out on import, rather than its condition dropped (letting everyone in).
                'conditions' => array_map(fn (array $condition) => [
                    'field' => (string) $condition['field'],
                    'operator' => (string) $condition['operator'],
                    'values' => $condition['field'] === GymField::Verdict->value
                        ? array_map(fn (string $value) => $value === GymField::ANY_RULE ? $value : TransferBundle::RULE_PREFIX.($ruleNames[(int) $value] ?? ''), array_map(strval(...), $condition['values']))
                        : array_map(strval(...), $condition['values']),
                ], $routine->conditions),
                'obstacles' => array_map(fn (array $obstacle) => [
                    'id' => (string) $obstacle['id'],
                    'type' => (string) $obstacle['type'],
                    'config' => (array) $obstacle['config'],
                    'escalate' => $obstacle['escalate'] ?? null,
                ], $routine->obstacles),
                'exit_action' => $routine->exit_action->value,
                'pass_seconds' => $routine->pass_seconds,
                'one_try' => (bool) $routine->one_try,
            ])
            ->all());
    }
}
