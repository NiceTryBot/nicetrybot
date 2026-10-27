<?php

namespace Tests\Fixtures\Addons\Example\Rules;

use App\Support\RuleFieldDefinition;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * The example add-on's rule field: the bookmark a request's page has on its team, read through the
 * add-on's own table.
 */
enum BookmarkField: string implements RuleFieldDefinition
{
    case Bookmark = 'bookmark';

    public function column(): string
    {
        return 'example_bookmarks.path';
    }

    public function likeColumn(): string
    {
        return 'lower(example_bookmarks.path)';
    }

    public function isText(): bool
    {
        return true;
    }

    public function isLive(): bool
    {
        return false;
    }

    public function isNumeric(): bool
    {
        return false;
    }

    public function storedValues(int|string $literal): array
    {
        return [(string) $literal];
    }

    public function englishName(): string
    {
        return 'bookmarked page';
    }

    public function group(): string
    {
        return 'Bookmarks';
    }

    public function hint(): string
    {
        return 'only on bookmarked pages';
    }

    public function ruleNote(): string
    {
        return 'only once the team bookmarks a page.';
    }

    public function joins(): array
    {
        return ['example_bookmarks' => fn (Builder $query) => $query->leftJoin('example_bookmarks', fn (JoinClause $join) => $join
            ->on('example_bookmarks.team_id', '=', 'domains.team_id')
            ->on('example_bookmarks.path', '=', 'request_paths.path'))];
    }
}
