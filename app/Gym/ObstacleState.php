<?php

namespace App\Gym;

/**
 * One visitor's progress on one obstacle, saved in their gym session between requests. The gym keeps
 * the timing, attempts and flags; `data` belongs to the obstacle.
 */
class ObstacleState
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $flags  Why the visitor looked automated here.
     */
    public function __construct(
        public array $data = [],
        public int $attempts = 0,
        public ?int $startedAt = null,
        public ?int $clearedAt = null,
        public ?string $notice = null,
        public array $flags = [],
        public int $hashes = 0,
    ) {
        //
    }

    /**
     * Rebuild a state saved with toArray().
     *
     * @param  array<string, mixed>  $saved
     */
    public static function fromArray(array $saved): self
    {
        return new self(
            data: (array) ($saved['data'] ?? []),
            attempts: (int) ($saved['attempts'] ?? 0),
            startedAt: isset($saved['started_at']) ? (int) $saved['started_at'] : null,
            clearedAt: isset($saved['cleared_at']) ? (int) $saved['cleared_at'] : null,
            notice: $saved['notice'] ?? null,
            flags: array_values((array) ($saved['flags'] ?? [])),
            hashes: (int) ($saved['hashes'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'attempts' => $this->attempts,
            'started_at' => $this->startedAt,
            'cleared_at' => $this->clearedAt,
            'notice' => $this->notice,
            'flags' => $this->flags,
            'hashes' => $this->hashes,
        ];
    }

    /**
     * Get a value the obstacle stored.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Store a value for the obstacle.
     */
    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * Determine if the visitor has cleared the obstacle.
     */
    public function isCleared(): bool
    {
        return $this->clearedAt !== null;
    }

    /**
     * Get how long the visitor spent on the obstacle, in milliseconds, so far or in total.
     */
    public function elapsedMs(?int $nowMs = null): int
    {
        if ($this->startedAt === null) {
            return 0;
        }

        return max(0, ($this->clearedAt ?? $nowMs ?? now()->getTimestampMs()) - $this->startedAt);
    }
}
