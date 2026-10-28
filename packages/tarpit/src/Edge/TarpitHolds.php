<?php

namespace NiceTry\Tarpit\Edge;

use App\Models\Domain;
use App\Support\EdgeLineAddon;
use NiceTry\Tarpit\Enums\TarpitOutcome;
use NiceTry\Tarpit\Enums\TarpitReason;
use NiceTry\Tarpit\Models\Tarpit;
use NiceTry\Tarpit\Models\TarpitHold;

/**
 * What a tarpit did to a request, kept beside it: which tarpit, why the client was in, which try, how
 * long it was held. Kept only when the tarpit belongs to the request's team.
 *
 * @phpstan-type Hold array{tarpit_id: int, reason: int, outcome: int, try: int, held_ms: int}
 */
class TarpitHolds implements EdgeLineAddon
{
    /**
     * @param  array<string, mixed>  $line
     * @return Hold|null
     */
    public function read(array $line): ?array
    {
        $tarpitId = filter_var($line['tarpit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $outcome = TarpitOutcome::fromSlug(is_string($line['tarpit_outcome'] ?? null) ? $line['tarpit_outcome'] : null);

        if ($tarpitId === false || $outcome === null) {
            return null;
        }

        $try = filter_var($line['tarpit_try'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $heldMs = filter_var($line['tarpit_ms'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return [
            'tarpit_id' => $tarpitId,
            'reason' => (TarpitReason::fromSlug(is_string($line['tarpit_why'] ?? null) ? $line['tarpit_why'] : null) ?? TarpitReason::Client)->value,
            'outcome' => $outcome->value,
            'try' => $try === false ? 1 : min($try, 65535),
            'held_ms' => $heldMs === false ? 0 : min($heldMs, 4_294_967_295),
        ];
    }

    /**
     * @param  array<int, array{domain_id: int, read: Hold}>  $rows
     * @return array<int, Hold>
     */
    public function keep(array $rows): array
    {
        $tarpitTeams = Tarpit::query()
            ->whereIn('id', array_values(array_unique(array_map(fn (array $row) => $row['read']['tarpit_id'], $rows))))
            ->pluck('team_id', 'id');

        $domainTeams = Domain::query()
            ->whereIn('id', array_values(array_unique(array_column($rows, 'domain_id'))))
            ->pluck('team_id', 'id');

        return array_map(
            fn (array $row) => $row['read'],
            array_filter($rows, fn (array $row) => $tarpitTeams->get($row['read']['tarpit_id']) !== null
                && $tarpitTeams->get($row['read']['tarpit_id']) === $domainTeams->get($row['domain_id'])),
        );
    }

    /**
     * @param  array<int, Hold>  $kept
     */
    public function store(array $kept): void
    {
        $now = now();
        $rows = [];

        foreach ($kept as $edgeRequestId => $hold) {
            $rows[] = [...$hold, 'edge_request_id' => $edgeRequestId, 'created_at' => $now];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            TarpitHold::insert($chunk);
        }
    }
}
