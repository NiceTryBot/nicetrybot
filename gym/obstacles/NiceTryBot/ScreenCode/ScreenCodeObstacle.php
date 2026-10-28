<?php

namespace NiceTryBot\Obstacles\ScreenCode;

use App\Gym\BaseObstacle;
use App\Gym\CodeImage;
use App\Gym\ObstacleOutcome;
use App\Gym\ObstacleSetting;
use App\Gym\ObstacleState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Number;

/**
 * A short code drawn as a noisy image that the visitor types back. The code never reaches the page as
 * text; a wrong answer draws a fresh one, so guessing gets nowhere.
 */
class ScreenCodeObstacle extends BaseObstacle
{
    /**
     * Answers faster than this come from a script, not a person reading and typing.
     */
    private const int HUMAN_MIN_MS = 1_500;

    /**
     * Characters for each alphabet, without look-alikes (0/O, 1/I/L).
     *
     * @var array<string, string>
     */
    private const array ALPHABETS = [
        'digits' => '23456789',
        'letters' => 'ABCDEFGHJKMNPQRSTUVWXYZ',
        'mixed' => '23456789ABCDEFGHJKMNPQRSTUVWXYZ',
    ];

    public function key(): string
    {
        return 'screen-code';
    }

    public function name(): string
    {
        return __('Type the code');
    }

    public function tagline(): string
    {
        return __('A short code drawn as a noisy image. The visitor reads it and types it back.');
    }

    public function icon(): string
    {
        return 'eye';
    }

    public function settings(): array
    {
        return [
            ObstacleSetting::range('length', __('Code length'), 5, 4, 8, unit: __('characters')),
            ObstacleSetting::choice('characters', __('Characters'), 'digits', [
                'digits' => __('Digits'),
                'letters' => __('Letters'),
                'mixed' => __('Digits and letters'),
            ], help: __('Look-alikes such as 0 and O are never used.')),
            ObstacleSetting::choice('noise', __('Noise'), 'medium', [
                'light' => __('Light'),
                'medium' => __('Medium'),
                'heavy' => __('Heavy'),
            ], help: __('More noise stops more OCR, and slows people down a little.')),
            ObstacleSetting::range('misses', __('Misses before it looks automated'), 3, 1, 5),
        ];
    }

    public function summarize(array $config): string
    {
        return __(':length :characters · :noise noise', [
            'length' => (int) $config['length'],
            'characters' => mb_strtolower($this->setting('characters')->format($config['characters'])),
            'noise' => mb_strtolower($this->setting('noise')->format($config['noise'])),
        ]);
    }

    public function humanSeconds(array $config): float
    {
        return 4 + (int) $config['length'] * 0.7;
    }

    public function workHashes(array $config): int
    {
        return 0;
    }

    public function flagsWhen(): ?string
    {
        return __('It answers faster than a person can read and type, or needs several tries, as OCR loops do.');
    }

    public function begin(ObstacleState $state, array $config): void
    {
        $state->put('misses', 0);
        $this->draw($state, $config);
    }

    public function viewData(ObstacleState $state, array $config): array
    {
        return [
            'image' => CodeImage::dataUri($this->code($state), (string) $this->scalar($config, 'noise')),
            'length' => (int) $config['length'],
            'numeric' => $config['characters'] === 'digits',
        ];
    }

    public function attempt(ObstacleState $state, array $config, array $input): ObstacleOutcome
    {
        if (filter_var($input['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->draw($state, $config);

            return ObstacleOutcome::retry(__('Here’s a new code.'));
        }

        $answer = strtoupper((string) preg_replace('/\s+/', '', (string) ($input['code'] ?? '')));

        if ($answer === '' || ! hash_equals($this->code($state), $answer)) {
            $state->put('misses', (int) $state->get('misses') + 1);
            $this->draw($state, $config);

            return ObstacleOutcome::retry(__('That’s not it. Here’s a fresh code.'));
        }

        $elapsed = now()->getTimestampMs() - (int) $state->get('shown_at');
        $misses = (int) $state->get('misses');

        if ($elapsed < self::HUMAN_MIN_MS) {
            return ObstacleOutcome::cleared(__('Answered in :seconds s, faster than anyone reads and types.', ['seconds' => Number::format($elapsed / 1000, maxPrecision: 2)]));
        }

        if ($misses >= (int) $config['misses']) {
            return ObstacleOutcome::cleared(trans_choice('Needed :count try.|Needed :count tries.', $misses + 1));
        }

        return ObstacleOutcome::cleared();
    }

    /**
     * Draw a fresh code. It's stored encrypted, so the session never holds it in the clear.
     *
     * @param  array<string, int|string|bool|list<string>>  $config
     */
    private function draw(ObstacleState $state, array $config): void
    {
        $alphabet = self::ALPHABETS[(string) $this->scalar($config, 'characters')] ?? self::ALPHABETS['digits'];
        $code = '';

        for ($i = 0; $i < (int) $config['length']; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $state->put('code', Crypt::encryptString($code));
        $state->put('shown_at', now()->getTimestampMs());
    }

    private function code(ObstacleState $state): string
    {
        return Crypt::decryptString((string) $state->get('code'));
    }
}
