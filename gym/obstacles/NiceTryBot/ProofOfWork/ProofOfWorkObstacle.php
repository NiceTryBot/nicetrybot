<?php

namespace NiceTryBot\Obstacles\ProofOfWork;

use App\Gym\BaseObstacle;
use App\Gym\HashPuzzle;
use App\Gym\ObstacleOutcome;
use App\Gym\ObstacleSetting;
use App\Gym\ObstacleState;
use Illuminate\Support\Number;

/**
 * One SHA-256 puzzle, solved by the visitor's browser in a worker. People wait a moment; scripts
 * without a JavaScript engine never get past it, and farms pay for every attempt in CPU.
 */
class ProofOfWorkObstacle extends BaseObstacle
{
    /**
     * How long an issued challenge stays solvable.
     */
    private const int CHALLENGE_TTL_MS = 600_000;

    /**
     * A hash rate no browser reaches: anything faster was solved natively or on a GPU.
     */
    private const int NATIVE_HASHES_PER_SECOND = 25_000_000;

    public function key(): string
    {
        return 'proof-of-work';
    }

    public function name(): string
    {
        return __('Proof of work');
    }

    public function tagline(): string
    {
        return __('The browser solves a SHA-256 puzzle in the background. People just wait a moment.');
    }

    public function icon(): string
    {
        return 'cpu-chip';
    }

    public function settings(): array
    {
        return [
            ObstacleSetting::range('difficulty', __('Difficulty'), 18, 12, 26, unit: __('bits'), help: __('Each extra bit doubles the work.')),
            ObstacleSetting::choice('display', __('What the visitor sees'), 'progress', [
                'progress' => __('Progress bar'),
                'quiet' => __('Spinner only'),
            ]),
        ];
    }

    public function summarize(array $config): string
    {
        $hashes = HashPuzzle::expectedHashes((int) $config['difficulty']);

        return __(':bits bits · about :laptop on a laptop, :phone on a phone', [
            'bits' => $config['difficulty'],
            'laptop' => HashPuzzle::describeSeconds($hashes / HashPuzzle::LAPTOP_HASHES_PER_SECOND),
            'phone' => HashPuzzle::describeSeconds($hashes / HashPuzzle::PHONE_HASHES_PER_SECOND),
        ]);
    }

    public function humanSeconds(array $config): float
    {
        return HashPuzzle::expectedHashes((int) $config['difficulty']) / HashPuzzle::LAPTOP_HASHES_PER_SECOND;
    }

    public function workHashes(array $config): int
    {
        return HashPuzzle::expectedHashes((int) $config['difficulty']);
    }

    public function flagsWhen(): ?string
    {
        return __('It solves faster than any browser can hash, so a native or GPU solver did the work.');
    }

    public function begin(ObstacleState $state, array $config): void
    {
        $this->issue($state);
    }

    public function viewData(ObstacleState $state, array $config): array
    {
        return [
            'challenge' => $state->get('challenge'),
            'difficulty' => (int) $config['difficulty'],
            'display' => $config['display'],
        ];
    }

    public function attempt(ObstacleState $state, array $config, array $input): ObstacleOutcome
    {
        $bits = (int) $config['difficulty'];
        $challenge = (string) ($input['challenge'] ?? '');
        $nonce = (string) ($input['nonce'] ?? '');
        $issuedAt = (int) $state->get('issued_at', 0);
        $now = now()->getTimestampMs();

        if (! hash_equals((string) $state->get('challenge', ''), $challenge) || $now - $issuedAt > self::CHALLENGE_TTL_MS) {
            $this->issue($state);

            return ObstacleOutcome::retry(__('That check expired. Starting a fresh one.'), ['challenge' => $state->get('challenge'), 'difficulty' => $bits]);
        }

        if (! HashPuzzle::verify($challenge, $nonce, $bits)) {
            $this->issue($state);

            return ObstacleOutcome::retry(__('That didn’t add up. Trying again.'), ['challenge' => $state->get('challenge'), 'difficulty' => $bits]);
        }

        // Browsers search nonces in order from zero, so the nonce is how many hashes were tried.
        $tried = ctype_digit($nonce) ? (int) $nonce + 1 : 0;
        $rate = $tried / max(0.001, ($now - $issuedAt) / 1000);
        $state->put('challenge', null);

        if ($tried >= HashPuzzle::expectedHashes($bits) / 4 && $rate > self::NATIVE_HASHES_PER_SECOND) {
            return ObstacleOutcome::cleared(__('Hashed at :rate a second. No browser does that.', ['rate' => Number::abbreviate($rate, maxPrecision: 1)]), HashPuzzle::expectedHashes($bits));
        }

        return ObstacleOutcome::cleared(hashes: HashPuzzle::expectedHashes($bits));
    }

    private function issue(ObstacleState $state): void
    {
        $state->put('challenge', HashPuzzle::challenge());
        $state->put('issued_at', now()->getTimestampMs());
    }
}
