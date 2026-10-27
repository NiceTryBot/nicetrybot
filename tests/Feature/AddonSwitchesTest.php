<?php

use App\Actions\Domains\MergeDomains;
use App\Addons\Addons;
use App\Addons\AddonSwitchboard;
use App\Gym\ObstacleRegistry;
use App\Gym\RoutineTemplates;
use App\Models\AddonSwitch;
use App\Models\Domain;
use App\Models\GymRoutine;
use App\Models\GymSession;
use App\Models\Team;
use App\Models\User;
use App\Support\RuleFields;
use App\Support\RuleTemplates;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\Addons\Example\ExampleServiceProvider;
use Tests\Fixtures\Addons\Example\Models\Bookmark;

beforeEach(function () {
    Bookmark::createTable();
    app()->register(ExampleServiceProvider::class);
    app()->forgetInstance(ObstacleRegistry::class);
    app('router')->getRoutes()->refreshNameLookups();
    config(['services.nicetry_edge.token' => 'edge-token']);
});

test('an add-on turned off stops its pages, links, slots, obstacles, templates and edge feeds, and comes back when turned on', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $domain = Domain::factory()->for($team)->verified()->create(['hostname' => 'sign.example']);
    Bookmark::create(['team_id' => $team->id, 'domain_id' => $domain->id, 'path' => '/locked']);
    $routine = GymRoutine::factory()->for($team)->obstacles(['knock' => []])->create();
    $session = GymSession::factory()->create(['team_id' => $team->id]);
    $session->routines()->attach($routine);

    $switch = AddonSwitch::factory()->create(['slug' => 'example']);
    $this->actingAs($user);

    $this->get(route('example.settings'))->assertNotFound();
    $this->get(route('profile.edit'))->assertOk()->assertDontSee(route('example.settings'));
    $this->get(route('dashboard'))->assertOk()
        ->assertDontSeeHtml('data-test="example-body-end"')
        ->assertDontSeeHtml('data-test="nav-example"');
    // The routine's only obstacle is off, so there's nothing to stop the visitor.
    $this->get(route('gym.show', $session))->assertRedirect()->assertDontSeeHtml('data-test="example-knock"');

    expect(app(ObstacleRegistry::class)->find('knock'))->toBeNull()
        ->and(app(ObstacleRegistry::class)->all())->not->toHaveKey('knock')
        ->and(app(RuleTemplates::class)->all())->not->toHaveKey('bookmark-hammering')
        ->and(RoutineTemplates::all())->not->toHaveKey('bookmark-knock');

    $this->withToken('edge-token')->getJson(route('edge.captures.index'))->assertJsonPath('tags', []);
    expect($this->withToken('edge-token')->getJson(route('edge.lockdowns'))->json('lockdowns'))->toBe([]);

    $switch->delete();

    $this->get(route('example.settings'))->assertOk();
    $this->get(route('dashboard'))->assertSeeHtml('data-test="example-body-end"')->assertSeeHtml('data-test="nav-example"');

    expect(app(ObstacleRegistry::class)->find('knock'))->not->toBeNull()
        ->and(app(RuleTemplates::class)->all())->toHaveKey('bookmark-hammering');

    $this->withToken('edge-token')->getJson(route('edge.captures.index'))->assertJsonCount(1, 'tags');
    expect($this->withToken('edge-token')->getJson(route('edge.lockdowns'))->json('lockdowns'))->toHaveCount(1);
});

test('an add-on turned off keeps its rule fields and its rows moving with their domain', function () {
    AddonSwitch::factory()->create(['slug' => 'example']);
    $team = Team::factory()->create();
    $bare = Domain::factory()->for($team)->verified()->create(['hostname' => 'both.example.com']);
    $www = Domain::factory()->for($team)->verified()->create(['hostname' => 'www.both.example.com']);
    $bookmark = Bookmark::create(['team_id' => $team->id, 'domain_id' => $www->id, 'path' => '/login']);

    app(MergeDomains::class)->handle($www, $bare);

    expect(app(RuleFields::class)->names())->toContain('bookmark')
        ->and($bookmark->fresh()->domain_id)->toBe($bare->id);
});

test('a switch another server wrote to the shared cache is read on the next request', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('example.settings'))->assertOk();

    // Another server turned it off: the row and the cache it cleared there, then refilled.
    Cache::forever(AddonSwitchboard::CACHE_KEY, ['example']);
    app()->forgetScopedInstances();

    $this->get(route('example.settings'))->assertNotFound();
});

test('the core and add-ons that are on stay on when another add-on is off', function () {
    AddonSwitch::factory()->create(['slug' => 'example']);

    expect(app(AddonSwitchboard::class)->isOn(null))->toBeTrue()
        ->and(app(AddonSwitchboard::class)->isOn('fingerprints'))->toBeTrue()
        ->and(app(AddonSwitchboard::class)->isOn('example'))->toBeFalse();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('fingerprints.index'));
})->skip(fn () => ! app(Addons::class)->has('fingerprints'), 'Needs the paid Fingerprints add-on (nicetry/fingerprints).');
