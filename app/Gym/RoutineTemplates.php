<?php

namespace App\Gym;

use App\Addons\AddonSwitchboard;
use App\Enums\GymExit;
use App\Enums\GymField;
use App\Enums\GymOperator;
use App\Gym\Contracts\RoutineTemplateSource;
use App\Models\EdgeRule;
use Illuminate\Support\Str;

/**
 * Starter routines shown in the gym's template gallery: the core's, then each source's tagged in under
 * TAG (App\Gym\Contracts\RoutineTemplateSource), in tag order.
 */
class RoutineTemplates
{
    /**
     * The container tag sources register under.
     */
    public const string TAG = 'gym.routine-templates';

    /**
     * Get every template, keyed by slug: the core's, then each source's. A key already taken keeps the
     * template that had it first.
     *
     * @return array<string, array{name: string, blurb: string, icon: string, match_all: bool, conditions: list<array{field: string, operator: string, values: list<string>}>, obstacles: list<array{type: string, config: array<string, mixed>, escalate: string|null}>, exit: GymExit, pass_seconds: int}>
     */
    public static function all(): array
    {
        $templates = self::core();

        foreach (app(AddonSwitchboard::class)->active(app()->tagged(self::TAG)) as $source) {
            if ($source instanceof RoutineTemplateSource) {
                $templates += $source->routineTemplates();
            }
        }

        return $templates;
    }

    /**
     * The core's templates.
     *
     * @return array<string, array{name: string, blurb: string, icon: string, match_all: bool, conditions: list<array{field: string, operator: string, values: list<string>}>, obstacles: list<array{type: string, config: array<string, mixed>, escalate: string|null}>, exit: GymExit, pass_seconds: int}>
     */
    private static function core(): array
    {
        return [
            'not-a-browser' => [
                'name' => __('Not a mainstream browser'),
                'blurb' => __('Anything not calling itself Chrome, Firefox or Safari works a puzzle, then types a code.'),
                'icon' => 'window',
                'match_all' => true,
                'conditions' => [
                    ['field' => 'browser', 'operator' => 'not_in', 'values' => ['chrome', 'firefox', 'safari']],
                ],
                'obstacles' => [
                    ['type' => 'proof-of-work', 'config' => ['difficulty' => 18, 'display' => 'progress'], 'escalate' => 'waiting-room'],
                    ['type' => 'screen-code', 'config' => ['length' => 5, 'characters' => 'digits', 'noise' => 'medium', 'misses' => 3], 'escalate' => null],
                ],
                'exit' => GymExit::Continue,
                'pass_seconds' => 86400,
            ],
            'datacenter-waiting-room' => [
                'name' => __('Datacenter waiting room'),
                'blurb' => __('DigitalOcean, Hetzner and friends wait in a queue that keeps their CPU busy.'),
                'icon' => 'server-stack',
                'match_all' => true,
                'conditions' => [
                    ['field' => 'network', 'operator' => 'in', 'values' => ['digitalocean', 'hetzner', 'ovh', 'vultr', 'linode', 'contabo']],
                ],
                'obstacles' => [
                    ['type' => 'waiting-room', 'config' => ['seconds' => 60, 'intensity' => 'steady', 'show_time' => true], 'escalate' => null],
                ],
                'exit' => GymExit::Continue,
                'pass_seconds' => 3600,
            ],
            'probe-scanners' => [
                'name' => __('Probe scanners'),
                'blurb' => __('Anyone fishing for .env files or admin panels works for their 404.'),
                'icon' => 'document-magnifying-glass',
                'match_all' => true,
                'conditions' => [
                    ['field' => 'category', 'operator' => 'in', 'values' => ['probe']],
                ],
                'obstacles' => [
                    ['type' => 'proof-of-work', 'config' => ['difficulty' => 20, 'display' => 'quiet'], 'escalate' => 'waiting-room'],
                ],
                'exit' => GymExit::NotFound,
                'pass_seconds' => 3600,
            ],
            'flagged-by-rules' => [
                'name' => __('Flagged by your rules'),
                'blurb' => __('Visitors your rules tagged or banned get a silent check, and a code if they look automated.'),
                'icon' => 'scale',
                'match_all' => true,
                'conditions' => [
                    ['field' => 'verdict', 'operator' => 'in', 'values' => ['any']],
                ],
                'obstacles' => [
                    ['type' => 'browser-check', 'config' => ['depth' => 'thorough'], 'escalate' => 'screen-code'],
                    ['type' => 'proof-of-work', 'config' => ['difficulty' => 18, 'display' => 'progress'], 'escalate' => null],
                ],
                'exit' => GymExit::Continue,
                'pass_seconds' => 3600,
            ],
        ];
    }

    /**
     * Get a template as routine attributes, each obstacle with a fresh id, or null for an unknown key.
     *
     * @return array{name: string, match_all: bool, conditions: list<array{field: string, operator: string, values: list<string>}>, obstacles: list<array{id: string, type: string, config: array<string, mixed>, escalate: string|null}>, exit_action: GymExit, pass_seconds: int}|null
     */
    public static function attributes(?string $key): ?array
    {
        $template = $key === null ? null : self::all()[$key] ?? null;

        if ($template === null) {
            return null;
        }

        return [
            'name' => $template['name'],
            'match_all' => $template['match_all'],
            'conditions' => $template['conditions'],
            'obstacles' => array_map(fn (array $obstacle) => ['id' => Str::lower(Str::random(10)), ...$obstacle], $template['obstacles']),
            'exit_action' => $template['exit'],
            'pass_seconds' => $template['pass_seconds'],
        ];
    }

    /**
     * Get a new routine for the clients one rule flags: the "Flagged by your rules" obstacles, with
     * the entry narrowed to that rule's verdicts and the rule's name.
     *
     * @return array{name: string, match_all: bool, conditions: list<array{field: string, operator: string, values: list<string>}>, obstacles: list<array{id: string, type: string, config: array<string, mixed>, escalate: string|null}>, exit_action: GymExit, pass_seconds: int}
     */
    public static function forRule(EdgeRule $rule): array
    {
        return [
            ...self::attributes('flagged-by-rules'),
            'name' => $rule->name,
            'conditions' => [['field' => GymField::Verdict->value, 'operator' => GymOperator::In->value, 'values' => [(string) $rule->id]]],
        ];
    }
}
