<?php

namespace NiceTry\Tarpit\Edge;

use App\Data\EdgeExpression;
use App\Data\RuleScript;
use App\Enums\DomainStatus;
use App\Enums\ProtectionMode;
use App\Models\Domain;
use App\Models\EdgeRule;
use App\Models\Verdict;
use App\Support\AllowedAddresses;
use App\Support\TarpitFeedContributor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use NiceTry\Tarpit\Models\Tarpit;
use NiceTry\Tarpit\Rules\StatusHolds;
use NiceTry\Tarpit\Support\GymNoShows;

/**
 * The enabled tarpits, in the edge's tarpit feed: for each, the hostnames of the domains it covers in
 * Enforce mode, how it makes clients wait (and step back), and who's in it: the clients holding its
 * rules' verdicts and (when it takes them) the gym's no-shows, its rules that one request can decide,
 * the statuses its status-only rules hold the origin's answer on, and the edge's own 404 and burst
 * limits. Observe-mode domains never hold anyone. Kept a few seconds, as edges poll often.
 */
class TarpitList implements TarpitFeedContributor
{
    /**
     * How long the list is kept between polls, in seconds. Changes to a tarpit drop it at once.
     */
    private const int CACHE_SECONDS = 10;

    public function __construct(private GymNoShows $noShows)
    {
        //
    }

    public function tarpits(string $edge): array
    {
        return Cache::remember(Tarpit::FEED_CACHE_KEY, self::CACHE_SECONDS, fn () => $this->build());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function build(): array
    {
        $tarpits = Tarpit::query()
            ->where('enabled', true)
            ->with(['rules' => fn ($query) => $query->where('edge_rules.enabled', true)->select(['edge_rules.id', 'edge_rules.team_id', 'edge_rules.source']), 'domains:id'])
            ->orderBy('id')
            ->get();

        if ($tarpits->isEmpty()) {
            return [];
        }

        $domains = Domain::query()
            ->whereIn('team_id', $tarpits->pluck('team_id')->unique())
            ->where('protection_mode', ProtectionMode::Enforce)
            ->where('status', DomainStatus::Active)
            ->orderBy('hostname')
            ->withEdgeHostnames()
            ->get(['id', 'team_id', 'hostname', 'preview_hostname', 'origin_id', 'serves_www'])
            ->groupBy('team_id');

        // Tarpit Pro unlocks the advanced holds. Off, every tarpit falls back to the free behaviour:
        // only 404 answers held, a flat cooldown, assets held like any 404.
        $pro = Tarpit::proIsOn();

        return $tarpits
            ->map(fn (Tarpit $tarpit) => $this->payload($tarpit, $domains->get($tarpit->team_id, collect()), $pro))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * One tarpit as the edge takes it, or null when it covers no domain in Enforce mode.
     *
     * @param  Collection<int, Domain>  $teamDomains
     * @return array<string, mixed>|null
     */
    private function payload(Tarpit $tarpit, Collection $teamDomains, bool $pro): ?array
    {
        $covered = $tarpit->all_domains ? $teamDomains : $teamDomains->whereIn('id', $tarpit->domains->modelKeys());
        $hosts = $covered->flatMap(fn (Domain $domain) => $domain->edgeHostnames())->unique()->sort()->values()->all();

        if ($hosts === []) {
            return null;
        }

        $scripts = $tarpit->rules
            ->mapWithKeys(fn (EdgeRule $rule) => [$rule->id => rescue(fn () => RuleScript::parse($rule->source), report: false)])
            ->filter();

        return [
            'id' => $tarpit->id,
            'hosts' => $hosts,
            'free' => $tarpit->free_tries,
            'delays' => array_map('intval', $tarpit->delays),
            'cap' => $tarpit->max_delay_seconds,
            'max_held' => $tarpit->max_held,
            'when_full' => $tarpit->when_full->slug(),
            'after' => $tarpit->after_wait->slug(),
            'forget' => $tarpit->forget_after_seconds,
            'clients' => $this->clients($tarpit),
            'rules' => $this->liveRules($scripts),
            // The origin's answers held for their status, from the status-only rules: any status with
            // Tarpit Pro, only the 404 without.
            'statuses' => $this->statuses($scripts, $pro),
            // Pro steps each try back on its own cooldown; free uses one flat cooldown.
            'cooldowns' => $this->cooldowns($tarpit, $pro),
            'not_found' => $tarpit->not_found_count === null ? null : ['count' => $tarpit->not_found_count, 'within' => $tarpit->not_found_within_seconds],
            'burst' => $tarpit->burst_count === null ? null : ['count' => $tarpit->burst_count, 'within' => $tarpit->burst_within_seconds],
            // Pro only: sparing static assets from 404 counts and status holds.
            'skip_assets' => $pro,
        ];
    }

    /**
     * The statuses the tarpit's status-only rules hold the origin's answer on, among those a tarpit may
     * hold: all of them with Tarpit Pro, only the 404 without.
     *
     * @param  Collection<int, RuleScript>  $scripts
     * @return list<int>
     */
    private function statuses(Collection $scripts, bool $pro): array
    {
        $statuses = $scripts->flatMap(fn (RuleScript $script) => StatusHolds::of($script) ?? [])
            ->unique()
            ->sort()
            ->filter(fn (int $status) => in_array($status, $pro ? Tarpit::ALLOWED_STATUSES : [404], true));

        return $statuses->values()->all();
    }

    /**
     * The cooldowns the tarpit steps a try back on: its full ladder with Tarpit Pro, only the first value
     * (a flat cooldown, repeated for every try) without, and none when it has no ladder.
     *
     * @return list<int>
     */
    private function cooldowns(Tarpit $tarpit, bool $pro): array
    {
        $cooldowns = array_map('intval', $tarpit->cooldowns ?? []);

        return $pro ? $cooldowns : array_slice($cooldowns, 0, 1);
    }

    /**
     * The clients holding a verdict from one of the tarpit's rules, then its gym no-shows not already
     * listed. Read as plain rows: a rule can hold tens of thousands of verdicts.
     *
     * @return list<array{ip: string|null, ja4: string|null, why: string}>
     */
    private function clients(Tarpit $tarpit): array
    {
        $clients = [];

        if ($tarpit->rules->isNotEmpty()) {
            Verdict::query()
                ->active()
                ->where('verdicts.team_id', $tarpit->team_id)
                ->whereIn('verdicts.edge_rule_id', $tarpit->rules->modelKeys())
                ->leftJoin('tls_fingerprints', 'tls_fingerprints.id', '=', 'verdicts.ja4_id')
                ->orderBy('verdicts.id')
                ->toBase()
                ->get(['verdicts.client_ip', 'tls_fingerprints.value as ja4'])
                ->each(function (object $row) use (&$clients) {
                    $clients[$row->client_ip.'|'.$row->ja4] ??= ['ip' => $row->client_ip, 'ja4' => $row->ja4, 'why' => 'verdict'];
                });
        }

        if ($tarpit->takes_gym_no_shows) {
            foreach ($this->noShows->for($tarpit->team_id) as $client) {
                $clients[$client['ip'].'|'] ??= [...$client, 'why' => 'no_show'];
            }
        }

        // An allowed address (the White List or Nice Bots add-ons) is never held, however it got here.
        $allowed = app(AllowedAddresses::class);

        return array_values(array_filter(
            $clients,
            fn (array $client) => $client['ip'] === null || ! $allowed->allows($client['ip']),
        ));
    }

    /**
     * The tarpit's rules that one request can decide, as EdgeExpressions: the edge holds a request one
     * matches before its client holds the verdict.
     *
     * @param  Collection<int, RuleScript>  $scripts  By rule id.
     * @return array<int, array{id: int, expr: array<string, mixed>}>
     */
    private function liveRules(Collection $scripts): array
    {
        return $scripts
            ->map(fn (RuleScript $script, int $ruleId) => ($expression = EdgeExpression::forScript($script)) === null ? null : ['id' => $ruleId, 'expr' => $expression])
            ->filter()
            ->values()
            ->all();
    }
}
