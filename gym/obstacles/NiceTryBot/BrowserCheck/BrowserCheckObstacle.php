<?php

namespace NiceTryBot\Obstacles\BrowserCheck;

use App\Gym\BaseObstacle;
use App\Gym\ObstacleOutcome;
use App\Gym\ObstacleSetting;
use App\Gym\ObstacleState;
use Illuminate\Support\Str;

/**
 * A silent look at the browser: does it run JavaScript at all, and does it admit to being automated?
 * It never stops anyone by itself, because people use odd browsers too. It clears everyone and flags
 * what looks automated, so the routine can escalate to something harder.
 */
class BrowserCheckObstacle extends BaseObstacle
{
    public function key(): string
    {
        return 'browser-check';
    }

    public function name(): string
    {
        return __('Browser check');
    }

    public function tagline(): string
    {
        return __('A silent look for automation: webdriver flags, headless windows, a missing Chrome runtime.');
    }

    public function icon(): string
    {
        return 'shield-check';
    }

    public function settings(): array
    {
        return [
            ObstacleSetting::choice('depth', __('What to look at'), 'thorough', [
                'basic' => __('Only the webdriver flag'),
                'thorough' => __('Webdriver, headless hints and the claimed browser'),
            ]),
        ];
    }

    public function summarize(array $config): string
    {
        return $config['depth'] === 'basic' ? __('Webdriver flag only · silent') : __('Thorough · silent');
    }

    public function humanSeconds(array $config): float
    {
        return 0.5;
    }

    public function workHashes(array $config): int
    {
        return 0;
    }

    public function flagsWhen(): ?string
    {
        return __('The browser admits to automation, looks headless, or isn’t the browser its user agent claims.');
    }

    public function begin(ObstacleState $state, array $config): void
    {
        $state->put('nonce', Str::random(24));
    }

    public function viewData(ObstacleState $state, array $config): array
    {
        return ['nonce' => $state->get('nonce')];
    }

    public function attempt(ObstacleState $state, array $config, array $input): ObstacleOutcome
    {
        if (! hash_equals((string) $state->get('nonce', ''), (string) ($input['nonce'] ?? ''))) {
            $state->put('nonce', Str::random(24));

            return ObstacleOutcome::retry(__('Checking again…'), ['nonce' => $state->get('nonce')]);
        }

        return ObstacleOutcome::cleared($this->reasons($input, $config['depth'] === 'thorough')[0] ?? null);
    }

    /**
     * List what looks automated about the browser's report, most telling first.
     *
     * @param  array<string, mixed>  $signals
     * @return list<string>
     */
    private function reasons(array $signals, bool $thorough): array
    {
        $reasons = [];

        if (filter_var($signals['webdriver'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $reasons[] = __('Reports navigator.webdriver: an automated browser.');
        }

        if (! $thorough) {
            return $reasons;
        }

        $userAgent = (string) ($signals['ua'] ?? '');
        $claimsDesktopChrome = str_contains($userAgent, 'Chrome/') && ! str_contains($userAgent, 'Mobile') && ! str_contains($userAgent, 'Edg/');

        if (str_contains($userAgent, 'Headless')) {
            $reasons[] = __('Calls itself headless in its user agent.');
        }

        if ($claimsDesktopChrome && ! filter_var($signals['chrome_runtime'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $reasons[] = __('Claims to be Chrome, but has no Chrome runtime.');
        }

        if ((int) ($signals['languages'] ?? 0) === 0) {
            $reasons[] = __('Sends no languages.');
        }

        if ((int) ($signals['screen_height'] ?? 0) >= 1000 && (int) ($signals['inner_height'] ?? 0) <= 500 && (float) ($signals['pixel_ratio'] ?? 0) === 1.0) {
            $reasons[] = __('A small window on a large screen, as headless browsers have.');
        }

        return $reasons;
    }
}
