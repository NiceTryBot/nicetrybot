<?php

namespace App\Support;

use App\Models\Team;
use App\Models\VisitorDataSetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;

/**
 * Everything that keeps visitors' data: the core's tables and any package's. Each holder deletes only
 * its own rows, so the order they run in doesn't matter.
 */
class VisitorDataHolders
{
    /**
     * The container tag holders register under.
     */
    public const string TAG = 'visitor-data.holders';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * @return list<VisitorDataHolder>
     */
    public function all(): array
    {
        return array_values(array_filter(
            iterator_to_array($this->container->tagged(self::TAG), false),
            fn (mixed $holder) => $holder instanceof VisitorDataHolder,
        ));
    }

    /**
     * Every holder's retention periods, in the order they're listed.
     *
     * @return list<RetentionPeriod>
     */
    public function retention(): array
    {
        $periods = array_merge(...array_map(fn (VisitorDataHolder $holder) => $holder->retention(), $this->all()));
        usort($periods, fn (RetentionPeriod $a, RetentionPeriod $b) => $a->order <=> $b->order);

        return $periods;
    }

    /**
     * The days each retention period defaults to, by field.
     *
     * @return array<string, int>
     */
    public function defaults(): array
    {
        return array_column(array_map(fn (RetentionPeriod $period) => ['field' => $period->field, 'days' => $period->default], $this->retention()), 'days', 'field');
    }

    /**
     * Delete the team's data past its retention, holder by holder, until the deadline passes.
     *
     * @return array<string, int> Rows deleted, by kind of data.
     */
    public function prune(Team $team, VisitorDataSetting $retention, CarbonImmutable $now, float $deadline): array
    {
        $deleted = [];

        foreach ($this->all() as $holder) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $deleted = self::add($deleted, $holder->prune($team, $retention, $now, $deadline));
        }

        return $deleted;
    }

    /**
     * Delete everything every holder keeps about one visitor.
     *
     * @return array<string, int> Rows deleted, by kind of data.
     */
    public function forget(Team $team, ?string $ip, ?string $ja4): array
    {
        $deleted = [];

        foreach ($this->all() as $holder) {
            $deleted = self::add($deleted, $holder->forget($team, $ip, $ja4));
        }

        return $deleted;
    }

    /**
     * @param  array<string, int>  $totals
     * @param  array<string, int>  $more
     * @return array<string, int>
     */
    private static function add(array $totals, array $more): array
    {
        foreach ($more as $kind => $count) {
            $totals[$kind] = ($totals[$kind] ?? 0) + $count;
        }

        return $totals;
    }
}
