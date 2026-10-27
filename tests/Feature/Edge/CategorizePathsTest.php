<?php

use App\Enums\PathCategory;
use App\Models\RequestPath;

test('recategorizing applies the current patterns to every recorded path', function () {
    $stale = RequestPath::factory()->create(['path' => '/.env.backup', 'category' => null]);
    $wrong = RequestPath::factory()->create(['path' => '/contact', 'category' => PathCategory::ProbeAdmin]);

    $this->artisan('edge:categorize-paths')
        ->expectsOutputToContain('Recategorized 2 path(s).')
        ->assertSuccessful();

    expect($stale->fresh()->category)->toBe(PathCategory::ProbeCreds)
        ->and($wrong->fresh()->category)->toBeNull();
});
