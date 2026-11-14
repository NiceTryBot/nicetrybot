<?php

namespace NiceTry\Honeypots\Edge;

use App\Models\Domain;
use App\Support\EdgeLineAddon;
use NiceTry\Honeypots\Enums\HoneypotOutcome;
use NiceTry\Honeypots\Models\Honeypot;
use NiceTry\Honeypots\Models\HoneypotHit;
use NiceTry\Honeypots\Models\HoneypotTrap;

/**
 * What a honeypot did with a trapped request, kept beside it: which honeypot and trap, whether it
 * answered, its status and how long it took. Kept only when the honeypot belongs to the request's team.
 *
 * @phpstan-type Hit array{honeypot_id: int, honeypot_trap_id: int|null, outcome: int, status: int, took_ms: int}
 */
class HoneypotHits implements EdgeLineAddon
{
    /**
     * @param  array<string, mixed>  $line
     * @return Hit|null
     */
    public function read(array $line): ?array
    {
        $honeypotId = filter_var($line['honeypot'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $outcome = HoneypotOutcome::fromSlug(is_string($line['honeypot_outcome'] ?? null) ? $line['honeypot_outcome'] : null);

        if ($honeypotId === false || $outcome === null) {
            return null;
        }

        $trapId = filter_var($line['honeypot_trap'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $status = filter_var($line['honeypot_status'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 999]]);
        $tookMs = filter_var($line['honeypot_ms'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return [
            'honeypot_id' => $honeypotId,
            'honeypot_trap_id' => $trapId === false ? null : $trapId,
            'outcome' => $outcome->value,
            'status' => $status === false ? 0 : $status,
            'took_ms' => $tookMs === false ? 0 : min($tookMs, 4_294_967_295),
        ];
    }

    /**
     * @param  array<int, array{domain_id: int, read: Hit}>  $rows
     * @return array<int, Hit>
     */
    public function keep(array $rows): array
    {
        $honeypotTeams = Honeypot::query()
            ->whereIn('id', array_values(array_unique(array_map(fn (array $row) => $row['read']['honeypot_id'], $rows))))
            ->pluck('team_id', 'id');

        $trapIds = HoneypotTrap::query()
            ->whereIn('id', array_values(array_filter(array_unique(array_map(fn (array $row) => $row['read']['honeypot_trap_id'], $rows)))))
            ->pluck('honeypot_id', 'id');

        $domainTeams = Domain::query()
            ->whereIn('id', array_values(array_unique(array_column($rows, 'domain_id'))))
            ->pluck('team_id', 'id');

        $kept = array_filter($rows, fn (array $row) => $honeypotTeams->get($row['read']['honeypot_id']) !== null
            && $honeypotTeams->get($row['read']['honeypot_id']) === $domainTeams->get($row['domain_id']));

        return array_map(function (array $row) use ($trapIds) {
            $hit = $row['read'];

            // A trap that's gone, or isn't this honeypot's, isn't pointed at.
            if ($hit['honeypot_trap_id'] !== null && $trapIds->get($hit['honeypot_trap_id']) !== $hit['honeypot_id']) {
                $hit['honeypot_trap_id'] = null;
            }

            return $hit;
        }, $kept);
    }

    /**
     * @param  array<int, Hit>  $kept
     */
    public function store(array $kept): void
    {
        $now = now();
        $rows = [];

        foreach ($kept as $edgeRequestId => $hit) {
            $rows[] = [...$hit, 'edge_request_id' => $edgeRequestId, 'created_at' => $now];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            HoneypotHit::insert($chunk);
        }
    }
}
