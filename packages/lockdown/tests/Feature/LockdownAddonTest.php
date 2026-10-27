<?php

use App\Addons\Addons;
use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\Team;
use App\Models\User;
use App\Support\WorkspaceMenu;
use App\Support\WorkspaceMenuItem;
use Illuminate\Support\Facades\Gate;
use NiceTry\Lockdown\Models\Lockdown;
use NiceTry\Lockdown\Policies\LockdownPolicy;

test('the add-on is installed as a free one, with its pages and the edge\'s feed where they always were', function () {
    expect(app(Addons::class)->find('lockdown'))
        ->package->toBe('nicetry/lockdown')
        ->name->toBe('Lockdown')
        ->paid->toBeFalse();

    $user = User::factory()->create();
    $team = $user->currentTeam;
    $domain = Domain::factory()->for($team)->create(['hostname' => 'shop.example']);

    expect(route('lockdown.index', $team, false))->toBe("/{$team->slug}/lockdown")
        ->and(route('lockdown.preview', $team, false))->toBe("/{$team->slug}/lockdown/preview")
        ->and(route('lockdown.pass', [$team, $domain], false))->toBe("/{$team->slug}/lockdown/pass/shop.example")
        ->and(route('edge.lockdowns', absolute: false))->toBe('/caddy/lockdowns');

    $this->actingAs($user)->get(route('lockdown.index'))->assertOk()->assertSee('Engage lockdown');
});

test('the cog leads to the lockdown after the core\'s links', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertSeeInOrder([route('domains.index'), route('visitor-data.index'), route('lockdown.index')])
        ->assertSeeHtml('data-test="nav-lockdown"')
        ->assertDontSeeHtml('data-test="workspace-menu-alert"');
});

test('the cog shows a red dot, and the link says On, while the team\'s own lockdown is engaged', function () {
    $user = User::factory()->create();
    Lockdown::factory()->engaged()->create();
    $this->actingAs($user);

    $lockdownLink = fn (): WorkspaceMenuItem => collect(app(WorkspaceMenu::class)->items())->first(fn (WorkspaceMenuItem $item) => $item->route === 'lockdown.index');

    expect(($lockdownLink()->badge)())->toBeNull();
    $this->get(route('dashboard'))->assertDontSeeHtml('data-test="workspace-menu-alert"');

    Lockdown::factory()->engaged()->create(['team_id' => $user->currentTeam->id]);

    expect($lockdownLink())
        ->badgeColor->toBe('red')
        ->alert->toBeTrue()
        ->and(($lockdownLink()->badge)())->toBe('On');
    $this->get(route('dashboard'))->assertSeeHtml('data-test="workspace-menu-alert"');
});

test('a team has its lockdown, and the add-on\'s policy lets whoever manages its domains change it', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $lockdown = Lockdown::for($team);
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    expect($team->lockdown->is($lockdown))->toBeTrue()
        ->and(Gate::getPolicyFor(Lockdown::class))->toBeInstanceOf(LockdownPolicy::class)
        ->and(Gate::forUser($owner)->allows('update', $lockdown))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $lockdown))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $lockdown))->toBeFalse()
        ->and(Gate::forUser($member)->allows('view', $lockdown))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('view', $lockdown))->toBeFalse();
});

test('the edge\'s answer is byte for byte what it has always read', function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
    $team = Team::factory()->create(['id' => 4242]);
    $domain = Domain::factory()->for($team)->verified()->create(['hostname' => 'shop.example']);
    $lockdown = Lockdown::factory()->for($team)->engaged()->create(['secret' => 's3cret', 'page_html' => '<!doctype html><title>Back soon — “soon”</title>']);
    $lockdown->lock($domain);
    $lockdown->allowedIps()->create(['range' => '203.0.113.4']);
    $lockdown->allowedIps()->create(['range' => '2001:db8::/48', 'label' => 'Office']);

    $response = $this->withToken('edge-token')->getJson(route('edge.lockdowns'))->assertOk();

    expect($response->getContent())->toBe('{"lockdowns":[{"team":4242,"hosts":["shop-example.preview.example","shop.example"],"allow":["2001:db8::\/48","203.0.113.4"],"secret":"s3cret","html":"<!doctype html><title>Back soon \u2014 \u201csoon\u201d<\/title>","retry_after":3600}]}')
        ->and($response->headers->get('ETag'))->toBe('"a782719b999660412b9f6d75c0298ab4"');
});

test('each edge that asks is noted, so the page can show when a change has landed', function () {
    config(['services.nicetry_edge.token' => 'edge-token']);

    $this->withToken('edge-token')->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->getJson(route('edge.lockdowns'))->assertOk();

    expect(Lockdown::edgeCheckIns()->keys()->all())->toBe(['198.51.100.9']);
});
