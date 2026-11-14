<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use App\Models\EdgeRule;
use App\Models\Team;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection;

/**
 * The destinations the core and add-ons have tagged in (App\Support\RuleDestinationSource), laid out as
 * the Rules page's board. A rule's outcome comes from what it's connected to, not from BLOCK or TAG, so a
 * rule goes in the column of each thing that acts on its clients, and in Tag only when nothing does.
 */
class RuleDestinations
{
    /**
     * The container tag sources register under.
     */
    public const string TAG = 'rules.destinations';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Get the board's columns from left to right, each with its cards.
     *
     * @param  Collection<int, EdgeRule>  $rules
     * @return list<array{column: RuleBoardColumn, cards: list<array{rule: EdgeRule, destinations: list<RuleDestination>, links: list<RuleDestination>}>}>
     */
    public function board(Team $team, Collection $rules): array
    {
        $sources = $this->all();
        $destinations = [];

        foreach ($sources as $source) {
            foreach ($source->destinations($team, $rules) as $ruleId => $found) {
                $destinations[$ruleId] = [...($destinations[$ruleId] ?? []), ...$found];
            }
        }

        $columns = [
            new RuleBoardColumn(RuleBoardColumn::TAG_ONLY, __('Tag only'), __('Recorded, and searchable by tag. Nothing happens to the client.'), 'tag', order: 10, tab: RuleBoardColumn::TAB_TAGS),
            ...array_merge(...array_map(fn (RuleDestinationSource $source) => $source->columns(), $sources)),
        ];
        usort($columns, fn (RuleBoardColumn $first, RuleBoardColumn $second) => $first->order <=> $second->order);

        $board = [];

        foreach ($columns as $column) {
            $cards = [];

            foreach ($rules as $rule) {
                $found = $destinations[$rule->id] ?? [];
                $acting = array_values(array_filter($found, fn (RuleDestination $destination) => $destination->column !== null));
                $here = array_values(array_filter($acting, fn (RuleDestination $destination) => $destination->column === $column->key));

                if ($here !== [] || ($column->key === RuleBoardColumn::TAG_ONLY && $acting === [])) {
                    $cards[] = [
                        'rule' => $rule,
                        'destinations' => $here,
                        'links' => array_values(array_filter($found, fn (RuleDestination $destination) => $destination->column === null)),
                    ];
                }
            }

            if ($cards !== [] || $column->showWhenEmpty) {
                $board[] = ['column' => $column, 'cards' => $cards];
            }
        }

        return $board;
    }

    /**
     * Everywhere one rule sends its clients: the acting destinations (a column) and the non-acting links,
     * from every source, in the order the sources are registered.
     *
     * @return list<RuleDestination>
     */
    public function forRule(Team $team, EdgeRule $rule): array
    {
        $rules = new Collection([$rule]);
        $found = [];

        foreach ($this->all() as $source) {
            $found = [...$found, ...($source->destinations($team, $rules)[$rule->id] ?? [])];
        }

        return $found;
    }

    /**
     * @return list<RuleDestinationSource>
     */
    private function all(): array
    {
        return array_values(array_filter(
            app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)),
            fn (mixed $source) => $source instanceof RuleDestinationSource,
        ));
    }
}
