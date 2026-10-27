<?php

namespace App\Data;

use App\Enums\NotFoundCategory;

readonly class NotFoundSummary
{
    /**
     * @param  array<string, int>  $categoryTotals  Keyed by NotFoundCategory value.
     * @param  array<int, array{hostname: string, hits: int}>  $domains
     * @param  array<int, array{path: string, category: NotFoundCategory, hits: int, uniqueIps: int, domains: int, lastSeenAt: string}>  $paths
     */
    public function __construct(
        public int $total,
        public int $uniquePaths,
        public int $uniqueIps,
        public array $categoryTotals,
        public array $domains,
        public array $paths,
    ) {
        //
    }

    /**
     * Get the number of 404s in the given category.
     */
    public function totalFor(NotFoundCategory $category): int
    {
        return $this->categoryTotals[$category->value] ?? 0;
    }

    /**
     * Get the percentage of 404s in the given category.
     */
    public function shareFor(NotFoundCategory $category): float
    {
        return $this->total === 0 ? 0.0 : round($this->totalFor($category) / $this->total * 100, 1);
    }
}
