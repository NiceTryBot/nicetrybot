<?php

use App\Actions\Edge\ImportEdgeLogFile;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\LiveTail;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
});

/**
 * One log line in the shape Vector sends, with the event id it gives every line.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function liveLogLine(array $overrides = []): array
{
    return array_merge([
        'event_id' => '0199c4a1-7b2e-7c3d-9e4f-5a6b7c8d9e0f',
        'occurred_at' => '2026-10-08T17:30:01.250Z',
        'host' => 'sign.example',
        'client_ip' => '203.0.113.7',
        'method' => 'POST',
        'path' => '/login',
        'status' => 200,
        'duration_ms' => 84,
        'action' => 'passed',
    ], $overrides);
}

/**
 * POST lines to the ingest endpoint as Vector does: newline-delimited JSON with the edge's token.
 *
 * @param  list<array<string, mixed>>  $lines
 */
function ingestLines(array $lines, ?string $token = 'edge-token'): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/x-ndjson', 'HTTP_ACCEPT' => 'application/json'];

    if ($token !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    }

    return test()->call('POST', route('edge.ingest'), server: $server, content: collect($lines)->map(fn (array $line) => json_encode($line))->implode("\n")."\n");
}

test('the edge hears which hostnames are tailed live: a whole team\'s, or one filtered domain', function () {
    $watched = Team::factory()->create();
    Domain::factory()->for($watched)->verified()->create(['hostname' => 'a.example']);
    Domain::factory()->for($watched)->verified()->create(['hostname' => 'b.example']);
    $filtered = Domain::factory()->verified()->create(['hostname' => 'c.example']);
    Domain::factory()->for($filtered->team)->verified()->create(['hostname' => 'c-sibling.example']);
    Domain::factory()->verified()->create(['hostname' => 'nobody-watching.example']);

    $this->getJson(route('edge.live-tail'))->assertUnauthorized();
    $this->withToken('edge-token')->getJson(route('edge.live-tail'))->assertExactJson(['hosts' => []]);

    LiveTail::factory()->for($watched)->create();
    LiveTail::factory()->for($filtered->team)->create(['domain_id' => $filtered->id]);

    $this->withToken('edge-token')
        ->getJson(route('edge.live-tail'))
        ->assertExactJson(['hosts' => ['a-example.preview.example', 'a.example', 'b-example.preview.example', 'b.example', 'c-example.preview.example', 'c.example']]);
});

test('a tail stops reaching the edge once its ten minutes are up or its page stops checking in', function (string $state) {
    Domain::factory()->verified()->create(['hostname' => 'a.example']);
    LiveTail::factory()->for(Domain::sole()->team)->{$state}()->create();

    $this->withToken('edge-token')->getJson(route('edge.live-tail'))->assertExactJson(['hosts' => []]);
})->with(['expired', 'idle']);

test('ingested lines are stored, and the S3 import skips them later instead of storing them twice', function () {
    Storage::fake('edge-logs');
    $domain = Domain::factory()->verified()->create(['hostname' => 'sign.example']);
    $second = liveLogLine(['event_id' => '0199c4a1-7b2e-7c3d-9e4f-5a6b7c8d9e10', 'path' => '/dashboard']);

    ingestLines([liveLogLine()], token: null)->assertUnauthorized();
    ingestLines([liveLogLine()], token: 'wrong')->assertUnauthorized();

    ingestLines([liveLogLine(), liveLogLine()])->assertOk()->assertExactJson(['imported' => 1, 'skipped' => 0, 'duplicates' => 1]);

    Storage::disk('edge-logs')->put('raw/node-1.ndjson.gz', gzencode(collect([liveLogLine(), $second])->map(fn (array $line) => json_encode($line))->implode("\n")));
    $file = app(ImportEdgeLogFile::class)->handle('raw/node-1.ndjson.gz');

    expect(EdgeRequest::query()->orderBy('id')->get()->map(fn (EdgeRequest $request) => [$request->domain_id, $request->path, $request->event_id])->all())
        ->toBe([
            [$domain->id, '/login', '0199c4a1-7b2e-7c3d-9e4f-5a6b7c8d9e0f'],
            [$domain->id, '/dashboard', '0199c4a1-7b2e-7c3d-9e4f-5a6b7c8d9e10'],
        ])
        ->and($file->rows_imported)->toBe(1);
});

test('turning on live tail tails the filtered domain, keeps going while the page checks in, and stops on its own', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create(['hostname' => 'sign.example']);
    $this->actingAs($user);

    $component = Livewire::test('pages::activity.index')
        ->set('domain', 'sign.example')
        ->call('toggleLive')
        ->assertSet('live', true)
        ->assertSee('Connecting to the edge…');

    expect(LiveTail::sole())->user_id->toBe($user->id)->domain_id->toBe($domain->id);

    $this->withToken('edge-token')->getJson(route('edge.live-tail'))->assertExactJson(['hosts' => ['sign-example.preview.example', 'sign.example']]);
    $component->call('heartbeat')->assertSee('Live')->assertDontSee('Connecting to the edge…');

    $this->travel(5)->minutes();
    $component->set('domain', '')->call('heartbeat')->assertSet('live', true);

    expect(LiveTail::sole())->domain_id->toBeNull()->last_seen_at->toEqual(now()->startOfSecond());

    $this->travel(6)->minutes();
    $component->call('heartbeat')->assertSet('live', false);

    expect(LiveTail::count())->toBe(0);
});

test('live tail can be turned off, and logging out ends it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::activity.index')->call('toggleLive')->call('toggleLive')->assertSet('live', false);

    expect(LiveTail::count())->toBe(0);

    LiveTail::factory()->for($user->currentTeam)->for($user)->create();
    $other = LiveTail::factory()->create();

    $this->post(route('logout'));

    expect(LiveTail::pluck('id')->all())->toBe([$other->id]);
});
