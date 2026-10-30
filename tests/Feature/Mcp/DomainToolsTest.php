<?php

use App\Actions\Domains\LookUpDnsRecords;
use App\Enums\ApiAbility;
use App\Enums\DomainStatus;
use App\Enums\ProtectionMode;
use App\Mcp\Tools\Domains\AddDomain;
use App\Mcp\Tools\Domains\CheckDomain;
use App\Mcp\Tools\Domains\GetOriginSecret;
use App\Mcp\Tools\Domains\ListDomains;
use App\Mcp\Tools\Domains\RemoveDomain;
use App\Mcp\Tools\Domains\SetProtectionMode;
use App\Mcp\Tools\Domains\VerifyDomain;
use App\Mcp\Tools\Whoami;
use App\Models\Domain;
use App\Models\Team;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * Answer DNS lookups with these addresses, for every host, and no TXT records.
 *
 * @param  list<string>  $ipv4
 */
function resolveEverythingTo(array $ipv4): void
{
    test()->mock(LookUpDnsRecords::class, function ($mock) use ($ipv4) {
        $mock->shouldReceive('handle')->andReturn(['ipv4' => $ipv4, 'ipv6' => []]);
        $mock->shouldReceive('txtRecords')->andReturn([]);
    });
}

test('list-domains shows the team\'s domains and nobody else\'s', function () {
    [$token] = apiToken();
    Domain::factory()->for($token->team)->create(['hostname' => 'www.mine.test']);
    Domain::factory()->for(Team::factory())->create(['hostname' => 'www.theirs.test']);

    mcpAs($token)->tool(ListDomains::class)
        ->assertOk()
        ->assertSee('www.mine.test')
        ->assertDontSee('www.theirs.test');
});

test('add-domain puts a domain behind the edge, unverified and observing, and hands over what the origin needs', function () {
    [$token] = apiToken([ApiAbility::ManageDomains]);

    mcpAs($token)->tool(AddDomain::class, ['hostname' => 'https://WWW.Example.test/', 'origin_url' => 'https://www-origin.example.test'])
        ->assertOk()
        ->assertSee(['www.example.test', '_nicetrybot.www.example.test', Domain::ORIGIN_SECRET_HEADER, Domain::edgeHostname()]);

    $domain = Domain::sole();

    expect($domain)
        ->team_id->toBe($token->team_id)
        ->hostname->toBe('www.example.test')
        ->origin_url->toBe('https://www-origin.example.test')
        ->status->toBe(DomainStatus::PendingVerification)
        ->protection_mode->toBe(ProtectionMode::Observe);
});

test('add-domain refuses an origin on the same host, and a domain that\'s already protected', function () {
    [$token] = apiToken([ApiAbility::ManageDomains]);
    Domain::factory()->for(Team::factory())->create(['hostname' => 'taken.example.test']);

    mcpAs($token)->tool(AddDomain::class, ['hostname' => 'loop.example.test', 'origin_url' => 'https://loop.example.test'])
        ->assertHasErrors(['loop back to the edge']);

    mcpAs($token)->tool(AddDomain::class, ['hostname' => 'taken.example.test', 'origin_url' => 'https://origin.example.test'])
        ->assertHasErrors(['already protected']);

    expect(Domain::count())->toBe(1);
});

test('add-domain needs the team permission as well as the token ability', function () {
    [$token] = apiToken([ApiAbility::ManageDomains]);
    $team = Team::factory()->create();
    $team->members()->attach($token->user, ['role' => 'member']);
    $token->update(['team_id' => $team->id]);

    mcpAs($token->fresh())->tools()->assertNotRegistered(AddDomain::class);
});

test('check-domain finds a locked origin: it refuses a request without the secret and serves one with it', function () {
    [$token] = apiToken();
    $domain = Domain::factory()->for($token->team)->create(['hostname' => 'www.locked.test', 'origin_url' => 'https://origin.locked.test']);
    resolveEverythingTo(['203.0.113.10']);
    Http::fake(fn (HttpRequest $request) => $request->header(Domain::ORIGIN_SECRET_HEADER) === [$domain->origin_secret]
        ? Http::response('hello', 200)
        : Http::response('go away', 403));

    mcpAs($token)->tool(CheckDomain::class, ['hostname' => 'www.locked.test'])
        ->assertOk()
        ->assertSee(['"locked":true', 'locked to the edge']);

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://origin.locked.test/'
        && $request->header('Host') === ['www.locked.test']);
});

test('check-domain says when the origin still serves requests that go around the edge', function () {
    [$token] = apiToken();
    Domain::factory()->for($token->team)->create(['hostname' => 'www.open.test', 'origin_url' => 'https://origin.open.test']);
    resolveEverythingTo(['203.0.113.10']);
    Http::fake(fn () => Http::response('hello', 200));

    mcpAs($token)->tool(CheckDomain::class, ['hostname' => 'www.open.test'])
        ->assertOk()
        ->assertSee(['"locked":false', 'anyone can go around the edge']);
});

test('check-domain looks for the TXT record and verifies the domain once it\'s there', function () {
    [$token] = apiToken();
    $domain = Domain::factory()->for($token->team)->create(['hostname' => 'www.mine.test', 'status' => DomainStatus::PendingVerification, 'verified_at' => null]);
    $txtRecords = [];
    test()->mock(LookUpDnsRecords::class, function ($mock) use (&$txtRecords) {
        $mock->shouldReceive('handle')->andReturn(['ipv4' => ['203.0.113.10'], 'ipv6' => []]);
        $mock->shouldReceive('txtRecords')->andReturnUsing(function () use (&$txtRecords) {
            return $txtRecords;
        });
    });
    Http::fake(fn () => Http::response('hello', 200));

    mcpAs($token)->tool(CheckDomain::class, ['hostname' => 'www.mine.test'])
        ->assertOk()
        ->assertSee(['No matching TXT record at _nicetrybot.www.mine.test yet.', 'Add the ownership TXT record, then check again.']);
    expect($domain->fresh()->verified_at)->toBeNull();

    $txtRecords = ['"'.$domain->verificationRecordValue().'"'];

    mcpAs($token)->tool(CheckDomain::class, ['hostname' => 'www.mine.test'])
        ->assertOk()
        ->assertSee('Found the TXT record: the domain is verified.')
        ->assertDontSee('Add the ownership TXT record');
    expect($domain->fresh())->verified_at->not->toBeNull()->status->toBe(DomainStatus::Active);
});

test('check-domain never calls an origin on a private address', function (string $address) {
    [$token] = apiToken();
    Domain::factory()->for($token->team)->create(['hostname' => 'www.inside.test', 'origin_url' => 'http://origin.inside.test']);
    resolveEverythingTo([$address]);
    Http::fake();

    mcpAs($token)->tool(CheckDomain::class, ['hostname' => 'www.inside.test'])
        ->assertOk()
        ->assertSee('doesn\'t resolve to a public address');

    Http::assertNothingSent();
})->with(['127.0.0.1', '10.0.0.5', '169.254.169.254', '192.168.1.1']);

test('verify-domain verifies one of the team\'s domains once its TXT record is in DNS', function () {
    [$token] = apiToken([ApiAbility::ManageDomains]);
    $domain = Domain::factory()->for($token->team)->create(['status' => DomainStatus::PendingVerification, 'verified_at' => null]);
    test()->mock(LookUpDnsRecords::class, fn ($mock) => $mock->shouldReceive('txtRecords')->with($domain->verificationRecordName())->andReturn([$domain->verificationRecordValue()]));

    mcpAs($token)->tool(VerifyDomain::class, ['hostname' => $domain->hostname])->assertOk()->assertSee('"verified":true');

    expect($domain->fresh()->verified_at)->not->toBeNull();
});

test('verify-domain reports a missing TXT record and leaves the domain unverified', function () {
    [$token] = apiToken([ApiAbility::ManageDomains]);
    $domain = Domain::factory()->for($token->team)->create(['hostname' => 'www.mine.test', 'status' => DomainStatus::PendingVerification, 'verified_at' => null]);
    test()->mock(LookUpDnsRecords::class, fn ($mock) => $mock->shouldReceive('txtRecords')->andReturn([]));

    mcpAs($token)->tool(VerifyDomain::class, ['hostname' => 'www.mine.test'])
        ->assertHasErrors(['No matching TXT record at _nicetrybot.www.mine.test yet.']);

    expect($domain->fresh()->verified_at)->toBeNull();
});

test('get-origin-secret and set-protection-mode only reach the token\'s own team', function () {
    [$token] = apiToken([ApiAbility::ManageDomains]);
    $theirs = Domain::factory()->for(Team::factory())->create(['hostname' => 'www.theirs.test']);

    mcpAs($token)->tool(GetOriginSecret::class, ['hostname' => 'www.theirs.test'])->assertHasErrors(['No domain called www.theirs.test']);
    mcpAs($token)->tool(SetProtectionMode::class, ['hostname' => 'www.theirs.test', 'mode' => 'enforce'])->assertHasErrors(['No domain called www.theirs.test']);

    expect($theirs->fresh()->protection_mode)->toBe(ProtectionMode::Observe);
});

test('set-protection-mode changes the mode, and get-origin-secret hands over the header', function () {
    [$token] = apiToken([ApiAbility::ManageDomains]);
    $domain = Domain::factory()->for($token->team)->create();

    mcpAs($token)->tool(SetProtectionMode::class, ['hostname' => $domain->hostname, 'mode' => 'enforce'])->assertOk();
    mcpAs($token)->tool(GetOriginSecret::class, ['hostname' => $domain->hostname])->assertOk()->assertStructuredContent([
        'hostname' => $domain->hostname,
        'origin_url' => $domain->origin_url,
        'header' => 'X-NiceTry-Origin-Secret',
        'value' => $domain->origin_secret,
    ]);

    expect($domain->fresh()->protection_mode)->toBe(ProtectionMode::Enforce);
});

test('remove-domain takes one of the team\'s domains out, and can\'t reach another team\'s', function () {
    [$token] = apiToken([ApiAbility::ManageDomains]);
    Domain::factory()->for($token->team)->create(['hostname' => 'www.mine.test']);
    $theirs = Domain::factory()->for(Team::factory())->create(['hostname' => 'www.theirs.test']);

    mcpAs($token)->tool(RemoveDomain::class, ['hostname' => 'https://WWW.Mine.test/'])->assertOk()->assertSee('www.mine.test');
    mcpAs($token)->tool(RemoveDomain::class, ['hostname' => 'www.theirs.test'])->assertHasErrors(['No domain called www.theirs.test']);

    expect(Domain::pluck('id')->all())->toBe([$theirs->id]);
});

test('remove-domain is only offered to a token that may manage domains', function () {
    [$token] = apiToken();

    mcpAs($token)->tools()->assertNotRegistered(RemoveDomain::class);
});

test('whoami names the token\'s team, its user, and what it may do', function () {
    [$token] = apiToken([ApiAbility::ManageDomains]);

    mcpAs($token)->tool(Whoami::class)
        ->assertOk()
        ->assertSee([$token->team->slug, $token->user->email, 'domains']);
});
