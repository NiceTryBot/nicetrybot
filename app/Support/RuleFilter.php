<?php

namespace App\Support;

use App\Models\EdgeRule;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;

/**
 * A way to narrow the Rules page's board and list from outside the core: to the rules a rule pack
 * installed, say. The page draws each one as a dropdown beside Board and List, with "All active rules"
 * first. A value can also stand for rules the team doesn't have yet (candidates), which the page draws
 * as cards to add, and each rule can be labelled with the value it falls under (its pack), so a card
 * says where it came from. Filters are tagged in the container under RuleFilters::TAG.
 */
interface RuleFilter
{
    /**
     * The filter's key, unique among filters: the page keeps its choice in the URL under it.
     */
    public function key(): string;

    /**
     * What the dropdown is labelled, translated.
     */
    public function label(): string;

    /**
     * What the team can pick, as value => label (translated), in the order the dropdown lists them.
     * An empty list leaves the dropdown out.
     *
     * @return array<string, string>
     */
    public function options(Team $team): array;

    /**
     * Narrow the team's rules to those the picked value stands for. The value is one of options().
     *
     * @param  Builder<EdgeRule>  $rules
     */
    public function apply(Builder $rules, Team $team, string $value): void;

    /**
     * Label the team's rules with the option each falls under, for their cards: value => label
     * (translated, short), keyed by rule id. A rule that falls under none is left out.
     *
     * @param  list<int>  $ruleIds
     * @return array<int, array{value: string, label: string}>
     */
    public function labels(Team $team, array $ruleIds): array;

    /**
     * The rules the picked value stands for that the team doesn't have, in the order to show them.
     *
     * @return list<RuleFilterCandidate>
     */
    public function candidates(Team $team, string $value): array;

    /**
     * Add one of candidates() to the team's rules, authorizing whoever is signed in to. Returns what it
     * added, by name, for the page to say.
     *
     * @return list<string>
     */
    public function add(Team $team, string $value, string $candidate): array;
}
