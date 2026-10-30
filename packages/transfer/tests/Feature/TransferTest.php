<?php

use App\Enums\ApiAbility;
use App\Enums\DomainStatus;
use App\Enums\GymExit;
use App\Enums\ProtectionMode;
use App\Enums\TeamRole;
use App\Models\AddonSwitch;
use App\Models\Administrator;
use App\Models\ApiToken;
use App\Models\Domain;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use NiceTry\Origins\Models\DomainProtectionOverride;
use NiceTry\Origins\Models\Origin;
use NiceTry\Origins\Models\OriginZone;
use NiceTry\Transfer\Actions\ExportOrigin;
use NiceTry\Transfer\Actions\ImportOrigin;
use NiceTry\Transfer\InvalidTransferBundle;
use NiceTry\Transfer\TransferBundle;
use NiceTry\Transfer\TransferSource;

beforeEach(function () {
    if (! class_exists(Origin::class)) {
        $this->markTestSkipped('Needs the paid Origins add-on (nicetry/origins).');
    }

    Http::preventStrayRequests();
});

/**
 * A team's origin to move: a group that refuses www., three sites (one keeping its own mode), a rule,
 * and a routine that waits for that rule's verdict.
 *
 * @return array{0: User, 1: Origin}
 */
function originToMove(?User $user = null, string $prefix = ''): array
{
    $user ??= User::factory()->create();
    $team = $user->currentTeam;

    $origin = Origin::factory()->for($team)->authorizing('https://sites.example.net/authorize-domains')->create([
        'name' => 'Fleet',
        'origin_url' => 'https://sites.example.net',
        'protection_mode' => ProtectionMode::Observe,
        'origin_secret' => 'origin-secret-0123456789abcdef',
    ]);
    OriginZone::factory()->for($origin)->create(['suffix' => 'quiet.example', 'serves_www' => false]);

    foreach (['alpha.example', 'beta.example', 'shop.quiet.example'] as $hostname) {
        Domain::factory()->for($team)->verified()->create(['origin_id' => $origin->id, 'hostname' => $prefix.$hostname, ...$origin->domainSettings()]);
    }

    $beta = Domain::query()->where('hostname', $prefix.'beta.example')->sole();
    DomainProtectionOverride::query()->create(['domain_id' => $beta->id, 'protection_mode' => ProtectionMode::Enforce]);
    $beta->update(['protection_mode' => ProtectionMode::Enforce]);

    $rule = EdgeRule::factory()->for($team)->create(['name' => 'Probe scanners']);
    GymRoutine::factory()->for($team)->create([
        'name' => 'After a ban',
        'conditions' => [['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]]],
    ]);

    return [$user, $origin];
}

/**
 * The origin as a bundle, then the origin, its sites and its team's rules and routines gone, as though
 * the bundle had come from another installation.
 *
 * @return array<string, mixed>
 */
function bundleFromElsewhere(Origin $origin): array
{
    $bundle = TransferBundle::validate(json_decode(json_encode(app(ExportOrigin::class)->handle($origin)), true));

    $team = $origin->team;
    Domain::query()->where('origin_id', $origin->id)->delete();
    $origin->zones()->delete();
    $origin->delete();
    $team->gymRoutines()->delete();
    $team->edgeRules()->delete();

    return $bundle;
}

/**
 * A team admin on a team of their own, as on the panel the origin moves to.
 */
function destinationAdmin(): User
{
    return Administrator::factory()->create()->user;
}

/**
 * The panel the origin is pulled from answers on old.example.org, which resolves to a public address.
 */
function pullFromOldPanel(array $bundle): void
{
    app()->bind(TransferSource::class, fn () => new TransferSource(fn (string $host) => ['203.0.113.10']));

    Http::fake([
        'https://old.example.org/transfer/v1/origins' => Http::response(['origins' => [['id' => 7, 'name' => 'Fleet', 'origin_url' => 'https://sites.example.net', 'sites' => 3]]]),
        'https://old.example.org/transfer/v1/origins/7' => Http::response($bundle),
    ]);
}

test('an origin exports with its groups, sites, own modes, secret, rules and routines, and nothing more', function () {
    [, $origin] = originToMove();

    $bundle = app(ExportOrigin::class)->handle($origin);

    expect(TransferBundle::validate(json_decode(json_encode($bundle), true)))->toBeArray()
        ->and($bundle)->toHaveKeys(['format', 'version', 'exported_at', 'source', 'origin', 'rules', 'routines'])
        ->and($bundle['format'])->toBe('nicetrybot-transfer')
        ->and($bundle['origin']['origin_secret'])->toBe('origin-secret-0123456789abcdef')
        ->and($bundle['origin']['zones'])->toBe([['suffix' => 'quiet.example', 'serves_www' => false]])
        ->and($bundle['origin']['sites'])->toBe([
            ['hostname' => 'alpha.example', 'protection_mode' => null],
            ['hostname' => 'beta.example', 'protection_mode' => 'enforce'],
            ['hostname' => 'shop.quiet.example', 'protection_mode' => null],
        ])
        ->and($bundle['rules'])->toBe([['name' => 'Probe scanners', 'source' => "BAN ip, ja4 FOR 30 DAYS\nWHERE category = 'probe'", 'enabled' => true]])
        ->and($bundle['routines'][0]['conditions'][0]['values'])->toBe(['rule:Probe scanners']);

    expect($bundle['origin'])->not->toHaveKey('id')->not->toHaveKey('team_id')
        ->and(json_encode($bundle))->not->toContain('team_id')->not->toContain('user_id')->not->toContain('@');
});

test('a token that may not transfer, and every request without one, gets the 404 an unrouted path gets', function (string $token) {
    [$user] = originToMove();
    $headers = match ($token) {
        'none' => [],
        'made up' => ['Authorization' => 'Bearer ntb_'.str_repeat('a', 48)],
        'read only' => ['Authorization' => 'Bearer '.apiToken([], $user)[1]],
    };

    $this->withHeaders($headers)->getJson('/transfer/v1/origins')->assertNotFound()->assertDontSee('Fleet');
})->with(['none', 'made up', 'read only']);

test('a token for all teams, rather than the origin\'s one, gets 404', function () {
    [$user, $origin] = originToMove();
    apiToken([], $user);
    [, $everywhere] = ApiToken::issue($user->fresh(), null, 'Everywhere', [ApiAbility::ManageDomains]);

    $this->withToken($everywhere)->getJson('/transfer/v1/origins')->assertNotFound()->assertDontSee('Fleet');
    $this->withToken($everywhere)->getJson("/transfer/v1/origins/{$origin->id}")->assertNotFound();
});

test('a token lists its own team\'s origins and pulls one, and never another team\'s', function () {
    [$user, $origin] = originToMove();
    [, $other] = originToMove(User::factory()->create(), 'other-');
    $other->update(['name' => 'Someone else']);
    $token = apiToken([ApiAbility::ManageDomains], $user)[1];

    $this->withToken($token)->getJson('/transfer/v1/origins')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['origins' => [['id' => $origin->id, 'name' => 'Fleet', 'origin_url' => 'https://sites.example.net', 'sites' => 3]]]);

    $bundle = $this->withToken($token)->getJson("/transfer/v1/origins/{$origin->id}")->assertOk()->json();
    expect(TransferBundle::validate($bundle)['origin']['name'])->toBe('Fleet');

    $this->withToken($token)->getJson("/transfer/v1/origins/{$other->id}")->assertNotFound()->assertDontSee('Someone else');
    $this->withToken($token)->getJson('/transfer/v1/origins/999999')->assertNotFound();
});

test('a token whose user can no longer change domains on the team gets 404', function () {
    [$user, $origin] = originToMove();
    $token = apiToken([ApiAbility::ManageDomains], $user)[1];
    $user->currentTeam->members()->updateExistingPivot($user->id, ['role' => TeamRole::Member->value]);
    $user->currentTeam->update(['user_id' => User::factory()->create()->id]);

    $this->withToken($token)->getJson("/transfer/v1/origins/{$origin->id}")->assertNotFound();
});

test('while Origins is off, the API answers 404 and the page says Transfer needs it', function () {
    [$user, $origin] = originToMove();
    $token = apiToken([ApiAbility::ManageDomains], $user)[1];
    AddonSwitch::factory()->create(['slug' => 'origins']);

    $this->withToken($token)->getJson("/transfer/v1/origins/{$origin->id}")->assertNotFound();

    $this->actingAs($user);

    Livewire::test('transfer::import')->assertSeeHtml('data-test="transfer-needs-origins"');

    expect(fn () => app(ImportOrigin::class)->handle($user->currentTeam, TransferBundle::validate(json_decode(json_encode(app(ExportOrigin::class)->handle($origin)), true))))
        ->toThrow(InvalidTransferBundle::class, 'needs the Origins add-on');
});

test('those who can change the origin download it as a file that\'s never cached; others can\'t', function () {
    [$user, $origin] = originToMove();

    $response = $this->actingAs($user)->get(route('transfer.file', $origin->id))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment; filename="nicetrybot-transfer-fleet-')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(TransferBundle::decode($response->getContent())['origin']['sites'])->toHaveCount(3);

    $member = User::factory()->create();
    $user->currentTeam->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($user->currentTeam);
    $this->actingAs($member)->get(route('transfer.file', $origin->id))->assertForbidden();

    [$stranger] = originToMove(User::factory()->create(), 'other-');
    $this->actingAs($stranger)->get(route('transfer.file', $origin->id))->assertNotFound();
});

test('a transfer file brings the origin, its sites, secret, rules and routines; importing it again changes nothing', function () {
    [, $origin] = originToMove();
    $json = json_encode(app(ExportOrigin::class)->handle($origin));
    bundleFromElsewhere($origin);
    $admin = destinationAdmin();
    $team = $admin->currentTeam;

    $this->actingAs($admin);

    Livewire::test('transfer::import')
        ->set('file', UploadedFile::fake()->createWithContent('fleet.json', $json))
        ->call('upload')
        ->assertHasNoErrors()
        ->assertSet('problem', null)
        ->assertSet('summary.origin', 'created')
        ->assertSet('summary.sitesAdded', 3)
        ->assertSet('summary.rules.added', ['Probe scanners'])
        ->assertSet('summary.routines.added', ['After a ban'])
        ->assertSeeHtml('data-test="transfer-summary"')
        ->assertDontSee('origin-secret-0123456789abcdef');

    $moved = Origin::query()->where('team_id', $team->id)->sole();
    $rule = $team->edgeRules()->sole();

    expect($moved)
        ->name->toBe('Fleet')
        ->origin_secret->toBe('origin-secret-0123456789abcdef')
        ->authorize_url->toBe('https://sites.example.net/authorize-domains')
        ->and($moved->zones()->pluck('serves_www', 'suffix')->all())->toBe(['quiet.example' => false])
        ->and($moved->domains()->orderBy('hostname')->get()->map(fn (Domain $site) => [$site->hostname, $site->protection_mode->value, $site->origin_secret, $site->verified_at !== null])->all())->toBe([
            ['alpha.example', 'observe', 'origin-secret-0123456789abcdef', true],
            ['beta.example', 'enforce', 'origin-secret-0123456789abcdef', true],
            ['shop.quiet.example', 'observe', 'origin-secret-0123456789abcdef', true],
        ])
        ->and(DomainProtectionOverride::query()->count())->toBe(1)
        ->and($team->gymRoutines()->sole()->conditions[0]['values'])->toBe([(string) $rule->id]);

    Livewire::test('transfer::import')
        ->set('file', UploadedFile::fake()->createWithContent('fleet.json', $json))
        ->call('upload')
        ->assertSet('summary.origin', 'unchanged')
        ->assertSet('summary.sitesAdded', 0)
        ->assertSet('summary.sitesUpdated', 0)
        ->assertSet('summary.sitesUnchanged', 3)
        ->assertSet('summary.rules.unchanged', ['Probe scanners'])
        ->assertSet('summary.routines.unchanged', ['After a ban']);

    expect(Origin::query()->count())->toBe(1)
        ->and(Domain::query()->count())->toBe(3)
        ->and(EdgeRule::query()->count())->toBe(1)
        ->and(GymRoutine::query()->count())->toBe(1);
});

test('importing again updates what changed at the source', function () {
    [, $origin] = originToMove();
    $bundle = bundleFromElsewhere($origin);
    $team = destinationAdmin()->currentTeam;
    app(ImportOrigin::class)->handle($team, $bundle);

    $bundle['origin']['origin_timeout_seconds'] = 60;
    $bundle['origin']['sites'][1]['protection_mode'] = null;
    $bundle['origin']['sites'][] = ['hostname' => 'gamma.example', 'protection_mode' => null];
    $bundle['rules'][0]['enabled'] = false;

    $summary = app(ImportOrigin::class)->handle($team, $bundle);

    expect($summary)
        ->origin->toBe('updated')
        ->sitesAdded->toBe(1)
        ->sitesUpdated->toBe(1)
        ->and($summary->rules['updated'])->toBe(['Probe scanners'])
        ->and(Domain::query()->where('hostname', 'beta.example')->sole()->protection_mode)->toBe(ProtectionMode::Observe)
        ->and(Domain::query()->where('hostname', 'gamma.example')->sole()->origin_timeout_seconds)->toBe(60)
        ->and(DomainProtectionOverride::query()->count())->toBe(0);
});

test('a hostname another team has stays theirs, and this team\'s own domains are left alone', function () {
    [$source, $origin] = originToMove();
    $bundle = TransferBundle::validate(json_decode(json_encode(app(ExportOrigin::class)->handle($origin)), true));
    $admin = destinationAdmin();
    Domain::query()->where('hostname', 'alpha.example')->update(['team_id' => $admin->currentTeam->id, 'origin_id' => null]);

    $summary = app(ImportOrigin::class)->handle($admin->currentTeam, $bundle);

    expect($summary->sitesElsewhere)->toBe(['alpha.example'])
        ->and($summary->sitesTaken)->toBe(['beta.example', 'shop.quiet.example'])
        ->and($summary->sitesAdded)->toBe(0)
        ->and(Domain::query()->where('hostname', 'beta.example')->sole()->team_id)->toBe($source->currentTeam->id)
        ->and(Domain::query()->where('hostname', 'alpha.example')->sole()->origin_id)->toBeNull();
});

test('a rule that doesn\'t parse here stays behind, and so does a routine waiting on it', function () {
    [, $origin] = originToMove();
    $bundle = bundleFromElsewhere($origin);
    $bundle['rules'][0]['source'] = "TAG ip AS 'odd' FOR 1 DAY\nWHERE no_such_field = 'x'";
    $obstacleFromElsewhere = ['id' => 'x1', 'type' => 'someone-elses-obstacle', 'config' => ['level' => 3], 'escalate' => null];
    $bundle['routines'][] = [...$bundle['routines'][0], 'name' => 'Everyone', 'conditions' => [], 'obstacles' => [$obstacleFromElsewhere]];

    $summary = app(ImportOrigin::class)->handle(destinationAdmin()->currentTeam, $bundle);

    expect($summary->rules['unavailable'])->toBe(['Probe scanners'])
        ->and($summary->routines['skipped'])->toBe(['After a ban'])
        ->and($summary->routines['added'])->toBe(['Everyone'])
        ->and($summary->missingObstacles)->toBe(['someone-elses-obstacle'])
        ->and(GymRoutine::query()->sole()->name)->toBe('Everyone');
});

test('sites behind an origin don\'t count toward the domain limit, so a full panel still takes a transfer', function () {
    [, $origin] = originToMove();
    $bundle = bundleFromElsewhere($origin);
    config(['nicetry.domain_limit' => 1]);
    $team = destinationAdmin()->currentTeam;
    Domain::factory()->for($team)->create(['hostname' => 'own.example']);

    expect(app(ImportOrigin::class)->handle($team, $bundle)->sitesAdded)->toBe(3);
});

test('a file that isn\'t exactly a transfer bundle is refused, and nothing is imported', function (Closure $tamper, string $message) {
    [, $origin] = originToMove();
    $bundle = app(ExportOrigin::class)->handle($origin);
    bundleFromElsewhere($origin);
    $admin = destinationAdmin();

    $this->actingAs($admin);

    Livewire::test('transfer::import')
        ->set('file', UploadedFile::fake()->createWithContent('fleet.json', $tamper($bundle)))
        ->call('upload')
        ->assertSet('summary', null)
        ->assertSee($message);

    expect(Origin::query()->count())->toBe(0)->and(Domain::query()->count())->toBe(0);
})->with([
    'not JSON' => [fn (array $b) => 'not json', 'it isn\'t JSON'],
    'another format' => [fn (array $b) => json_encode([...$b, 'format' => 'something-else']), 'isn\'t a NiceTryBot transfer file'],
    'a newer version' => [fn (array $b) => json_encode([...$b, 'version' => 2]), 'That transfer is version 2'],
    'a field it doesn\'t know' => [fn (array $b) => json_encode([...$b, 'team_id' => 1]), 'fields this panel doesn'],
    'an origin field it doesn\'t know' => [fn (array $b) => json_encode([...$b, 'origin' => [...$b['origin'], 'team_id' => 1]]), 'isn\'t valid'],
    'a hostname that isn\'t one' => [fn (array $b) => json_encode([...$b, 'origin' => [...$b['origin'], 'sites' => [['hostname' => 'http://nope/x', 'protection_mode' => null]]]]), 'isn\'t valid'],
    'a setting that isn\'t a plain value' => [function (array $b) {
        $b['routines'][0]['obstacles'][0]['config'] = ['difficulty' => ['nested' => 'array']];

        return json_encode($b);
    }, 'isn\'t a plain value'],
]);

test('it pulls an origin from another panel with that panel\'s token, then forgets the token', function () {
    [, $origin] = originToMove();
    pullFromOldPanel(app(ExportOrigin::class)->handle($origin));
    bundleFromElsewhere($origin);

    $this->actingAs(destinationAdmin());

    Livewire::test('transfer::import')
        ->set('panelUrl', 'https://old.example.org')
        ->set('token', 'ntb_'.str_repeat('t', 48))
        ->call('findOrigins')
        ->assertSet('problem', null)
        ->assertSet('remoteOrigins.0.name', 'Fleet')
        ->call('bringHere', 7)
        ->assertSet('summary.origin', 'created')
        ->assertSet('summary.sitesAdded', 3)
        ->assertSet('summary.source', 'localhost')
        ->assertSet('token', '')
        ->assertSet('remoteOrigins', []);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://old.example.org/transfer/v1/origins/7'
        && $request->hasHeader('Authorization', 'Bearer ntb_'.str_repeat('t', 48)));
    expect(Origin::query()->sole()->origin_secret)->toBe('origin-secret-0123456789abcdef');
});

test('a panel that refuses the token says so, without the token in the message', function () {
    app()->bind(TransferSource::class, fn () => new TransferSource(fn (string $host) => ['203.0.113.10']));
    Http::fake(['https://old.example.org/*' => Http::response('', 404)]);

    $this->actingAs(destinationAdmin());

    Livewire::test('transfer::import')
        ->set('panelUrl', 'https://old.example.org')
        ->set('token', 'ntb_'.str_repeat('s', 48))
        ->call('findOrigins')
        ->assertSee('didn&#039;t accept the token', false)
        ->assertDontSee(str_repeat('s', 48));
});

test('it never pulls from a private, local or plain-HTTP address', function (string $url, array $resolvesTo) {
    app()->bind(TransferSource::class, fn () => new TransferSource(fn (string $host) => $resolvesTo));

    $this->actingAs(destinationAdmin());

    Livewire::test('transfer::import')
        ->set('panelUrl', $url)
        ->set('token', 'ntb_'.str_repeat('t', 48))
        ->call('findOrigins')
        ->assertSet('remoteOrigins', [])
        ->assertNotSet('problem', null);

    Http::assertNothingSent();
})->with([
    'plain HTTP' => ['http://old.example.org', ['203.0.113.10']],
    'a private address' => ['https://old.example.org', ['10.1.2.3']],
    'one private address among public ones' => ['https://old.example.org', ['203.0.113.10', '192.168.1.5']],
    'loopback' => ['https://127.0.0.1', []],
    'the cloud metadata service' => ['https://169.254.169.254', []],
    'carrier-grade NAT' => ['https://old.example.org', ['100.64.1.1']],
    'IPv6 loopback' => ['https://[::1]', []],
    'IPv4 written as IPv6' => ['https://old.example.org', ['::ffff:10.0.0.1']],
    'a unique local IPv6 address' => ['https://old.example.org', ['fd00::5']],
    'a name that doesn\'t resolve' => ['https://nowhere.example.org', []],
    'credentials in the address' => ['https://user:pass@old.example.org', ['203.0.113.10']],
]);

test('it doesn\'t follow a redirect from the other panel', function () {
    app()->bind(TransferSource::class, fn () => new TransferSource(fn (string $host) => ['203.0.113.10']));
    Http::fake(['https://old.example.org/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);

    $this->actingAs(destinationAdmin());

    Livewire::test('transfer::import')
        ->set('panelUrl', 'https://old.example.org')
        ->set('token', 'ntb_'.str_repeat('t', 48))
        ->call('findOrigins')
        ->assertSee('That panel answered 302');

    Http::assertSentCount(1);
});

test('only those who can change domains can open Transfer, and the Domains page links it for them', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $owner->currentTeam->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($owner->currentTeam);

    $this->actingAs($member)->get(route('transfer.import'))->assertForbidden();
    $this->actingAs($member)->get(route('domains.index'))->assertDontSeeHtml('data-test="open-transfer"');

    $this->actingAs($owner)->get(route('transfer.import'))->assertOk()->assertSee('Pull from another panel');
    $this->actingAs($owner)->get(route('domains.index'))->assertSeeHtml('data-test="open-transfer"');
});

test('routines keep the exit and pass they had', function () {
    [, $origin] = originToMove();
    GymRoutine::query()->update(['exit_action' => GymExit::Block->value, 'pass_seconds' => 86400, 'one_try' => true]);
    $bundle = bundleFromElsewhere($origin);
    $team = destinationAdmin()->currentTeam;

    app(ImportOrigin::class)->handle($team, $bundle);

    expect($team->gymRoutines()->sole())
        ->exit_action->toBe(GymExit::Block)
        ->pass_seconds->toBe(86400)
        ->one_try->toBeTrue();
});

test('Team stays a team: the bundle source names the panel, not the team', function () {
    [, $origin] = originToMove();

    expect(app(ExportOrigin::class)->handle($origin)['source'])->toBe(parse_url(config('app.url'), PHP_URL_HOST))
        ->and(Team::query()->count())->toBe(1);
});

test('a team owner who isn\'t an installation administrator imports sites unverified, without the authorize URL', function () {
    [, $origin] = originToMove();
    $json = json_encode(app(ExportOrigin::class)->handle($origin));
    bundleFromElsewhere($origin);
    $owner = User::factory()->create();

    $this->actingAs($owner);

    Livewire::test('transfer::import')
        ->set('file', UploadedFile::fake()->createWithContent('fleet.json', $json))
        ->call('upload')
        ->assertHasNoErrors()
        ->assertSet('summary.sitesAdded', 3);

    $moved = Origin::query()->where('team_id', $owner->currentTeam->id)->sole();

    expect($moved->authorize_url)->toBeNull()
        ->and($moved->domains()->pluck('status')->unique()->all())->toBe([DomainStatus::PendingVerification])
        ->and($moved->domains()->whereNotNull('verified_at')->count())->toBe(0);
});
