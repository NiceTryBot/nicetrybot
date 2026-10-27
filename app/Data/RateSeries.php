<?php

namespace App\Data;

readonly class RateSeries
{
    /**
     * @param  int  $unit  The seconds a rate is counted over: 1 for per second, 60 for per minute.
     * @param  int  $period  The seconds each point covers, a multiple of the unit.
     * @param  list<array{t: int, label: string, requests: float, suspicious: float, peak: int}>  $points
     */
    public function __construct(
        public int $unit,
        public int $period,
        public array $points,
    ) {
        //
    }

    /**
     * Determine if each point spans more than one unit, so its busiest unit can differ from its average.
     */
    public function hasPeak(): bool
    {
        return $this->period > $this->unit;
    }

    /**
     * Get the busiest single unit across the series, with the label of the point it fell in.
     *
     * @return array{requests: int, label: ?string}
     */
    public function peak(): array
    {
        $busiest = collect($this->points)->sortByDesc('peak')->first();

        return [
            'requests' => (int) ($busiest['peak'] ?? 0),
            'label' => ($busiest['peak'] ?? 0) > 0 ? $busiest['label'] : null,
        ];
    }

    /**
     * Get the period in words, such as "10 seconds" or "1 hour".
     */
    public function periodLabel(): string
    {
        return match (true) {
            $this->period % 3_600 === 0 => trans_choice(':count hour|:count hours', $this->period / 3_600),
            $this->period % 60 === 0 => trans_choice(':count minute|:count minutes', $this->period / 60),
            default => trans_choice(':count second|:count seconds', $this->period),
        };
    }
}
