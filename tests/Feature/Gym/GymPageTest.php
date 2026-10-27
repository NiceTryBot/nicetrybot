<?php

use App\Enums\GymSessionStatus;
use App\Models\GymRoutine;
use App\Models\GymSession;
use App\Models\User;
use Livewire\Livewire;

test('the gym lists routines in stacking order with what each one does', function () {
    $user = User::factory()->create();
    GymRoutine::factory()->for($user->currentTeam)->obstacles(['waiting-room' => []])->create(['name' => 'Second', 'position' => 2]);
    GymRoutine::factory()->for($user->currentTeam)->create(['name' => 'First', 'position' => 1]);
    GymRoutine::factory()->create(['name' => 'Another team\'s routine']);
    $this->actingAs($user);

    $this->get(route('gym.index'))
        ->assertSeeInOrder(['First', 'Visitors whose network is DigitalOcean or Hetzner.', 'Proof of work', 'Second', 'Waiting room'])
        ->assertDontSee('Another team\'s routine');
});

test('dragging a routine changes the stacking order', function () {
    $user = User::factory()->create();
    [$first, $second, $third] = GymRoutine::factory()->for($user->currentTeam)->count(3)->sequence(['position' => 1], ['position' => 2], ['position' => 3])->create();
    $this->actingAs($user);

    Livewire::test('pages::gym.index')->call('sortRoutine', $third->id, 0);

    expect($user->currentTeam->gymRoutines()->pluck('id')->all())->toBe([$third->id, $first->id, $second->id]);
});

test('the numbers count real visits only, and a stale visit has given up', function () {
    $user = User::factory()->create();
    GymSession::factory()->for($user->currentTeam)->cleared()->create(['hashes' => 3_000_000]);
    GymSession::factory()->for($user->currentTeam)->create(['last_seen_at' => now()->subHour()]);
    GymSession::factory()->for($user->currentTeam)->create(['status' => GymSessionStatus::TurnedAway]);
    GymSession::factory()->for($user->currentTeam)->preview()->cleared()->create(['hashes' => 9_000_000]);
    $this->actingAs($user);

    $stats = Livewire::test('pages::gym.index')->instance()->stats;

    expect($stats)->toBe(['entered' => 3, 'cleared' => 1, 'turned_away' => 1, 'gave_up' => 1, 'hashes' => 3_000_000]);
});

test('the tester shows which routines a visitor would stack into', function () {
    $user = User::factory()->create();
    GymRoutine::factory()->for($user->currentTeam)->create(['name' => 'Datacenter waiting room']);
    GymRoutine::factory()->for($user->currentTeam)->entering([['field' => 'country', 'operator' => 'in', 'values' => ['FR']]])->create(['name' => 'French visitors']);
    $this->actingAs($user);

    Livewire::test('pages::gym.index')
        ->set('testIp', '203.0.113.7')
        ->set('testAsn', 14061)
        ->assertSee('Enters 1 routine:')
        ->assertSee('Datacenter waiting room')
        ->set('testAsn', null)
        ->assertSee('No routine wants this visitor.');
});

test('members can switch nothing on or off', function () {
    $member = User::factory()->create();
    $routine = GymRoutine::factory()->create();
    $routine->team->members()->attach($member, ['role' => 'member']);
    $member->switchTeam($routine->team);
    $this->actingAs($member);

    Livewire::test('pages::gym.index')
        ->assertDontSee('New routine')
        ->call('toggle', $routine->id)
        ->assertForbidden();

    expect($routine->fresh()->enabled)->toBeTrue();
});
