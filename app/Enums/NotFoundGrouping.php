<?php

namespace App\Enums;

use App\Data\RequestQuery;

/**
 * A way to split the most requested missing paths into groups on the 404s page.
 */
enum NotFoundGrouping: string
{
    case LooksLike = 'looks-like';
    case PathCategory = 'category';
    case Tag = 'tag';

    /**
     * Get the display label for the grouping.
     */
    public function label(): string
    {
        return match ($this) {
            self::LooksLike => __('Looks like'),
            self::PathCategory => __('Path category'),
            self::Tag => __('Rule tag'),
        };
    }

    /**
     * Get how many paths to show in each group. The broad groups are few, so each can show more.
     */
    public function pathsPerGroup(): int
    {
        return $this === self::LooksLike ? 10 : 5;
    }

    /**
     * Get the SQL that gives a 404 its group. "Looks like" sorts a path's stored category the way
     * NotFoundCategory::fromPathCategory() does, so the database can group by it.
     *
     * @return literal-string
     */
    public function column(): string
    {
        return match ($this) {
            self::LooksLike => "case when request_paths.category in (10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 22) then 'probe' when request_paths.category in (1, 20, 21) then 'browser_asset' else 'missing_page' end",
            self::PathCategory => 'request_paths.category',
            self::Tag => 'bot_tags.name',
        };
    }

    /**
     * Get the display name for a group's key.
     */
    public function groupLabel(?string $key): string
    {
        return match ($this) {
            self::LooksLike => NotFoundCategory::from((string) $key)->label(),
            self::PathCategory => $key === null ? __('No category') : PathCategory::from((int) $key)->slug(),
            self::Tag => $key ?? __('No tag'),
        };
    }

    /**
     * Get a short explanation of a group, when there's more to say than its name.
     */
    public function groupDescription(?string $key): ?string
    {
        return match ($this) {
            self::LooksLike => NotFoundCategory::from((string) $key)->description(),
            self::PathCategory => $key === null ? null : PathCategory::from((int) $key)->description(),
            self::Tag => null,
        };
    }

    /**
     * Get the Activity search that finds a group's requests.
     */
    public function activityClause(?string $key): string
    {
        return match ($this) {
            self::LooksLike => match (NotFoundCategory::from((string) $key)) {
                NotFoundCategory::Probe => "category = 'probe'",
                NotFoundCategory::BrowserAsset => "category = 'asset'",
                NotFoundCategory::MissingPage => "NOT category = 'probe' AND NOT category = 'asset'",
            },
            self::PathCategory => $key === null ? 'category IS NULL' : 'category = '.RequestQuery::quote(PathCategory::from((int) $key)->slug()),
            self::Tag => $key === null ? 'tag IS NULL' : 'tag = '.RequestQuery::quote($key),
        };
    }
}
