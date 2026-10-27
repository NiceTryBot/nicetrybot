<?php

namespace Tests\Fixtures\Addons\Example\Rules;

use App\Enums\GymExit;
use App\Gym\Contracts\RoutineTemplateSource;
use App\Support\RuleTemplateSource;

/**
 * The example add-on's starter rule and gym routine, both about bookmarked pages. Each also offers a
 * template under a key the core already has, which the core's keeps.
 */
class BookmarkTemplates implements RoutineTemplateSource, RuleTemplateSource
{
    public function ruleTemplates(): array
    {
        return [
            'bookmark-hammering' => [
                'name' => 'Bookmark hammering',
                'blurb' => 'One client requesting a bookmarked page over and over.',
                'source' => "TAG ip AS 'bookmark-hammering' FOR 1 DAY\nWHERE bookmark IS NOT NULL\nWITHIN 1 MINUTE\nHAVING COUNT(*) >= 30",
            ],
            'probe-scanners' => ['name' => 'Not the core\'s', 'blurb' => '', 'source' => 'BAN ip FOR 1 DAY WHERE status = 404'],
        ];
    }

    public function routineTemplates(): array
    {
        return [
            'bookmark-knock' => [
                'name' => 'Knock for bookmarks',
                'blurb' => 'Anyone on a probe path knocks twice.',
                'icon' => 'bookmark',
                'match_all' => true,
                'conditions' => [['field' => 'category', 'operator' => 'in', 'values' => ['probe']]],
                'obstacles' => [['type' => 'knock', 'config' => [], 'escalate' => null]],
                'exit' => GymExit::Continue,
                'pass_seconds' => 3600,
            ],
            'probe-scanners' => [
                'name' => 'Not the core\'s',
                'blurb' => '',
                'icon' => 'bookmark',
                'match_all' => true,
                'conditions' => [],
                'obstacles' => [],
                'exit' => GymExit::Continue,
                'pass_seconds' => 60,
            ],
        ];
    }
}
