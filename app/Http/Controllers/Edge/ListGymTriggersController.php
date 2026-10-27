<?php

namespace App\Http\Controllers\Edge;

use App\Data\EdgeExpression;
use App\Data\RuleScript;
use App\Enums\DomainStatus;
use App\Enums\HostingProvider;
use App\Enums\NetworkKind;
use App\Enums\PathCategory;
use App\Enums\ProtectionMode;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Verdict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ListGymTriggersController extends Controller
{
    /**
     * What an edge announces in X-NiceTry-Features when it judges single-request rules itself. An edge
     * that doesn't gets the list exactly as before.
     */
    public const string RULES_FEATURE = 'gym-rules';

    /**
     * Tell the edge which requests might belong in the gym: for each enabled routine, the hostnames of
     * its team's enforcing domains, the path globs its entry pins down ("*" when it doesn't) and, when
     * the entry requires a rule verdict, the clients currently holding one (an IP, a TLS fingerprint,
     * or both). The edge asks /caddy/gym-check only about those requests, so every other request is
     * never slowed. A routine whose required verdicts nobody holds sends no trigger at all.
     *
     * Domains in observe mode are left out: they never send anyone to the gym. A domain that serves www.
     * triggers under www. too.
     *
     * An edge that judges single-request rules (RULES_FEATURE) also gets, on each trigger limited to rule
     * verdicts, those of its rules that one request can decide, as EdgeExpressions: it asks about a
     * request that matches one even before the client holds the verdict, so the gym check can reach the
     * verdict there and then. Such a trigger is "limited" (only its clients or rules), and is sent even
     * while nobody holds the verdict. Alongside come what the expressions need: the path categories in
     * the order they're tried, the hosting networks' ASNs, and each domain's other hostnames.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $judgesRules = in_array(self::RULES_FEATURE, array_map('trim', explode(',', (string) $request->header('X-NiceTry-Features'))), true);

        $hostnames = Domain::query()
            ->where('protection_mode', ProtectionMode::Enforce)
            ->where('status', DomainStatus::Active)
            ->orderBy('hostname')
            ->withEdgeHostnames()
            ->get(['team_id', 'hostname', 'preview_hostname', 'origin_id', 'serves_www'])
            ->groupBy('team_id');

        $teamHosts = $hostnames->map(fn (Collection $domains) => $domains->flatMap(fn (Domain $domain) => $domain->edgeHostnames())->unique()->sort()->values()->all());
        $liveRules = $judgesRules ? $this->liveRules($hostnames->keys()->all()) : collect();

        $triggers = GymRoutine::query()
            ->where('enabled', true)
            ->whereIn('team_id', $hostnames->keys())
            ->orderBy('id')
            ->get()
            ->flatMap(function (GymRoutine $routine) use ($teamHosts, $liveRules, $judgesRules) {
                $clients = $this->clients($routine);
                $rules = $judgesRules ? $this->routineRules($routine, $liveRules->get($routine->team_id, collect())) : [];

                if ($clients === [] && $rules === []) {
                    return [];
                }

                return array_map(fn (string $glob) => [
                    'id' => $routine->id,
                    'hosts' => $teamHosts[$routine->team_id],
                    'method' => null,
                    'path' => $glob,
                    'clients' => $clients,
                    ...($judgesRules && $clients !== null ? ['limited' => true, 'rules' => $rules] : []),
                ], $routine->entry()->edgePathGlobs() ?? ['*']);
            })
            ->values()
            ->all();

        if (! $judgesRules) {
            return response()->json(['triggers' => $triggers]);
        }

        return response()->json([
            'triggers' => $triggers,
            'categories' => array_map(fn (PathCategory $category) => ['id' => $category->value, 'patterns' => $category->patterns()], PathCategory::cases()),
            'networks' => [['kind' => NetworkKind::Hosting->value, 'asns' => collect(HostingProvider::cases())->flatMap(fn (HostingProvider $provider) => $provider->asns())->unique()->sort()->values()->all()]],
            'domains' => $hostnames->flatten()->map(fn (Domain $domain) => ['hostname' => $domain->hostname, 'aliases' => $domain->edgeHostnames()])->values()->all(),
        ]);
    }

    /**
     * The enabled rules of the given teams that one request can decide, as EdgeExpressions by rule id,
     * by team.
     *
     * @param  list<int>  $teamIds
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    private function liveRules(array $teamIds): Collection
    {
        return EdgeRule::query()
            ->whereIn('team_id', $teamIds)
            ->where('enabled', true)
            ->orderBy('id')
            ->get(['id', 'team_id', 'source'])
            ->groupBy('team_id')
            ->map(fn (Collection $rules) => $rules
                ->mapWithKeys(function (EdgeRule $rule) {
                    $script = rescue(fn () => RuleScript::parse($rule->source), report: false);

                    return [$rule->id => $script === null ? null : EdgeExpression::forScript($script)];
                })
                ->filter());
    }

    /**
     * The single-request rules whose verdicts let visitors into a routine, as the edge takes them.
     *
     * @param  Collection<int, array<string, mixed>>  $teamRules
     * @return list<array{id: int, expr: array<string, mixed>}>
     */
    private function routineRules(GymRoutine $routine, Collection $teamRules): array
    {
        $ruleIds = $routine->entry()->edgeVerdictRuleIds();

        if ($ruleIds === null) {
            return [];
        }

        return $teamRules
            ->when($ruleIds !== [], fn (Collection $rules) => $rules->only($ruleIds))
            ->map(fn (array $expression, int $id) => ['id' => $id, 'expr' => $expression])
            ->values()
            ->all();
    }

    /**
     * Get the clients a routine's entry is limited to by the verdicts it requires: null when it
     * requires none, and an empty list when it requires verdicts nobody holds.
     *
     * @return list<array{ip: string|null, ja4: string|null}>|null
     */
    private function clients(GymRoutine $routine): ?array
    {
        $ruleIds = $routine->entry()->edgeVerdictRuleIds();

        if ($ruleIds === null) {
            return null;
        }

        // Read as plain rows and keyed by client: a routine can carry tens of thousands of verdicts.
        $clients = [];

        Verdict::query()
            ->active()
            ->where('verdicts.team_id', $routine->team_id)
            ->whereNotNull('verdicts.edge_rule_id')
            ->when($ruleIds !== [], fn ($query) => $query->whereIn('verdicts.edge_rule_id', $ruleIds))
            ->leftJoin('tls_fingerprints', 'tls_fingerprints.id', '=', 'verdicts.ja4_id')
            ->orderBy('verdicts.id')
            ->toBase()
            ->get(['verdicts.client_ip', 'tls_fingerprints.value as ja4'])
            ->each(function (object $row) use (&$clients) {
                $clients[$row->client_ip.'|'.$row->ja4] ??= ['ip' => $row->client_ip, 'ja4' => $row->ja4];
            });

        return array_values($clients);
    }
}
