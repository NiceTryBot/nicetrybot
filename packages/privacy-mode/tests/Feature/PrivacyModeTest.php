<?php

use App\Addons\Addons;
use App\Addons\AddonSwitchboard;
use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\Team;
use App\Models\User;
use App\Support\PrivacyHostname;
use App\Support\SharedOrigins;
use Illuminate\Container\Container;
use Livewire\Livewire;
use NiceTry\Origins\Models\Origin;
use NiceTry\PrivacyMode\Support\HostnameMap;
use NiceTry\PrivacyMode\Support\Script;

test('privacy mode is a free add-on', function () {
    expect(app(Addons::class)->find('privacy-mode'))
        ->package->toBe('nicetry/privacy-mode')
        ->name->toBe('Privacy mode')
        ->paid->toBeFalse();
});

test('the toggle sits before the cog, and every page with the app\'s head loads the script', function () {
    $script = route('privacy.script', ['v' => Script::version()]);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<script type="module" src="'.e($script).'" data-navigate-track></script>', false)
        ->assertDontSee('data-test="privacy-toggle"', false);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<script type="module" src="'.e($script).'" data-navigate-track></script>', false)
        ->assertSee("localStorage.getItem('nicetrybot:privacy-mode')", false)
        ->assertSeeInOrder(['data-test="privacy-toggle"', 'data-test="workspace-menu"'], false);
});

test('the script is served from the add-on, kept for good at its version and without a session', function () {
    $this->get(route('privacy.script', ['v' => Script::version()]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
        ->assertCookieMissing(config('session.cookie'))
        ->assertSee('window.privacyMode = {', false)
        ->assertSee("addEventListener('livewire:navigated'", false);

    $this->get(route('privacy.script', ['v' => 'stale']))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-cache, public');
});

test('pages hand privacy mode the current team\'s domains, their previews and origin hosts with their stand-ins', function () {
    $user = User::factory()->create();
    Domain::factory()->for($user->currentTeam)->create([
        'hostname' => 'claims.example.com',
        'privacy_hostname' => 'invisible-pony.hidden-domain.com',
        'origin_url' => 'https://backend.claims-origin.test:8443',
    ]);
    Origin::factory()->for($user->currentTeam)->create(['origin_url' => 'http://10.0.0.5']);
    $otherTeamsDomain = Domain::factory()->create(['hostname' => 'rival.example.com']);

    $script = route('privacy.hostnames', ['v' => HostnameMap::versionFor($user->currentTeam)]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<script src="'.e($script).'"></script>', false)
        ->assertSee('data-test="privacy-toggle"', false);

    $this->get($script)
        ->assertOk()
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private')
        ->assertSee(base64_encode(json_encode([
            'claims.example.com' => 'invisible-pony.hidden-domain.com',
            'claims-example-com.preview.example' => 'invisible-pony-hidden-domain-com.preview.example',
            'backend.claims-origin.test' => HostnameMap::forOrigin('backend.claims-origin.test'),
        ])), false)
        ->assertDontSee('backend.claims-origin.test', false)
        ->assertDontSee('10.0.0.5')
        ->assertDontSee($otherTeamsDomain->hostname);
})->skip(fn () => ! class_exists(Origin::class), 'Needs the paid Origins add-on (nicetry/origins).');

test('the privacy host map gets a new version when a domain changes', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $version = HostnameMap::versionFor($user->currentTeam);

    $this->travel(1)->second();
    $domain->update(['privacy_hostname' => 'sneaky-otter.hidden-domain.com']);

    expect(HostnameMap::versionFor($user->currentTeam))->not->toBe($version);

    expect(json_decode(base64_decode(HostnameMap::encodedMapFor($user->currentTeam)), true))
        ->toHaveKey($domain->hostname, 'sneaky-otter.hidden-domain.com');
});

test('the domain page shows its stand-in and managers can pick another', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['privacy_hostname' => 'invisible-pony.hidden-domain.com']);

    $this->actingAs($user)
        ->get(route('domains.show', $domain))
        ->assertSee('invisible-pony.hidden-domain.com')
        ->assertSeeHtml('data-test="shuffle-privacy-hostname"');

    Livewire::test('pages::domains.show', ['domain' => $domain])
        ->assertSeeLivewire('privacy-mode::domain-stand-in');

    Livewire::test('privacy-mode::domain-stand-in', ['domain' => $domain])
        ->call('shufflePrivacyHostname')
        ->assertDispatched('privacy-hostname-changed', hostname: $domain->hostname, privacyHostname: $domain->fresh()->privacy_hostname);

    expect($domain->fresh()->privacy_hostname)
        ->not->toBe('invisible-pony.hidden-domain.com')
        ->toEndWith('.'.PrivacyHostname::SUFFIX);
});

test('members cannot pick another stand-in', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);
    $domain = Domain::factory()->for($team)->create(['privacy_hostname' => 'invisible-pony.hidden-domain.com']);

    $this->actingAs($member);

    Livewire::test('privacy-mode::domain-stand-in', ['domain' => $domain])
        ->assertDontSeeHtml('data-test="shuffle-privacy-hostname"')
        ->call('shufflePrivacyHostname')
        ->assertForbidden();

    expect($domain->fresh()->privacy_hostname)->toBe('invisible-pony.hidden-domain.com');
});

test('without shared origins, the host map holds the domains\' own origin hosts', function () {
    $this->app->instance(SharedOrigins::class, new SharedOrigins(new Container, app(AddonSwitchboard::class)));
    $user = User::factory()->create();
    Domain::factory()->for($user->currentTeam)->create([
        'hostname' => 'claims.example.com',
        'privacy_hostname' => 'invisible-pony.hidden-domain.com',
        'origin_url' => 'https://backend.claims-origin.test:8443',
    ]);

    expect(HostnameMap::mapFor($user->currentTeam))->toBe([
        'claims.example.com' => 'invisible-pony.hidden-domain.com',
        'claims-example-com.preview.example' => 'invisible-pony-hidden-domain-com.preview.example',
        'backend.claims-origin.test' => HostnameMap::forOrigin('backend.claims-origin.test'),
    ])->and(HostnameMap::versionFor($user->currentTeam))->toHaveLength(16);
});

test('a shared origin\'s hosts are hidden too, its authorize URL and host header among them', function () {
    $user = User::factory()->create();
    Origin::factory()->for($user->currentTeam)->authorizing('https://gate.fleet-origin.test/authorize')->create([
        'origin_url' => 'https://sites.fleet-origin.test',
        'origin_host_header' => 'tenant.fleet-origin.test',
    ]);

    expect(HostnameMap::mapFor($user->currentTeam))->toBe([
        'sites.fleet-origin.test' => HostnameMap::forOrigin('sites.fleet-origin.test'),
        'gate.fleet-origin.test' => HostnameMap::forOrigin('gate.fleet-origin.test'),
        'tenant.fleet-origin.test' => HostnameMap::forOrigin('tenant.fleet-origin.test'),
    ]);
})->skip(fn () => ! class_exists(Origin::class), 'Needs the paid Origins add-on (nicetry/origins).');
