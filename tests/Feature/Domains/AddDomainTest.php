<?php

use App\Enums\DomainStatus;
use App\Enums\HostHeaderMode;
use App\Enums\ProtectionMode;
use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\Team;
use App\Models\User;
use App\Support\AddDomainWithAiPrompt;
use Livewire\Livewire;

test('owners can add a domain origin that starts unverified in observe mode, answering as nginx', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('add-domain-modal')
        ->set('hostname', 'https://Claims.Example.com/claim?ref=email')
        ->set('originUrl', 'https://sites.example.net/')
        ->set('hostHeaderMode', HostHeaderMode::Rewrite->value)
        ->set('forwardedHostHeader', 'User-Custom-Domain')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertRedirect(route('domains.show', ['current_team' => $user->currentTeam->slug, 'domain' => 'claims.example.com']));

    $domain = Domain::sole();

    expect($domain->team_id)->toBe($user->currentTeam->id)
        ->and($domain->hostname)->toBe('claims.example.com')
        ->and($domain->origin_url)->toBe('https://sites.example.net')
        ->and($domain->status)->toBe(DomainStatus::PendingVerification)
        ->and($domain->protection_mode)->toBe(ProtectionMode::Observe)
        ->and($domain->forwarded_host_header)->toBe('User-Custom-Domain')
        ->and($domain->server_header)->toBe('nginx')
        ->and($domain->verified_at)->toBeNull();
});

test('members cannot add domains', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $this->actingAs($member);

    Livewire::test('add-domain-modal')
        ->set('hostname', 'claims.example.com')
        ->set('originUrl', 'https://sites.example.net')
        ->call('addDomain')
        ->assertForbidden();

    expect(Domain::count())->toBe(0);
});

test('a domain that is already protected cannot be added again', function () {
    Domain::factory()->create(['hostname' => 'claims.example.com']);

    $this->actingAs(User::factory()->create());

    Livewire::test('add-domain-modal')
        ->set('hostname', 'claims.example.com')
        ->set('originUrl', 'https://sites.example.net')
        ->call('addDomain')
        ->assertHasErrors(['hostname' => 'unique']);
});

test('the origin must be a different host than the domain', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('add-domain-modal')
        ->set('hostname', 'claims.example.com')
        ->set('originUrl', 'https://claims.example.com')
        ->call('addDomain')
        ->assertHasErrors('originUrl');

    expect(Domain::count())->toBe(0);
});

test('invalid hostnames are rejected', function (string $hostname) {
    $this->actingAs(User::factory()->create());

    Livewire::test('add-domain-modal')
        ->set('hostname', $hostname)
        ->set('originUrl', 'https://sites.example.net')
        ->call('addDomain')
        ->assertHasErrors('hostname');
})->with([
    'no top-level domain' => 'localhost',
    'underscore' => 'claims_site.example.com',
    'leading dash' => '-claims.example.com',
]);

test('a new domain\'s forwarded host headers are checked', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('add-domain-modal')
        ->set('hostname', 'claims.example.com')
        ->set('originUrl', 'https://sites.example.net')
        ->set('forwardedHostHeader', 'X-Domain, X Site')
        ->call('addDomain')
        ->assertHasErrors(['forwardedHostHeader' => 'Header names may only contain letters, numbers, and dashes. Separate several with commas.']);

    expect(Domain::count())->toBe(0);
});

test('Add with AI copies a prompt that names this installation\'s MCP server and token page, and no token', function () {
    $user = User::factory()->create();
    [, $plainText] = apiToken(user: $user);
    $this->actingAs($user);

    $prompt = app(AddDomainWithAiPrompt::class)->render();

    expect($prompt)->toContain(route('mcp'), route('admin.tokens'), Domain::ORIGIN_SECRET_HEADER, 'add-domain', 'check-domain', 'www-origin.myproject.com')
        ->not->toContain($plainText);

    Livewire::test('add-domain-modal')
        ->assertSeeHtml('data-test="add-domain-with-ai"')
        ->assertSeeHtml('data-test="add-domain-with-ai-steps"')
        ->assertSeeHtml(trim(json_encode('## 4. Add the domain to the edge'), '"'));
});

test('the Add with AI prompt covers what trips agents up on a real deploy', function () {
    $prompt = app(AddDomainWithAiPrompt::class)->render();

    expect($prompt)->toContain(
        'in my own terminal',            // the token never goes through the chat
        'host_header_mode: rewrite',     // hosts that route by Host refuse the public hostname
        'the DNS record the host gives', // not always where the public hostname points
        'up to an hour',                 // new hostnames take a while to answer
        'not X-Forwarded-Host',          // which anyone can send
        'NICETRY_LOCKED_HOSTS',          // going live is a config change
        'or ask me to',                  // the agent may not be allowed to deploy
        'create-rule',                   // webhooks and APIs need allow rules before enforcing
    );
});
