<?php

use App\Enums\GymSessionStatus;
use App\Gym\AttemptListeners;
use App\Gym\Contracts\Obstacle;
use App\Gym\ObstacleRegistry;
use App\Models\GymRoutine;
use App\Models\GymSession;
use App\Models\User;
use Illuminate\Container\Container;
use Livewire\Livewire;

/**
 * Give the gym only the core's obstacles, as an installation without add-ons has.
 */
function withCoreObstaclesOnly(): void
{
    app()->instance(ObstacleRegistry::class, new ObstacleRegistry(array_map(fn (string $class): Obstacle => app($class), config('gym.obstacles'))));
}

test('a routine\'s obstacle whose add-on is gone is skipped, and the visitor carries on', function () {
    withCoreObstaclesOnly();
    $routine = GymRoutine::factory()->create(['obstacles' => [
        ['id' => 'puzzle', 'type' => 'captcha', 'config' => ['captcha' => 'firefly'], 'escalate' => null],
        ['id' => 'queue', 'type' => 'waiting-room', 'config' => [], 'escalate' => 'captcha'],
    ]]);
    $session = GymSession::factory()->create(['team_id' => $routine->team_id, 'escalations' => [['key' => 'queue~captcha', 'type' => 'captcha', 'after' => 'queue']]]);
    $session->routines()->attach($routine);

    $this->get(route('gym.show', $session))->assertOk()->assertSeeHtml('data-obstacle="waiting-room"');

    $alone = GymRoutine::factory()->create(['obstacles' => [['id' => 'puzzle', 'type' => 'captcha', 'config' => [], 'escalate' => null]]]);
    $passed = GymSession::factory()->create(['team_id' => $alone->team_id, 'destination' => 'https://shop.example.com/checkout']);
    $passed->routines()->attach($alone);

    $this->get(route('gym.show', $passed))->assertRedirect('https://shop.example.com/checkout')->assertCookie(GymSession::PASS_COOKIE);
    expect($passed->fresh()->status)->toBe(GymSessionStatus::Cleared);
});

test('the routine editor shows an obstacle whose add-on is gone, offers only what\'s installed, and keeps it on save', function () {
    withCoreObstaclesOnly();
    $user = User::factory()->create();
    $routine = GymRoutine::factory()->for($user->currentTeam)->create(['obstacles' => [['id' => 'puzzle', 'type' => 'captcha', 'config' => ['captcha' => 'firefly'], 'escalate' => null]]]);
    $this->actingAs($user);

    Livewire::test('pages::gym.edit', ['routine' => $routine])
        ->assertSee('Unknown obstacle “captcha”')
        ->assertSee('Not installed any more, so visitors skip it.')
        ->assertDontSeeHtml('wire:click="addObstacle(\'captcha\')"')
        ->call('save')
        ->assertHasNoErrors();

    expect($routine->fresh()->obstacles[0])->toMatchArray(['type' => 'captcha', 'config' => ['captcha' => 'firefly']]);
});

test('with no add-on listening, an attempt is the core\'s alone', function () {
    app()->instance(AttemptListeners::class, new AttemptListeners(new Container));
    $routine = GymRoutine::factory()->obstacles(['screen-code' => []])->create();
    $session = GymSession::factory()->create(['team_id' => $routine->team_id]);
    $session->routines()->attach($routine);
    $step = $routine->obstacles[0]['id'];
    $this->get(route('gym.show', $session));

    $this->postJson(route('gym.attempt', $session), ['step' => $step, 'code' => 'WRONG'])->assertJsonPath('status', 'retry');

    expect($session->fresh()->state($step)->attempts)->toBe(1);
});
