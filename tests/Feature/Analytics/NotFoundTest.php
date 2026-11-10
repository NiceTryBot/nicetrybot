<?php

use App\Actions\Analytics\SummarizeNotFound;
use App\Enums\NotFoundGrouping;
use App\Enums\PathCategory;
use App\Enums\TrafficPeriod;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;
use Livewire\Livewire;

test('the 404s page ranks domains and sorts paths into probes and browser assets', function () {
    $user = User::factory()->create();
    $busy = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'busy.example.com']);
    $quiet = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'quiet.example.com']);
    EdgeRequest::factory()->for($busy)->count(3)->probe()->create();
    EdgeRequest::factory()->for($quiet)->notFound('/apple-touch-icon.png')->create();
    EdgeRequest::factory()->for($quiet)->create(['path' => '/claim']);

    $this->actingAs($user)
        ->get(route('not-found.index'))
        ->assertOk()
        ->assertSeeInOrder(['busy.example.com', 'quiet.example.com'])
        ->assertSeeInOrder(['/.env', 'Scanner probe', '/apple-touch-icon.png', 'Browser asset'])
        ->assertDontSee('/claim');
});

test('the 404s page sorts paths by the category stored on them, including one chosen by hand', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $request = EdgeRequest::factory()->for($domain)->count(2)->notFound('/old-landing')->create()->first();
    $request->requestPath->update(['category' => PathCategory::ProbeAdmin, 'categorized_by' => $user->id, 'categorized_at' => now()]);

    $this->actingAs($user)
        ->get(route('not-found.index'))
        ->assertOk()
        ->assertSeeInOrder(['/old-landing', 'Scanner probe']);
});

test('the 404s page keeps its summary for a minute and serves it from the cache', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->count(2)->probe()->create();

    $this->actingAs($user);

    $this->get(route('not-found.index'))->assertOk()->assertSeeInOrder(['/.env', 'Scanner probe']);

    EdgeRequest::factory()->for($domain)->notFound('/later-page')->create();

    $this->get(route('not-found.index'))
        ->assertOk()
        ->assertSeeInOrder(['/.env', 'Scanner probe'])
        ->assertDontSee('/later-page');
});

test('grouping by what a path looks like ranks each group and lists its own busiest paths', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->count(3)->notFound('/favicon.ico')->create();
    EdgeRequest::factory()->for($domain)->count(2)->notFound('/.env')->create();
    EdgeRequest::factory()->for($domain)->notFound('/.git/config')->create();
    EdgeRequest::factory()->for($domain)->count(2)->notFound('/old-landing')->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/.aws/credentials']);

    $groups = app(SummarizeNotFound::class)->groupedPaths($user->currentTeam, TrafficPeriod::Week, NotFoundGrouping::LooksLike);

    expect(array_column($groups, 'key'))->toBe(['browser_asset', 'probe', 'missing_page'])
        ->and(array_column($groups, 'hits'))->toBe([3, 3, 2])
        ->and(array_column($groups[1]['paths'], 'path'))->toBe(['/.env', '/.git/config'])
        ->and(array_column($groups[1]['paths'], 'hits'))->toBe([2, 1]);
});

test('grouping by what a path looks like sorts every path category into the right group', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();

    foreach (PathCategory::cases() as $category) {
        EdgeRequest::factory()->for($domain)->notFound("/path-{$category->value}")->create()
            ->requestPath->update(['category' => $category]);
    }

    $groups = app(SummarizeNotFound::class)->groupedPaths($user->currentTeam, TrafficPeriod::Week, NotFoundGrouping::LooksLike);

    expect(array_column($groups, 'hits', 'key'))->toBe(['probe' => 11, 'browser_asset' => 3, 'missing_page' => 1]);

    foreach ($groups as $group) {
        expect(array_unique(array_map(fn (array $path) => $path['category']->value, $group['paths'])))->toBe([$group['key']]);
    }
});

test('grouping by path category and by rule tag keeps the 404s that have neither', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->count(2)->notFound('/.env')->create(['bot_tag' => 'scanner']);
    EdgeRequest::factory()->for($domain)->notFound('/old-landing')->create();

    $summarize = app(SummarizeNotFound::class);

    expect(array_column($summarize->groupedPaths($user->currentTeam, TrafficPeriod::Week, NotFoundGrouping::PathCategory), 'key'))
        ->toBe([(string) PathCategory::ProbeCreds->value, null])
        ->and(array_column($summarize->groupedPaths($user->currentTeam, TrafficPeriod::Week, NotFoundGrouping::Tag), 'key'))
        ->toBe(['scanner', null]);
});

test('a group counts all of its 404s while listing only its busiest paths', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();

    foreach (range(1, 6) as $page) {
        EdgeRequest::factory()->for($domain)->notFound("/old-page-{$page}")->create(['bot_tag' => 'stale']);
    }

    [$group] = app(SummarizeNotFound::class)->groupedPaths($user->currentTeam, TrafficPeriod::Week, NotFoundGrouping::Tag);

    expect($group['hits'])->toBe(6)
        ->and($group['distinctPaths'])->toBe(6)
        ->and($group['paths'])->toHaveCount(5);
});

test('grouping leaves out other teams\' 404s and requests that did not 404', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->notFound('/.env')->create();
    EdgeRequest::factory()->for($domain)->create(['path' => '/.env']);
    EdgeRequest::factory()->notFound('/.env')->create();

    [$group] = app(SummarizeNotFound::class)->groupedPaths($user->currentTeam, TrafficPeriod::Week, NotFoundGrouping::LooksLike);

    expect($group['hits'])->toBe(1);
});

test('the 404s page shows the chosen grouping with a link to each group\'s requests', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->notFound('/.env')->create(['bot_tag' => "o'brien"]);

    $this->actingAs($user)
        ->get(route('not-found.index', ['group' => 'tag']))
        ->assertOk()
        ->assertSeeInOrder(["o'brien", '/.env'])
        ->assertSee(e(route('activity.index', ['q' => "tag = 'o''brien'", 'status' => '404', 'period' => '7d', 'domain' => ''])), escape: false);
});

test('the 404s page shows the flat list when the grouping in the URL is unknown', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->probe()->create();

    $this->actingAs($user)
        ->get(route('not-found.index', ['group' => 'nonsense']))
        ->assertOk()
        ->assertSeeInOrder(['/.env', 'Scanner probe']);
});

test('each group\'s Activity search finds exactly that group\'s requests', function (NotFoundGrouping $grouping, ?string $key, array $sees, array $hides) {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->notFound('/.env')->create(['bot_tag' => 'scanner']);
    EdgeRequest::factory()->for($domain)->notFound('/favicon.ico')->create();
    EdgeRequest::factory()->for($domain)->notFound('/old-landing')->create();

    $this->actingAs($user);

    $activity = Livewire::test('pages::activity.index')->set('search', $grouping->activityClause($key));

    foreach ($sees as $path) {
        $activity->assertSee($path);
    }

    foreach ($hides as $path) {
        $activity->assertDontSee($path);
    }
})->with([
    'scanner probes' => [NotFoundGrouping::LooksLike, 'probe', ['/.env'], ['/favicon.ico', '/old-landing']],
    'browser assets' => [NotFoundGrouping::LooksLike, 'browser_asset', ['/favicon.ico'], ['/.env', '/old-landing']],
    'missing pages' => [NotFoundGrouping::LooksLike, 'missing_page', ['/old-landing'], ['/.env', '/favicon.ico']],
    'one path category' => [NotFoundGrouping::PathCategory, (string) PathCategory::ProbeCreds->value, ['/.env'], ['/favicon.ico', '/old-landing']],
    'no path category' => [NotFoundGrouping::PathCategory, null, ['/old-landing'], ['/.env', '/favicon.ico']],
    'one tag' => [NotFoundGrouping::Tag, 'scanner', ['/.env'], ['/favicon.ico', '/old-landing']],
    'no tag' => [NotFoundGrouping::Tag, null, ['/favicon.ico', '/old-landing'], ['/.env']],
]);
