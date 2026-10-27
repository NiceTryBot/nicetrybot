<?php

namespace App\Gym;

/**
 * An obstacle's judgement of one attempt.
 *
 * - cleared: the visitor moves on. A flag says they looked automated doing it, which can escalate.
 * - progress: good work, keep going (a waiting room round). The payload carries the next challenge.
 * - retry: that didn't count. The message is shown to the visitor; the payload may carry a new challenge.
 * - failed: out of tries. The gym turns the visitor away, as a routine that ends in Block does.
 */
readonly class ObstacleOutcome
{
    /**
     * @param  'cleared'|'progress'|'retry'|'failed'  $status
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public string $status,
        public ?string $message = null,
        public array $payload = [],
        public ?string $flag = null,
        public int $hashes = 0,
    ) {
        //
    }

    /**
     * The visitor cleared the obstacle, optionally looking automated while doing it.
     */
    public static function cleared(?string $flag = null, int $hashes = 0): self
    {
        return new self('cleared', flag: $flag, hashes: $hashes);
    }

    /**
     * The visitor is making progress and should keep going.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function progress(array $payload, int $hashes = 0, ?string $message = null): self
    {
        return new self('progress', $message, $payload, hashes: $hashes);
    }

    /**
     * The attempt didn't count; the visitor tries again.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function retry(string $message, array $payload = []): self
    {
        return new self('retry', $message, $payload);
    }

    /**
     * The visitor is out of tries; the gym turns them away.
     */
    public static function failed(string $message): self
    {
        return new self('failed', $message);
    }

    /**
     * Determine if the visitor cleared the obstacle.
     */
    public function isCleared(): bool
    {
        return $this->status === 'cleared';
    }

    /**
     * Determine if the visitor ran out of tries.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
