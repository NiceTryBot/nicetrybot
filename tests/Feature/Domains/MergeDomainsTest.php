<?php

use App\Actions\Domains\MergeDomains;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\LiveTail;
use App\Models\Team;

test('merging a domain into another moves its history there and removes it', function () {
    $team = Team::factory()->create();
    $bare = Domain::factory()->for($team)->verified()->create(['hostname' => 'both.example.com']);
    $www = Domain::factory()->for($team)->verified()->create(['hostname' => 'www.both.example.com']);
    EdgeRequest::factory()->count(2)->create(['domain_id' => $www->id]);
    $tail = LiveTail::factory()->for($team)->create(['domain_id' => $www->id]);

    app(MergeDomains::class)->handle($www, $bare);

    expect(Domain::find($www->id))->toBeNull()
        ->and($bare->edgeRequests()->count())->toBe(2)
        ->and($tail->fresh()->domain_id)->toBe($bare->id);
});

test('a domain only merges into another of the same team', function () {
    $mine = Domain::factory()->verified()->create();
    $theirs = Domain::factory()->verified()->create();

    expect(fn () => app(MergeDomains::class)->handle($mine, $theirs))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(MergeDomains::class)->handle($mine, $mine))->toThrow(InvalidArgumentException::class)
        ->and($mine->fresh())->not->toBeNull();
});
