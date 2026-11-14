<?php

use App\Actions\Edge\ImportEdgeLogFile;
use App\Enums\RequestAction;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use NiceTry\Honeypots\Enums\HoneypotOutcome;
use NiceTry\Honeypots\Models\Honeypot;
use NiceTry\Honeypots\Models\HoneypotHit;
use NiceTry\Honeypots\Models\HoneypotTrap;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function honeypotLogLine(array $overrides = []): array
{
    return array_merge([
        'occurred_at' => '2026-11-11T21:39:28.512Z',
        'host' => 'shop.example',
        'client_ip' => '35.238.129.244',
        'method' => 'GET',
        'path' => '/wp-admin/',
        'status' => 200,
        'duration_ms' => 4012.6,
        'user_agent' => 'Mozilla/5.0',
        'action' => 'passed',
    ], $overrides);
}

test('what a honeypot did is kept beside the request, only when the honeypot is the request\'s team\'s', function () {
    Storage::fake('edge-logs');
    $domain = Domain::factory()->verified()->enforcing()->create(['hostname' => 'shop.example']);
    $galah = Honeypot::factory()->for($domain->team)->create();
    $trap = HoneypotTrap::factory()->for($galah)->create(['team_id' => $domain->team_id]);
    $theirs = Honeypot::factory()->create();
    $otherTrap = HoneypotTrap::factory()->create();

    Storage::disk('edge-logs')->put('raw/dt=2026-11-11/hh=21/node-1.ndjson.gz', gzencode(collect([
        honeypotLogLine(['path' => '/plain']),
        honeypotLogLine(['honeypot' => $galah->id, 'honeypot_trap' => $trap->id, 'honeypot_status' => 200, 'honeypot_ms' => 4012, 'honeypot_outcome' => 'served']),
        honeypotLogLine(['path' => '/api/x', 'status' => 404, 'honeypot' => $galah->id, 'honeypot_trap' => $otherTrap->id, 'honeypot_status' => 0, 'honeypot_ms' => 30001, 'honeypot_outcome' => 'failed']),
        honeypotLogLine(['honeypot' => $theirs->id, 'honeypot_trap' => $trap->id, 'honeypot_status' => 200, 'honeypot_ms' => 5, 'honeypot_outcome' => 'served']),
        honeypotLogLine(['honeypot' => $galah->id, 'honeypot_outcome' => 'nonsense']),
    ])->map(fn (array $line) => json_encode($line))->implode("\n")));

    app(ImportEdgeLogFile::class)->handle('raw/dt=2026-11-11/hh=21/node-1.ndjson.gz');

    $hits = HoneypotHit::orderBy('id')->get();

    expect(EdgeRequest::count())->toBe(5)
        ->and($hits)->toHaveCount(2)
        ->and($hits[0]->honeypot_id)->toBe($galah->id)
        ->and($hits[0]->honeypot_trap_id)->toBe($trap->id)
        ->and($hits[0]->outcome)->toBe(HoneypotOutcome::Served)
        ->and($hits[0]->status)->toBe(200)
        ->and($hits[0]->took_ms)->toBe(4012)
        ->and($hits[0]->edgeRequest->path)->toBe('/wp-admin/')
        ->and($hits[1]->outcome)->toBe(HoneypotOutcome::Failed)
        ->and($hits[1]->honeypot_trap_id)->toBeNull();
});

test('Activity shows Honeypot in place of Passed when it answered, and the details say which one', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->enforcing()->create();
    $galah = Honeypot::factory()->for($user->currentTeam)->create(['name' => 'Galah']);
    $trap = HoneypotTrap::factory()->for($galah)->create(['team_id' => $user->currentTeam->id, 'patterns' => ['wp-admin*']]);
    $served = EdgeRequest::factory()->for($domain)->create(['action' => RequestAction::Passed, 'occurred_at' => now()->subMinutes(3)]);
    $failed = EdgeRequest::factory()->for($domain)->create(['action' => RequestAction::Passed, 'status_code' => 404, 'occurred_at' => now()->subMinutes(2)]);
    EdgeRequest::factory()->for($domain)->create(['action' => RequestAction::Passed, 'occurred_at' => now()->subMinute()]);

    $this->actingAs($user);
    $passedBefore = substr_count(Livewire::test('pages::activity.index')->html(), 'Passed');

    HoneypotHit::create(['edge_request_id' => $served->id, 'honeypot_id' => $galah->id, 'honeypot_trap_id' => $trap->id, 'outcome' => HoneypotOutcome::Served, 'status' => 200, 'took_ms' => 4100]);
    HoneypotHit::create(['edge_request_id' => $failed->id, 'honeypot_id' => $galah->id, 'honeypot_trap_id' => $trap->id, 'outcome' => HoneypotOutcome::Failed, 'status' => 0, 'took_ms' => 30000]);

    $page = Livewire::test('pages::activity.index');
    $html = $page->html();

    expect(substr_count($html, 'data-test="honeypot-badge"'))->toBe(2)
        ->and($html)->toContain('Served 200')
        ->and($html)->toContain('No answer, site’s 404')
        ->and(substr_count($html, 'Passed'))->toBe($passedBefore - 1);

    $page->call('inspect', $served->id)
        ->assertSeeHtml('data-test="honeypot-details"')
        ->assertSee('the honeypot Galah answered instead, with a 200 in 4.1 s')
        ->assertSee('Trap: wp-admin*.');
});

test('the Honeypots page charts what the team\'s honeypots answered and lists the most trapped paths', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->enforcing()->create(['hostname' => 'shop.example']);
    $galah = Honeypot::factory()->for($user->currentTeam)->create();
    $theirs = Honeypot::factory()->create();
    $hit = function (Honeypot $honeypot, string $path, string $ip, HoneypotOutcome $outcome, int $tookMs) use ($domain) {
        $request = EdgeRequest::factory()->for($domain)->create(['path' => $path, 'client_ip' => $ip, 'status_code' => $outcome === HoneypotOutcome::Served ? 200 : 404, 'occurred_at' => now()->subHour()]);
        HoneypotHit::create(['edge_request_id' => $request->id, 'honeypot_id' => $honeypot->id, 'outcome' => $outcome, 'status' => $outcome === HoneypotOutcome::Served ? 200 : 0, 'took_ms' => $tookMs, 'created_at' => now()->subHour()]);
    };
    $hit($galah, '/wp-admin/', '198.51.100.1', HoneypotOutcome::Served, 4000);
    $hit($galah, '/wp-admin/', '198.51.100.2', HoneypotOutcome::Served, 2000);
    $hit($galah, '/.git/config', '198.51.100.1', HoneypotOutcome::Failed, 30000);
    $hit($theirs, '/someone-elses-trap', '198.51.100.9', HoneypotOutcome::Served, 100);
    $this->actingAs($user);

    $page = Livewire::test('honeypots::index');

    expect($page->get('totals'))->toBe(['served' => 2, 'failed' => 1, 'clients' => 2, 'avg_ms' => 3000])
        ->and(array_sum(array_column($page->instance()->chartBuckets, 'count')))->toBe(2)
        ->and(array_column($page->instance()->topPaths, 'path'))->toBe(['/wp-admin/', '/.git/config'])
        ->and($page->instance()->topPaths[0])->toMatchArray(['host' => 'shop.example', 'hits' => 2, 'served' => 2, 'clients' => 2]);

    $page->assertSeeHtml('data-test="honeypot-chart"')->assertSee('/.git/config')->assertDontSee('/someone-elses-trap');

    $page->set('chartSource', 'failed');

    expect(array_sum(array_column($page->instance()->chartBuckets, 'count')))->toBe(1);
});
