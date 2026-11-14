<?php

namespace App\Actions\Rules;

use App\Enums\RuleSubject;
use App\Enums\TlsFingerprintType;
use App\Models\EdgeRule;
use App\Models\ScopeVerdict;
use App\Models\TlsFingerprint;
use App\Models\Verdict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EvaluateRule
{
    /**
     * How far past its last request a single-request rule looks in one run, in request ids. A rule that
     * has fallen behind catches up over several runs, saving its place after each, as one run over a big
     * backlog could outlast the process and never save it.
     */
    public const int MOST_REQUESTS_PER_RUN = 50000;

    /**
     * How many clients a single-request rule records in one run, at about 20ms each. A run covering
     * more covers fewer requests instead.
     */
    public const int MOST_CLIENTS_PER_RUN = 1500;

    public function __construct(private BuildRuleQuery $buildRuleQuery)
    {
        //
    }

    /**
     * Run a rule over the requests imported since it last ran and record a verdict for each subject it
     * matches. A HAVING rule counts each subject's requests over its window, and acts only when the
     * window holds something new. Returns how many new verdicts were reached.
     *
     * A rule starts from the moment it's first evaluated: requests from before it existed are left to
     * the backtest, so a new rule never bans retroactively. Afterwards, a rule with UNTIL lifts the
     * verdict of any subject that made a matching request after its verdict last matched.
     *
     * A verdict runs from the match that earned it, so a rule catching up doesn't start a fresh ban on a
     * client for what it did days ago, and spends nothing on requests whose ban would already be over.
     */
    public function handle(EdgeRule $rule, ?CarbonImmutable $now = null, int $mostClients = self::MOST_CLIENTS_PER_RUN): int
    {
        $now ??= CarbonImmutable::now();
        $script = $rule->script();
        $latestId = (int) DB::table('edge_requests')->max('id');
        $since = $rule->last_request_id ?? $latestId;

        if ($latestId <= $since) {
            $rule->update(['last_request_id' => $latestId, 'last_evaluated_at' => $now]);

            return 0;
        }

        if (! $script->isAggregate()) {
            $latestId = min($latestId, $since + self::MOST_REQUESTS_PER_RUN);
        }

        $subjectColumns = array_map(fn (RuleSubject $subject) => $subject->column(), $script->subjects);

        $matches = function (int $upTo) use ($rule, $script, $subjectColumns, $since, $now): Collection {
            $query = $this->buildRuleQuery->handle($rule->team, $script);

            if ($script->isAggregate()) {
                [$having, $bindings] = $this->buildRuleQuery->having($script->having);

                $query->where('edge_requests.occurred_at', '>=', $now->subSeconds($script->windowSeconds))
                    ->havingRaw($having, $bindings)
                    ->havingRaw('max(edge_requests.id) > ?', [$since]);
            } else {
                $query->where('edge_requests.id', '>', $since)
                    ->where('edge_requests.id', '<=', $upTo)
                    ->when($rule->duration_seconds !== null, fn ($query) => $query->where('edge_requests.occurred_at', '>', $now->subSeconds($rule->duration_seconds)));
            }

            return $query
                ->selectRaw(implode(', ', [
                    in_array(RuleSubject::Ip, $script->subjects, true) ? 'edge_requests.client_ip as client_ip' : 'null as client_ip',
                    in_array(RuleSubject::Ja4, $script->subjects, true) ? 'edge_requests.ja4_id as ja4_id' : 'null as ja4_id',
                    in_array(RuleSubject::Host, $script->subjects, true) ? 'edge_requests.domain_id as domain_id' : 'null as domain_id',
                    in_array(RuleSubject::Path, $script->subjects, true) ? 'edge_requests.path_id as path_id' : 'null as path_id',
                    'count(*) as matched',
                    'min(edge_requests.occurred_at) as first_matched_at',
                    'max(edge_requests.occurred_at) as last_matched_at',
                ]))
                ->groupBy($subjectColumns)
                ->get();
        };

        $groups = $matches($latestId);

        while (! $script->isAggregate() && $groups->count() > $mostClients && $latestId > $since + 1) {
            $latestId = $since + (int) ceil(($latestId - $since) / 2);
            $groups = $matches($latestId);
        }

        $reached = 0;

        foreach ($groups as $group) {
            $reached += ($script->isScoped()
                ? $this->recordScope($rule, $group, $script->isAggregate(), $now)
                : $this->record($rule, $group, $script->isAggregate(), $now)) ? 1 : 0;
        }

        $this->liftUntil($rule, $since, $latestId, $now);

        $rule->update(['last_request_id' => $latestId, 'last_evaluated_at' => $now]);

        return $reached;
    }

    /**
     * Reach a single-request rule's verdict on a client from one live request it matched, as the gym
     * check does when the edge flags the request: the same verdict the minute's evaluation would reach
     * once the request is imported, which then extends it instead of reaching another. Returns whether
     * the verdict is new.
     */
    public function reachLive(EdgeRule $rule, ?string $ip, ?string $ja4, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $subjects = $rule->script()->subjects;

        if ($rule->script()->isScoped()) {
            return false;
        }

        return $this->record($rule, (object) [
            'client_ip' => in_array(RuleSubject::Ip, $subjects, true) ? $ip : null,
            'ja4_id' => in_array(RuleSubject::Ja4, $subjects, true) && $ja4 !== null ? TlsFingerprint::idFor(TlsFingerprintType::Ja4, $ja4) : null,
            'matched' => 1,
            'first_matched_at' => $now,
            'last_matched_at' => $now,
        ], isAggregate: false, now: $now);
    }

    /**
     * Lift the rule's verdicts whose subject made a request passing UNTIL, among the requests this run
     * covers, at or after the verdict's last match (so the request that lifts comes after the ones that
     * earned it).
     */
    private function liftUntil(EdgeRule $rule, int $since, int $latestId, CarbonImmutable $now): void
    {
        $untilScript = $rule->script()->untilScript();

        if ($untilScript === null) {
            return;
        }

        foreach ($rule->verdicts()->active()->get() as $verdict) {
            $lifts = $this->buildRuleQuery->handle($rule->team, $untilScript)
                ->where('edge_requests.id', '>', $since)
                ->where('edge_requests.id', '<=', $latestId)
                ->where('edge_requests.occurred_at', '>=', $verdict->last_matched_at)
                ->when($verdict->client_ip !== null, fn ($query) => $query->where('edge_requests.client_ip', $verdict->client_ip))
                ->when($verdict->ja4_id !== null, fn ($query) => $query->where('edge_requests.ja4_id', $verdict->ja4_id))
                ->exists();

            if ($lifts) {
                $verdict->update(['lifted_at' => $now]);
            }
        }
    }

    /**
     * Record a matched site or path: extend its scope verdict if one is in force, otherwise reach a new
     * one. Unlike a client's, a scope's verdict runs on from its latest match, so a mode a rule turns on
     * stays on for as long as what turned it on keeps happening, and ends a full duration after it stops.
     */
    private function recordScope(EdgeRule $rule, object $group, bool $isAggregate, CarbonImmutable $now): bool
    {
        $domainId = data_get($group, 'domain_id') === null ? null : (int) data_get($group, 'domain_id');
        $pathId = data_get($group, 'path_id') === null ? null : (int) data_get($group, 'path_id');
        $matched = (int) data_get($group, 'matched');
        $firstMatchedAt = (string) data_get($group, 'first_matched_at');
        $lastMatchedAt = (string) data_get($group, 'last_matched_at');
        $verdict = ScopeVerdict::query()
            ->active()
            ->where('edge_rule_id', $rule->id)
            ->where(fn ($query) => $domainId === null ? $query->whereNull('domain_id') : $query->where('domain_id', $domainId))
            ->where(fn ($query) => $pathId === null ? $query->whereNull('path_id') : $query->where('path_id', $pathId))
            ->first();
        $expiresAt = $rule->duration_seconds === null ? null : CarbonImmutable::parse($lastMatchedAt)->addSeconds($rule->duration_seconds);

        if ($verdict !== null) {
            $verdict->update([
                'matched_requests' => $isAggregate ? max($verdict->matched_requests, $matched) : $verdict->matched_requests + $matched,
                'last_matched_at' => $lastMatchedAt,
                'expires_at' => $expiresAt === null || $verdict->expires_at === null ? null : max($expiresAt, CarbonImmutable::parse($verdict->expires_at)),
            ]);

            return false;
        }

        if ($expiresAt?->lessThanOrEqualTo($now)) {
            return false;
        }

        $rule->scopeVerdicts()->create([
            'team_id' => $rule->team_id,
            'tag' => $rule->script()->tag,
            'domain_id' => $domainId,
            'path_id' => $pathId,
            'matched_requests' => $matched,
            'first_matched_at' => $firstMatchedAt,
            'last_matched_at' => $lastMatchedAt,
            'expires_at' => $expiresAt,
        ]);

        return true;
    }

    /**
     * Record a matched subject: extend its verdict if one is in force, otherwise reach a new one.
     */
    private function record(EdgeRule $rule, object $group, bool $isAggregate, CarbonImmutable $now): bool
    {
        $lastMatchedAt = $group->last_matched_at;
        $verdict = Verdict::query()
            ->active()
            ->where('edge_rule_id', $rule->id)
            ->where(fn ($query) => $group->client_ip === null ? $query->whereNull('client_ip') : $query->where('client_ip', $group->client_ip))
            ->where(fn ($query) => $group->ja4_id === null ? $query->whereNull('ja4_id') : $query->where('ja4_id', $group->ja4_id))
            ->first();

        if ($verdict !== null) {
            $verdict->update([
                'matched_requests' => $isAggregate ? max($verdict->matched_requests, (int) $group->matched) : $verdict->matched_requests + (int) $group->matched,
                'last_matched_at' => $lastMatchedAt,
            ]);

            return false;
        }

        $expiresAt = $rule->duration_seconds === null ? null : CarbonImmutable::parse($lastMatchedAt)->addSeconds($rule->duration_seconds);

        if ($expiresAt?->lessThanOrEqualTo($now)) {
            return false;
        }

        $rule->verdicts()->create([
            'team_id' => $rule->team_id,
            'action' => $rule->action,
            'tag' => $rule->script()->tag,
            'client_ip' => $group->client_ip,
            'ja4_id' => $group->ja4_id,
            'matched_requests' => (int) $group->matched,
            'first_matched_at' => $group->first_matched_at,
            'last_matched_at' => $lastMatchedAt,
            'expires_at' => $expiresAt,
        ]);

        return true;
    }
}
