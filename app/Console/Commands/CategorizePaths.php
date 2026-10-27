<?php

namespace App\Console\Commands;

use App\Enums\PathCategory;
use App\Models\RequestPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('edge:categorize-paths')]
#[Description('Re-apply the PathCategory patterns to every recorded path not categorized by hand (run after changing a pattern)')]
class CategorizePaths extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $changed = 0;

        // Paths someone categorized by hand keep their category.
        RequestPath::query()->whereNull('categorized_at')->orderBy('id')->chunkById(1000, function ($paths) use (&$changed) {
            foreach ($paths as $path) {
                $category = PathCategory::classify($path->path);

                if ($path->category !== $category) {
                    $path->update(['category' => $category]);
                    $changed++;
                }
            }
        });

        $this->info(sprintf('Recategorized %d path(s).', $changed));

        $counts = RequestPath::query()->toBase()->selectRaw('category, count(*) as paths')->groupBy('category')->pluck('paths', 'category');

        $this->table(['Category', 'Paths'], collect(PathCategory::cases())
            ->map(fn (PathCategory $category) => [$category->slug(), (int) ($counts[$category->value] ?? 0)])
            ->push(['(uncategorized)', (int) ($counts[''] ?? $counts[null] ?? 0)])
            ->all());

        return self::SUCCESS;
    }
}
