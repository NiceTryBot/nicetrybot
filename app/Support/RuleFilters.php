<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use App\Models\EdgeRule;
use App\Models\Team;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;

/**
 * The rule filters add-ons have tagged in (App\Support\RuleFilter). Without any, the Rules page shows
 * every rule, as the core always has.
 */
class RuleFilters
{
    /**
     * The container tag filters register under.
     */
    public const string TAG = 'rules.filters';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * The filters that have something for the team to pick, keyed by their key, each with its options.
     *
     * @return array<string, array{filter: RuleFilter, options: array<string, string>}>
     */
    public function for(Team $team): array
    {
        $filters = [];

        foreach ($this->all() as $filter) {
            if (! isset($filters[$filter->key()]) && ($options = $filter->options($team)) !== []) {
                $filters[$filter->key()] = ['filter' => $filter, 'options' => $options];
            }
        }

        return $filters;
    }

    /**
     * Narrow the team's rules by what was picked, keyed like for(). A pick that isn't one of its
     * filter's options, or names no filter, is ignored.
     *
     * @param  Builder<EdgeRule>  $rules
     * @param  array<string, mixed>  $picked
     */
    public function apply(Builder $rules, Team $team, array $picked): void
    {
        foreach ($this->picked($team, $picked) as [$filter, $value]) {
            $filter->apply($rules, $team, $value);
        }
    }

    /**
     * Every filter's labels for the team's rules, keyed by rule id, each naming its filter so a card's
     * label can pick that option.
     *
     * @param  list<int>  $ruleIds
     * @return array<int, list<array{filter: string, value: string, label: string}>>
     */
    public function labels(Team $team, array $ruleIds): array
    {
        $labels = [];

        foreach ($ruleIds === [] ? [] : $this->for($team) as $key => ['filter' => $filter]) {
            foreach ($filter->labels($team, $ruleIds) as $ruleId => $label) {
                $labels[$ruleId][] = ['filter' => $key, ...$label];
            }
        }

        return $labels;
    }

    /**
     * The rules what was picked stands for that the team doesn't have yet, by filter key.
     *
     * @param  array<string, mixed>  $picked
     * @return array<string, list<RuleFilterCandidate>>
     */
    public function candidates(Team $team, array $picked): array
    {
        $candidates = [];

        foreach ($this->picked($team, $picked) as $key => [$filter, $value]) {
            if (($found = $filter->candidates($team, $value)) !== []) {
                $candidates[$key] = $found;
            }
        }

        return $candidates;
    }

    /**
     * Add a candidate of a picked filter to the team's rules. Returns what was added, by name, or null
     * when the filter isn't picked or has no such candidate.
     *
     * @param  array<string, mixed>  $picked
     * @return list<string>|null
     */
    public function add(Team $team, array $picked, string $filterKey, string $candidate): ?array
    {
        [$filter, $value] = $this->picked($team, $picked)[$filterKey] ?? [null, null];

        if ($filter === null || ! collect($filter->candidates($team, $value))->contains(fn (RuleFilterCandidate $found) => $found->key === $candidate && $found->unavailable === null)) {
            return null;
        }

        return $filter->add($team, $value, $candidate);
    }

    /**
     * The filters with a valid pick, by key, each with its value.
     *
     * @param  array<string, mixed>  $picked
     * @return array<string, array{0: RuleFilter, 1: string}>
     */
    private function picked(Team $team, array $picked): array
    {
        $found = [];

        foreach ($this->for($team) as $key => ['filter' => $filter, 'options' => $options]) {
            $value = $picked[$key] ?? null;

            if (is_string($value) && array_key_exists($value, $options)) {
                $found[$key] = [$filter, $value];
            }
        }

        return $found;
    }

    /**
     * @return list<RuleFilter>
     */
    private function all(): array
    {
        return array_values(array_filter(
            app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)),
            fn (mixed $filter) => $filter instanceof RuleFilter,
        ));
    }
}
