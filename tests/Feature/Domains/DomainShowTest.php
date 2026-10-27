<?php

use App\Actions\Domains\LookUpDnsRecords;
use App\Enums\DomainStatus;
use App\Enums\HstsPolicy;
use App\Enums\ProtectionMode;
use App\Enums\TeamRole;
use App\Jobs\VerifyDomainOwnershipJob;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\mock;

test('team members can view their domain', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'claims.example.com']);

    $this->actingAs($user)
        ->get(route('domains.show', $domain))
        ->assertOk()
        ->assertSee('claims.example.com')
        ->assertSee($domain->verificationRecordValue());
});

test('another team\'s domain returns 404', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->create();

    $this->actingAs($user)
        ->get(route('domains.show', $domain))
        ->assertNotFound();
});

test('checking the TXT record verifies the domain and starts protection once the record is in DNS', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'sign.example']);

    mock(LookUpDnsRecords::class)
        ->shouldReceive('txtRecords')
        ->once()
        ->with('_nicetrybot.sign.example')
        ->andReturn(['v=spf1 -all', '"'.strtoupper($domain->verificationRecordValue()).'"']);

    $this->actingAs($user);

    Livewire::test('pages::domains.show', ['domain' => $domain])
        ->call('verify')
        ->assertSet('isCheckingTxtRecord', false)
        ->assertSet('txtRecordProblem', null);

    expect($domain->fresh())
        ->status->toBe(DomainStatus::Active)
        ->verified_at->not->toBeNull();
});

test('checking the TXT record leaves the domain unverified while the record is missing or wrong', function (array $records) {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'sign.example']);

    mock(LookUpDnsRecords::class)->shouldReceive('txtRecords')->once()->andReturn($records);

    $this->actingAs($user);

    Livewire::test('pages::domains.show', ['domain' => $domain])
        ->call('verify')
        ->assertSet('isCheckingTxtRecord', false)
        ->assertSee('No matching TXT record at _nicetrybot.sign.example yet.');

    expect($domain->fresh())
        ->status->toBe(DomainStatus::PendingVerification)
        ->verified_at->toBeNull();
})->with([
    'no record' => [[]],
    'another domain\'s token' => [['nicetrybot-verify=someoneelse']],
]);

test('the TXT record is looked up on the queue while the page polls for the outcome', function () {
    Queue::fake();
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();

    $this->actingAs($user);

    $page = Livewire::test('pages::domains.show', ['domain' => $domain])
        ->call('verify')
        ->assertSet('isCheckingTxtRecord', true)
        ->assertSeeHtml('wire:poll.2s="pollTxtRecord"');

    Queue::assertPushed(VerifyDomainOwnershipJob::class, fn (VerifyDomainOwnershipJob $job) => $job->domain->is($domain));

    $this->travel(2)->minutes();

    $page->call('pollTxtRecord')
        ->assertSet('isCheckingTxtRecord', false)
        ->assertSee('The check didn\'t finish.')
        ->assertDontSeeHtml('wire:poll.2s="pollTxtRecord"');
});

test('with DNS verification off, checking the TXT record verifies without a lookup', function () {
    config(['nicetry.verify_dns' => false]);
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();

    mock(LookUpDnsRecords::class)->shouldNotReceive('txtRecords');

    $this->actingAs($user);

    Livewire::test('pages::domains.show', ['domain' => $domain])->call('verify');

    expect($domain->fresh()->verified_at)->not->toBeNull();
});

test('checking DNS reports whether every visitor reaches the edge', function (array $ipv4, array $ipv6, bool $isPointed, string $summary) {
    config(['edge.addresses' => ['192.0.2.10']]);
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create(['hostname' => 'sign.example']);

    mock(LookUpDnsRecords::class)
        ->shouldReceive('handle')
        ->once()
        ->with('sign.example')
        ->andReturn(['ipv4' => $ipv4, 'ipv6' => $ipv6]);

    $this->actingAs($user);

    Livewire::test('pages::domains.show', ['domain' => $domain])
        ->call('checkEdgeDns')
        ->assertSet('isEdgeDnsPointed', $isPointed)
        ->assertSet('edgeDnsSummary', $summary);

    expect($domain->fresh()->edge_dns_pointed_at !== null)->toBe($isPointed);
})->with([
    'pointed at the edge' => [['192.0.2.10'], [], true, 'sign.example points at the edge.'],
    'still on the old host' => [['198.51.100.26', '192.0.2.10'], [], false, 'sign.example still resolves to 198.51.100.26. Replace those records with the ones above.'],
    'IPv6 records left behind' => [['192.0.2.10'], ['2001:db8::1'], false, 'sign.example also has IPv6 (AAAA) records: 2001:db8::1. Remove them, or IPv6 visitors will skip the edge.'],
    'nothing resolves yet' => [[], [], false, 'No records found for sign.example yet. DNS changes can take a few minutes to show up.'],
]);

test('the DNS step collapses once DNS points at the edge', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create(['edge_dns_pointed_at' => now()]);

    $this->actingAs($user)
        ->get(route('domains.show', $domain))
        ->assertOk()
        ->assertSee('Pointed at the edge')
        ->assertDontSee('data-test="check-edge-dns"', escape: false);
});

test('changing the protection mode is saved', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create();

    $this->actingAs($user);

    Livewire::test('pages::domains.show', ['domain' => $domain])
        ->set('protectionMode', ProtectionMode::Enforce->value)
        ->assertHasNoErrors();

    expect($domain->fresh()->protection_mode)->toBe(ProtectionMode::Enforce);
});

test('members cannot change a domain', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);
    $domain = Domain::factory()->for($team)->verified()->create();

    $this->actingAs($member);

    Livewire::test('pages::domains.show', ['domain' => $domain])
        ->set('protectionMode', ProtectionMode::Enforce->value)
        ->assertForbidden();

    expect($domain->fresh()->protection_mode)->toBe(ProtectionMode::Observe);
});

test('origin settings are validated and saved', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'claims.example.com']);

    $this->actingAs($user);

    Livewire::test('pages::domains.show', ['domain' => $domain])
        ->set('originUrl', 'not a url')
        ->call('saveOriginSettings')
        ->assertHasErrors('originUrl')
        ->set('originUrl', 'https://new-origin.example.net/')
        ->set('originTimeoutSeconds', 45)
        ->set('forwardedHostHeader', 'X-Site, X-Site')
        ->call('saveOriginSettings')
        ->assertHasErrors(['forwardedHostHeader' => 'List each header only once.'])
        ->set('forwardedHostHeader', "X\u{2011}Forwarded-Host \u{00A0}, User-Custom-Domain")
        ->set('hstsPolicy', HstsPolicy::On->value)
        ->set('cacheAssets', true)
        ->set('servesWww', true)
        ->call('saveOriginSettings')
        ->assertHasNoErrors();

    expect($domain->fresh())
        ->origin_url->toBe('https://new-origin.example.net')
        ->origin_timeout_seconds->toBe(45)
        ->forwarded_host_header->toBe('X-Forwarded-Host, User-Custom-Domain')
        ->hsts_policy->toBe(HstsPolicy::On)
        ->cache_assets->toBeTrue()
        ->serves_www->toBeTrue();
});

test('pausing a verified domain stops protection until it is resumed', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create();

    $this->actingAs($user);

    $component = Livewire::test('pages::domains.show', ['domain' => $domain])->call('togglePause');

    expect($domain->fresh()->status)->toBe(DomainStatus::Paused);

    $component->call('togglePause');

    expect($domain->fresh()->status)->toBe(DomainStatus::Active);
});

test('sample traffic is only loaded into a domain with no requests', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create();

    $this->actingAs($user);

    $component = Livewire::test('pages::domains.show', ['domain' => $domain])->call('loadSampleTraffic');

    $loaded = EdgeRequest::where('domain_id', $domain->id)->count();

    $component->call('loadSampleTraffic');

    expect($loaded)->toBeGreaterThan(0)
        ->and(EdgeRequest::where('domain_id', $domain->id)->count())->toBe($loaded);
});

test('removing a domain deletes it and its request history', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->create();

    $this->actingAs($user);

    Livewire::test('pages::domains.show', ['domain' => $domain])
        ->call('deleteDomain')
        ->assertRedirect(route('domains.index', ['current_team' => $user->currentTeam->slug]));

    expect(Domain::count())->toBe(0)
        ->and(EdgeRequest::count())->toBe(0);
});
