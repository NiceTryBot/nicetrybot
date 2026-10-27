<?php

use App\Models\AiCredential;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Database probes on two sites: /backup.sql three times from two IPs across both, /db.sqlite once, and
 * a homepage visit the search leaves out.
 */
function databaseProbes(User $user): void
{
    $shop = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'shop.example.com']);
    $blog = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'blog.example.com']);

    EdgeRequest::factory()->for($shop)->count(2)->create(['client_ip' => '198.51.100.1', 'path' => '/backup.sql']);
    EdgeRequest::factory()->for($blog)->create(['client_ip' => '198.51.100.2', 'path' => '/backup.sql']);
    EdgeRequest::factory()->for($blog)->create(['client_ip' => '198.51.100.2', 'path' => '/db.sqlite']);
    EdgeRequest::factory()->for($shop)->create(['client_ip' => '198.51.100.3', 'path' => '/']);
}

test('the paths view lists each path the search finds once, busiest first, with its requests counted', function () {
    $user = User::factory()->create();
    databaseProbes($user);

    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')
        ->set('view', 'paths')
        ->set('search', "path LIKE '%.sql%'")
        ->assertSeeText('Requests (4)')
        ->assertSeeText('Paths (2)')
        ->assertSeeInOrder(['/backup.sql', '3', '2 sites', '/db.sqlite', '1', 'blog.example.com'])
        ->assertDontSee('data-path="/"', escape: false);

    expect(substr_count($page->html(), 'data-test="path-row"'))->toBe(2);
});

test('picking a path lists its requests, keeping what the search asked', function () {
    $user = User::factory()->create();
    databaseProbes($user);
    $pathId = EdgeRequest::query()->firstWhere('client_ip', '198.51.100.2')->path_id;

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('view', 'paths')
        ->set('search', "host = 'blog.example.com'")
        ->call('showPath', $pathId)
        ->call('loadCounts')
        ->assertSet('view', 'requests')
        ->assertSet('search', "host = 'blog.example.com'\nAND path = '/backup.sql'")
        ->assertSeeText('Requests (1)');
});

test('a path only another team has requested isn\'t taken into the search', function () {
    $user = User::factory()->create();
    $theirs = EdgeRequest::factory()->for(Domain::factory())->create(['path' => '/their-secret-path']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->call('showPath', $theirs->path_id)
        ->assertSet('search', '')
        ->assertDontSee('/their-secret-path');
});

test('the paths view exports its paths and counts as CSV', function () {
    $user = User::factory()->create();
    databaseProbes($user);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('view', 'paths')
        ->set('search', "path LIKE '%.sql%'")
        ->call('export')
        ->assertFileDownloaded(contentType: 'text/csv');
});

test('a question about unique requests lists the paths Claude\'s search finds', function () {
    $user = User::factory()->create();
    AiCredential::factory()->for($user)->create();
    databaseProbes($user);
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeSearchAnswer(['search' => "path LIKE '%.sql%'", 'view' => 'paths']))]);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')->call('loadCounts')
        ->set('question', 'show me all unique requests for databases, with counts')
        ->call('ask')
        ->call('$refresh')
        ->assertSet('view', 'paths')
        ->assertSeeText('Paths (2)');
});
