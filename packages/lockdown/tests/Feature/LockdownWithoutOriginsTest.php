<?php

use App\Addons\AddonSwitchboard;
use App\Models\AddonSwitch;
use App\Models\Domain;
use App\Models\User;
use App\Support\SharedOrigins;
use Illuminate\Container\Container;
use Livewire\Livewire;
use NiceTry\Lockdown\Models\Lockdown;
use NiceTry\Origins\Models\Origin;

test('without shared origins, locking everything locks every site on its own, and there\'s no pool to show', function () {
    $this->app->instance(SharedOrigins::class, new SharedOrigins(new Container, app(AddonSwitchboard::class)));
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $first = Domain::factory()->for($team)->verified()->create(['hostname' => 'first.example']);
    $second = Domain::factory()->for($team)->verified()->create(['hostname' => 'second.example']);

    $this->actingAs($user);

    Livewire::test('lockdown::index')
        ->assertDontSee('Origins in the pool')
        ->assertDontSeeHtml('data-test="pool-origin"')
        ->call('lockEverything')
        ->assertSet('lockedCount', 2)
        ->assertSet('origins', collect())
        ->call('toggleOrigin', 1)
        ->assertNotFound();

    $lockdown = Lockdown::for($team);

    expect($lockdown->pooledOrigins()->count())->toBe(0)
        ->and($lockdown->covers($first))->toBeTrue()
        ->and($lockdown->covers($second))->toBeTrue();
});

test('with Origins off, its sites lock one by one, and an origin already in the pool keeps its sites locked', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $pooled = Origin::factory()->for($team)->create(['name' => 'Pooled']);
    $other = Origin::factory()->for($team)->create(['name' => 'Other']);
    $pooledSite = Domain::factory()->for($team)->verified()->create(['origin_id' => $pooled->id]);
    $otherSite = Domain::factory()->for($team)->verified()->create(['origin_id' => $other->id]);
    $own = Domain::factory()->for($team)->verified()->create();
    $lockdown = Lockdown::for($team);
    $lockdown->addOrigin($pooled->id);

    AddonSwitch::factory()->create(['slug' => 'origins']);
    $this->actingAs($user);

    expect($lockdown->covers($pooledSite))->toBeTrue()
        ->and($lockdown->covers($otherSite))->toBeFalse();

    Livewire::test('lockdown::index')
        ->assertDontSee('Origins in the pool')
        ->assertDontSee('Pooled')
        ->call('lockEverything')
        ->assertSet('lockedCount', 3);

    expect($lockdown->pooledOrigins()->pluck('origin_id')->all())->toBe([$pooled->id])
        ->and($lockdown->covers($otherSite))->toBeTrue()
        ->and($lockdown->covers($own))->toBeTrue();

    $lockdown->release($pooledSite);

    expect($lockdown->covers($pooledSite))->toBeFalse();
})->skip(fn () => ! class_exists(Origin::class), 'Needs the paid Origins add-on (nicetry/origins).');
