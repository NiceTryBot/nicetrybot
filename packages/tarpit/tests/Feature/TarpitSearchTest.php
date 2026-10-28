<?php

use App\Data\RuleScript;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;
use App\Support\RuleFields;
use Livewire\Livewire;
use NiceTry\Tarpit\Enums\TarpitOutcome;
use NiceTry\Tarpit\Enums\TarpitReason;
use NiceTry\Tarpit\Models\Tarpit;
use NiceTry\Tarpit\Models\TarpitHold;
use NiceTry\Tarpit\Rules\TarpitField;

test('tarpit and tarpit.outcome are search and rule fields the edge can\'t judge', function () {
    expect(app(RuleFields::class)->find('tarpit'))->toBe(TarpitField::Tarpit)
        ->and(app(RuleFields::class)->find('tarpit.outcome'))->toBe(TarpitField::Outcome)
        ->and(RuleScript::parse("TAG ip AS 'x' FOR 1 DAY WHERE tarpit = 'curl probes' AND tarpit.outcome = 'held'")->isLive())->toBeFalse()
        ->and(fn () => RuleScript::parse("TAG ip AS 'x' FOR 1 DAY WHERE tarpit.outcome = 'asleep'"))->toThrow(Exception::class);
});

test('Activity finds the requests a tarpit touched, by its name, by what it did, or any', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->enforcing()->create();
    $curl = Tarpit::factory()->for($user->currentTeam)->create(['name' => 'curl probes']);
    $bursts = Tarpit::factory()->for($user->currentTeam)->create(['name' => 'Bursts']);
    $request = fn (string $path) => EdgeRequest::factory()->for($domain)->create(['path' => $path, 'occurred_at' => now()->subMinute()]);
    $hold = fn (EdgeRequest $request, Tarpit $tarpit, TarpitOutcome $outcome) => TarpitHold::create(['edge_request_id' => $request->id, 'tarpit_id' => $tarpit->id, 'reason' => TarpitReason::Rule, 'outcome' => $outcome, 'try' => 2, 'held_ms' => 2000]);
    $hold($request('/curl-held'), $curl, TarpitOutcome::Held);
    $hold($request('/curl-free'), $curl, TarpitOutcome::Free);
    $hold($request('/burst-held'), $bursts, TarpitOutcome::Held);
    $request('/untouched');

    $this->actingAs($user);
    $paths = fn (string $search) => Livewire::test('pages::activity.index')->set('search', $search)->instance()->requests->getCollection()->pluck('path')->sort()->values()->all();

    expect($paths("tarpit = 'curl probes'"))->toBe(['/curl-free', '/curl-held'])
        ->and($paths("tarpit = 'curl probes' AND tarpit.outcome = 'held'"))->toBe(['/curl-held'])
        ->and($paths("tarpit.outcome = 'held'"))->toBe(['/burst-held', '/curl-held'])
        ->and($paths('tarpit IS NOT NULL'))->toBe(['/burst-held', '/curl-free', '/curl-held'])
        ->and($paths('tarpit IS NULL'))->toBe(['/untouched']);
});

test('each tarpit links to its requests on Activity, and names are unique within a team', function () {
    $user = User::factory()->create();
    Tarpit::factory()->for($user->currentTeam)->create(['name' => "curl's probes"]);
    $this->actingAs($user);

    Livewire::test('tarpit::index')
        ->assertSeeHtml(e(route('activity.index', ['q' => "tarpit = 'curl''s probes'"])))
        ->call('create')
        ->set('name', "curl's probes")
        ->set('countsBursts', true)
        ->call('save')
        ->assertHasErrors('name');
});
