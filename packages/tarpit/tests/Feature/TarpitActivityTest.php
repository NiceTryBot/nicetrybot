<?php

use App\Actions\Edge\ImportEdgeLogFile;
use App\Enums\RequestAction;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use NiceTry\Tarpit\Enums\TarpitOutcome;
use NiceTry\Tarpit\Enums\TarpitReason;
use NiceTry\Tarpit\Models\Tarpit;
use NiceTry\Tarpit\Models\TarpitHold;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tarpitLogLine(array $overrides = []): array
{
    return array_merge([
        'occurred_at' => '2026-10-27T21:39:28.512Z',
        'host' => 'shop.example',
        'client_ip' => '35.238.129.244',
        'method' => 'GET',
        'path' => '/phpmyadmin/',
        'status' => 200,
        'duration_ms' => 8012.6,
        'user_agent' => 'Mozilla/5.0',
        'action' => 'passed',
    ], $overrides);
}

test('what a tarpit did is kept beside the request, only when the tarpit is the request\'s team\'s', function () {
    Storage::fake('edge-logs');
    $domain = Domain::factory()->verified()->enforcing()->create(['hostname' => 'shop.example']);
    $tarpit = Tarpit::factory()->for($domain->team)->create();
    $theirs = Tarpit::factory()->create();

    Storage::disk('edge-logs')->put('raw/dt=2026-10-27/hh=21/node-1.ndjson.gz', gzencode(collect([
        tarpitLogLine(['path' => '/plain']),
        tarpitLogLine(['tarpit' => $tarpit->id, 'tarpit_why' => 'rule', 'tarpit_try' => 3, 'tarpit_ms' => 8004, 'tarpit_outcome' => 'held']),
        tarpitLogLine(['tarpit' => $theirs->id, 'tarpit_why' => 'burst', 'tarpit_try' => 2, 'tarpit_ms' => 2000, 'tarpit_outcome' => 'held']),
        tarpitLogLine(['tarpit' => $tarpit->id, 'tarpit_outcome' => 'nonsense']),
    ])->map(fn (array $line) => json_encode($line))->implode("\n")));

    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-10-27/hh=21/node-1.ndjson.gz');

    $hold = TarpitHold::sole();

    expect(EdgeRequest::count())->toBe(4)
        ->and($hold->tarpit_id)->toBe($tarpit->id)
        ->and($hold->reason)->toBe(TarpitReason::Rule)
        ->and($hold->outcome)->toBe(TarpitOutcome::Held)
        ->and($hold->try)->toBe(3)
        ->and($hold->held_ms)->toBe(8004)
        ->and($hold->edgeRequest->path)->toBe('/phpmyadmin/');
});

test('Activity shows Tarpit in place of Passed, and beside In the gym when a routine took the request after', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->enforcing()->create();
    $tarpit = Tarpit::factory()->for($user->currentTeam)->create(['name' => 'Probers']);
    $passed = EdgeRequest::factory()->for($domain)->create(['action' => RequestAction::Passed, 'occurred_at' => now()->subMinutes(3)]);
    $diverted = EdgeRequest::factory()->for($domain)->create(['action' => RequestAction::Diverted, 'occurred_at' => now()->subMinutes(2)]);
    EdgeRequest::factory()->for($domain)->create(['action' => RequestAction::Passed, 'occurred_at' => now()->subMinute()]);

    $this->actingAs($user);
    $passedBefore = substr_count(Livewire::test('pages::activity.index')->html(), 'Passed');

    TarpitHold::create(['edge_request_id' => $passed->id, 'tarpit_id' => $tarpit->id, 'reason' => TarpitReason::NoShow, 'outcome' => TarpitOutcome::Held, 'try' => 2, 'held_ms' => 2003]);
    TarpitHold::create(['edge_request_id' => $diverted->id, 'tarpit_id' => $tarpit->id, 'reason' => TarpitReason::Rule, 'outcome' => TarpitOutcome::Free, 'try' => 1, 'held_ms' => 0]);

    $page = Livewire::test('pages::activity.index');
    $html = $page->html();

    expect(substr_count($html, 'data-test="tarpit-badge"'))->toBe(2)
        ->and($html)->toContain('Held 2 s')
        ->and($html)->toContain('Free try')
        ->and($html)->toContain('In the gym')
        ->and(substr_count($html, 'Passed'))->toBe($passedBefore - 1);

    $page->call('inspect', $passed->id)
        ->assertSeeHtml('data-test="tarpit-details"')
        ->assertSee('the client left the gym without running its script');
});
