<?php

namespace NiceTryBot\Obstacles\WaitingRoom;

use App\Gym\BaseObstacle;
use App\Gym\HashPuzzle;
use App\Gym\ObstacleOutcome;
use App\Gym\ObstacleSetting;
use App\Gym\ObstacleState;
use Illuminate\Support\Number;

/**
 * A queue that keeps the visitor's CPU busy: proof-of-work rounds back to back until the time is
 * served. The server keeps the clock. If the rounds stop for too long (the tab closed, or a bot
 * parked the work), the clock starts over, so the time can't be served without the work.
 *
 * Rounds are sized to take about a second or more on a laptop, so a visitor posts roughly once a
 * second rather than many times, well inside the gym's rate limit.
 */
class WaitingRoomObstacle extends BaseObstacle
{
    /**
     * The longest pause between rounds before the clock starts over, at the least: room for a request
     * that hangs and is retried (a browser stuck opening a connection), well short of parked work.
     */
    private const int MIN_PAUSE_MS = 30_000;

    /**
     * Network and bookkeeping overhead added to each round in estimates.
     */
    private const float ROUND_OVERHEAD_SECONDS = 0.12;

    public function key(): string
    {
        return 'waiting-room';
    }

    public function name(): string
    {
        return __('Waiting room');
    }

    public function tagline(): string
    {
        return __('A queue that keeps the visitor’s CPU busy, one proof of work after another, for as long as you set.');
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function settings(): array
    {
        return [
            ObstacleSetting::range('seconds', __('Time in the room'), 45, 10, 300, 5, unit: 's'),
            ObstacleSetting::choice('intensity', __('Work while waiting'), 'steady', [
                'light' => __('Light (20-bit rounds)'),
                'steady' => __('Steady (21-bit rounds)'),
                'heavy' => __('Heavy (22-bit rounds)'),
            ], help: __('Bigger rounds make fast solvers do more work between check-ins, for the same wait.')),
            ObstacleSetting::toggle('show_time', __('Show the time left'), true),
            ObstacleSetting::text('headline', __('Headline'), __('You’re in the queue'), 60),
        ];
    }

    public function summarize(array $config): string
    {
        return __(':time · :intensity work · about :hashes hashes', [
            'time' => HashPuzzle::describeSeconds((int) $config['seconds']),
            'intensity' => strtolower((string) $this->scalar($config, 'intensity')),
            'hashes' => Number::abbreviate($this->workHashes($config)),
        ]);
    }

    public function humanSeconds(array $config): float
    {
        return (float) $config['seconds'];
    }

    public function workHashes(array $config): int
    {
        $perRound = HashPuzzle::expectedHashes($this->bits($config));
        $roundSeconds = $perRound / HashPuzzle::LAPTOP_HASHES_PER_SECOND + self::ROUND_OVERHEAD_SECONDS;

        return (int) ceil((int) $config['seconds'] / $roundSeconds) * $perRound;
    }

    public function begin(ObstacleState $state, array $config): void
    {
        $now = now()->getTimestampMs();

        $state->put('clock_started_at', $now);
        $state->put('last_round_at', $now);
        $state->put('rounds', 0);
        $state->put('challenge', HashPuzzle::challenge());
    }

    public function viewData(ObstacleState $state, array $config): array
    {
        return [
            'challenge' => $state->get('challenge'),
            'difficulty' => $this->bits($config),
            'totalMs' => (int) $config['seconds'] * 1000,
            'elapsedMs' => $this->served($state),
            'showTime' => (bool) $config['show_time'],
            'headline' => $config['headline'],
        ];
    }

    public function attempt(ObstacleState $state, array $config, array $input): ObstacleOutcome
    {
        $bits = $this->bits($config);
        $now = now()->getTimestampMs();

        if (! hash_equals((string) $state->get('challenge', ''), (string) ($input['challenge'] ?? ''))) {
            return ObstacleOutcome::progress($this->payload($state, $config));
        }

        if (! HashPuzzle::verify((string) $input['challenge'], (string) ($input['nonce'] ?? ''), $bits)) {
            $state->put('challenge', HashPuzzle::challenge());

            return ObstacleOutcome::retry(__('A round didn’t check out. Carrying on.'), $this->payload($state, $config));
        }

        $state->put('challenge', HashPuzzle::challenge());

        if ($now - (int) $state->get('last_round_at') > $this->maxPauseMs($bits)) {
            $state->put('clock_started_at', $now);
            $state->put('last_round_at', $now);
            $state->put('rounds', 0);

            return ObstacleOutcome::progress($this->payload($state, $config), message: __('The work paused, so the clock started over.'));
        }

        $state->put('last_round_at', $now);
        $state->put('rounds', (int) $state->get('rounds') + 1);

        if ($this->served($state, $now) >= (int) $config['seconds'] * 1000) {
            return ObstacleOutcome::cleared(hashes: HashPuzzle::expectedHashes($bits));
        }

        return ObstacleOutcome::progress($this->payload($state, $config), HashPuzzle::expectedHashes($bits));
    }

    /**
     * Get the difficulty of each round.
     *
     * @param  array<string, int|string|bool|list<string>>  $config
     */
    private function bits(array $config): int
    {
        return match ($config['intensity']) {
            'light' => 20,
            'heavy' => 22,
            default => 21,
        };
    }

    /**
     * Get the longest pause between rounds that keeps the clock running: three slow-phone rounds, and
     * never under MIN_PAUSE_MS.
     */
    private function maxPauseMs(int $bits): int
    {
        return max(self::MIN_PAUSE_MS, (int) (3000 * HashPuzzle::expectedHashes($bits) / HashPuzzle::PHONE_HASHES_PER_SECOND));
    }

    /**
     * Get how much of the wait the visitor has served, in milliseconds.
     */
    private function served(ObstacleState $state, ?int $now = null): int
    {
        return max(0, ($now ?? now()->getTimestampMs()) - (int) $state->get('clock_started_at', 0));
    }

    /**
     * @param  array<string, int|string|bool|list<string>>  $config
     * @return array<string, mixed>
     */
    private function payload(ObstacleState $state, array $config): array
    {
        return [
            'challenge' => $state->get('challenge'),
            'difficulty' => $this->bits($config),
            'elapsedMs' => $this->served($state),
            'totalMs' => (int) $config['seconds'] * 1000,
            'rounds' => (int) $state->get('rounds'),
        ];
    }
}
