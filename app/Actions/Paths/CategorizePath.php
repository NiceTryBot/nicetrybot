<?php

namespace App\Actions\Paths;

use App\Enums\PathCategory;
use App\Models\RequestPath;
use App\Models\User;

class CategorizePath
{
    /**
     * Set a path's category by hand, so the patterns leave it alone from now on. Null means it's an
     * ordinary page. Every request for the path, past and future, shows the new category.
     */
    public function handle(RequestPath $path, ?PathCategory $category, User $user): RequestPath
    {
        $path->update([
            'category' => $category,
            'categorized_by' => $user->id,
            'categorized_at' => now(),
        ]);

        return $path;
    }

    /**
     * Hand the path back to the patterns.
     */
    public function automatic(RequestPath $path): RequestPath
    {
        $path->update([
            'category' => PathCategory::classify($path->path),
            'categorized_by' => null,
            'categorized_at' => null,
        ]);

        return $path;
    }
}
