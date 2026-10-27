<?php

namespace App\Actions\Rules;

use App\Enums\RuleSubject;
use App\Models\EdgeRule;
use App\Models\Verdict;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class FindStaleVerdicts
{
    /**
     * The most active verdicts checked after one edit; a rule with more is checked newest first.
     */
    private const int VERDICT_LIMIT = 500;

    public function __construct(private BacktestRule $backtestRule)
    {
        //
    }

    /**
     * Find the rule's active verdicts that its current version wouldn't have reached: a different action
     * or tag, a different subject (IP, fingerprint or both), or requests that no longer match. A verdict
     * is replayed against the requests that earned it, from its first match (less the window, for a
     * HAVING rule) to its last. Editing a rule never lifts its verdicts on its own; this is what's offered.
     *
     * @return Collection<int, Verdict>
     */
    public function handle(EdgeRule $rule): Collection
    {
        $script = $rule->script();
        $wantsIp = in_array(RuleSubject::Ip, $script->subjects, true);
        $wantsJa4 = in_array(RuleSubject::Ja4, $script->subjects, true);

        return $rule->verdicts()
            ->active()
            ->with('ja4Fingerprint')
            ->latest('last_matched_at')
            ->limit(self::VERDICT_LIMIT)
            ->get()
            ->filter(function (Verdict $verdict) use ($rule, $script, $wantsIp, $wantsJa4) {
                if ($verdict->action !== $script->action || $verdict->tag !== $script->tag) {
                    return true;
                }

                if (($verdict->client_ip !== null) !== $wantsIp || ($verdict->ja4_id !== null) !== $wantsJa4) {
                    return true;
                }

                $from = CarbonImmutable::parse($verdict->first_matched_at)->subSeconds($script->isAggregate() ? (int) $script->windowSeconds : 0);

                return ! $this->backtestRule->subjectMatches($rule->team, $script, $verdict->client_ip, $verdict->ja4_id, $from, CarbonImmutable::parse($verdict->last_matched_at));
            })
            ->values();
    }
}
