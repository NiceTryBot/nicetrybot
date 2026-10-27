<?php

use App\Actions\Ai\TranslateRule;
use App\Actions\Domains\MergeDomains;
use App\Actions\Edge\ImportEdgeLogFile;
use App\Actions\Rules\BuildRuleQuery;
use App\Actions\Rules\EvaluateRule;
use App\Addons\Addons;
use App\Data\RuleScript;
use App\Enums\RuleField;
use App\Gym\ObstacleRegistry;
use App\Gym\RoutineTemplates;
use App\Models\Administrator;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\GymSession;
use App\Models\LivewireComponent;
use App\Models\Team;
use App\Models\User;
use App\Models\Verdict;
use App\Models\VisitorDataSetting;
use App\Support\RuleFields;
use App\Support\RuleTemplates;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Fixtures\Addons\Example\Auth\BookmarkAccess;
use Tests\Fixtures\Addons\Example\ExampleServiceProvider;
use Tests\Fixtures\Addons\Example\Gym\AttemptLog;
use Tests\Fixtures\Addons\Example\Models\Bookmark;
use Tests\Fixtures\Addons\Example\Rules\BookmarkField;

/**
 * Install the example add-on (tests/Fixtures/Addons/Example) for one test, with its table.
 */
function installExampleAddon(): void
{
    Bookmark::createTable();
    app()->register(ExampleServiceProvider::class);
    app()->forgetInstance(ObstacleRegistry::class);
    app('router')->getRoutes()->refreshNameLookups();
}

test('an installed add-on is read from its composer.json and listed on the admin\'s Add-ons page', function () {
    $fingerprints = app(Addons::class)->find('fingerprints');

    expect($fingerprints)->not->toBeNull()
        ->package->toBe('nicetry/fingerprints')
        ->name->toBe('Fingerprints')
        ->paid->toBeTrue();

    $this->actingAs(Administrator::factory()->create()->user)
        ->get(route('admin.addons'))
        ->assertOk()
        ->assertSeeHtml('data-test="addon-fingerprints"')
        ->assertSee('nicetry/fingerprints')
        ->assertSee('Paid');
})->skip(fn () => ! app(Addons::class)->has('fingerprints'), 'Needs the paid Fingerprints add-on (nicetry/fingerprints).');

test('the sidebar shows an add-on\'s page among the core\'s, in order', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSeeInOrder([route('gym.index'), route('fingerprints.index')])
        ->assertDontSee('Coming soon Fingerprints');
})->skip(fn () => ! app(Addons::class)->has('fingerprints'), 'Needs the paid Fingerprints add-on (nicetry/fingerprints).');

test('an add-on\'s retention period is chosen on the Visitor data page with the core\'s', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    Livewire::test('pages::visitor-data.index')
        ->assertSee('JavaScript fingerprints taken in the gym.')
        ->assertSet('days.fingerprint_days', config('fingerprints.retention_days'))
        ->set('days.fingerprint_days', 14)
        ->call('saveRetention')
        ->assertHasNoErrors();

    expect(VisitorDataSetting::sole()->fingerprint_days)->toBe(14);
})->skip(fn () => ! app(Addons::class)->has('fingerprints'), 'Needs the paid Fingerprints add-on (nicetry/fingerprints).');

test('an add-on\'s views are drawn in the slots it names, in order, with the slot\'s data', function () {
    installExampleAddon();
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'shop.example.com']);
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder([
            '<meta name="example-head">',
            '<meta name="example-head-too">',
            '</head>',
            'data-test="example-topbar"',
            'data-test="workspace-menu"',
            'data-test="example-body-end"',
            '</body>',
        ], false);

    $this->get(route('domains.show', $domain))
        ->assertOk()
        ->assertSeeInOrder(['Example panel for shop.example.com', 'data-test="open-delete-domain"'], false);

    $this->get(route('rules.index'))
        ->assertOk()
        ->assertSeeInOrder(['data-test="rule-template"', 'Example link for team '.$user->currentTeam->id], false);

    $this->get(route('domains.index'))
        ->assertOk()
        ->assertSeeInOrder([
            'Example action for team '.$user->currentTeam->id,
            'data-test="open-add-domain"',
            'Example section for team '.$user->currentTeam->id,
            'data-test="domain-card"',
        ], false);
});

test('an add-on\'s settings page is linked among the core\'s, by its order', function () {
    installExampleAddon();

    expect(app(Addons::class)->find('example'))->name->toBe('Example');

    $this->actingAs(User::factory()->create())
        ->get(route('example.settings'))
        ->assertOk();

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertSeeInOrder([route('appearance.edit'), route('example.settings'), route('ai.edit')])
        ->assertSee('Example settings');
});

test('an add-on\'s cog menu link is listed among the core\'s, by its order, and flags the cog while its badge shows', function () {
    installExampleAddon();
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder([route('domains.index'), route('example.settings'), route('visitor-data.index')])
        ->assertSee('Example switch')
        ->assertDontSeeHtml('data-test="workspace-menu-alert"');

    config(['example.alert' => true]);

    $this->get(route('dashboard'))->assertSeeInOrder(['data-test="workspace-menu-alert"', 'data-test="nav-example"', 'Example switch', 'On', route('visitor-data.index')], false);
});

test('an add-on\'s obstacles join the gym after the core\'s, and reach visitors', function () {
    installExampleAddon();
    $routine = GymRoutine::factory()->obstacles(['knock' => []])->create();
    $session = GymSession::factory()->create(['team_id' => $routine->team_id]);
    $session->routines()->attach($routine);

    $keys = array_keys(app(ObstacleRegistry::class)->all());

    expect(array_slice($keys, 0, count(config('gym.obstacles'))))->toBe(array_map(fn (string $class) => app($class)->key(), config('gym.obstacles')))
        ->and($keys)->toContain('knock');

    $this->get(route('gym.show', $session))->assertOk()->assertSeeHtml('data-test="example-knock"');

    $this->actingAs(User::factory()->create());
    Livewire::test('pages::gym.edit')->assertSeeInOrder(['data-test="obstacle-library"', 'Proof of work', 'Waiting room', 'Type the code', 'Browser check', 'Knock twice'], false);
});

test('an add-on hears about each attempt once it\'s saved, from the IP that made it', function () {
    app()->singleton(AttemptLog::class);
    installExampleAddon();
    $routine = GymRoutine::factory()->obstacles(['knock' => []])->create();
    $session = GymSession::factory()->create(['team_id' => $routine->team_id, 'client_ip' => '198.51.100.4']);
    $session->routines()->attach($routine);
    $step = $routine->obstacles[0]['id'];
    $this->get(route('gym.show', $session));

    $this->postJson(route('gym.attempt', $session), ['step' => $step])->assertJsonPath('status', 'retry');
    $this->postJson(route('gym.attempt', $session), ['step' => $step])->assertJsonPath('status', 'cleared');

    $heard = app(AttemptLog::class)->heard;
    expect($heard)->toHaveCount(2)
        ->and($heard[0]['attempt'])
        ->step->key->toBe($step)
        ->before->toBe(['knocks' => 0])
        ->after->toBe(['knocks' => 1])
        ->outcome->status->toBe('retry')
        ->clientIp->toBe('198.51.100.4')
        ->and($heard[0]['saved'])->toBe(['knocks' => 1])
        ->and($heard[1]['attempt']->outcome->isCleared())->toBeTrue();
});

test('an add-on\'s policies are registered with the Gate', function () {
    installExampleAddon();
    $user = User::factory()->create();
    $bookmark = Bookmark::create(['team_id' => $user->current_team_id, 'path' => '/login']);

    expect(Gate::getPolicyFor(Bookmark::class))->toBeInstanceOf(BookmarkAccess::class)
        ->and(Gate::forUser($user)->allows('view', $bookmark))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('view', $bookmark))->toBeFalse();
});

test('an add-on\'s entries join the edge\'s capture feed, in the lists it names', function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
    installExampleAddon();
    $team = Team::factory()->create();
    $sign = Domain::factory()->for($team)->verified()->create(['hostname' => 'sign.example']);
    $bookmark = Bookmark::create(['team_id' => $team->id, 'domain_id' => $sign->id, 'path' => '/login']);

    $this->withToken('edge-token')
        ->getJson(route('edge.captures.index'))
        ->assertOk()
        ->assertJsonPath('captures', [])
        ->assertJsonPath('tags', [['id' => $bookmark->id, 'hosts' => ['sign-example.preview.example', 'sign.example'], 'method' => null, 'path' => '/login', 'component' => null]]);
});

test('an add-on\'s lockdowns join the edge\'s lockdown feed, told which edge asked', function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
    installExampleAddon();
    $team = Team::factory()->create();
    $sign = Domain::factory()->for($team)->verified()->create(['hostname' => 'sign.example']);
    Bookmark::create(['team_id' => $team->id, 'domain_id' => $sign->id, 'path' => '/locked']);
    Bookmark::create(['team_id' => $team->id, 'domain_id' => $sign->id, 'path' => '/pricing']);

    expect($this->withToken('edge-token')->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->getJson(route('edge.lockdowns'))->assertOk()->json('lockdowns'))
        ->toContain(['team' => $team->id, 'hosts' => ['sign-example.preview.example', 'sign.example'], 'allow' => [], 'secret' => 'example-secret', 'html' => 'Held for 198.51.100.9', 'retry_after' => 60])
        ->toHaveCount(1);
});

test('an add-on\'s rows on a www. domain move with it when it folds into the bare name', function () {
    installExampleAddon();
    $team = Team::factory()->create();
    $bare = Domain::factory()->for($team)->verified()->create(['hostname' => 'both.example.com']);
    $www = Domain::factory()->for($team)->verified()->create(['hostname' => 'www.both.example.com']);
    $bookmark = Bookmark::create(['team_id' => $team->id, 'domain_id' => $www->id, 'path' => '/login']);

    app(MergeDomains::class)->handle($www, $bare);

    expect(Domain::find($www->id))->toBeNull()
        ->and($bookmark->fresh()->domain_id)->toBe($bare->id);
});

test('the Livewire components an add-on names for a team are known to it', function () {
    $team = Team::factory()->create();
    $component = LivewireComponent::factory()->create(['name' => 'app.bookmarked']);

    expect(LivewireComponent::query()->knownTo($team)->count())->toBe(0);

    installExampleAddon();
    Bookmark::create(['team_id' => $team->id, 'livewire_component_id' => $component->id, 'path' => '/livewire/update']);

    expect(LivewireComponent::query()->knownTo($team)->pluck('name')->all())->toBe(['app.bookmarked'])
        ->and(LivewireComponent::query()->knownTo(Team::factory()->create())->count())->toBe(0);
});

test('an add-on\'s rule fields are listed after the core\'s, for scripts, searches, the editors and Claude', function () {
    installExampleAddon();

    $names = app(RuleFields::class)->names();

    expect(array_slice($names, 0, count(RuleField::cases())))->toBe(array_map(fn (RuleField $field) => $field->value, RuleField::cases()))
        ->and(end($names))->toBe('bookmark')
        ->and(app(RuleFields::class)->find('BOOKMARK'))->toBe(BookmarkField::Bookmark)
        ->and(RuleScript::parse("TAG ip AS 'saved' FOR 1 DAY WHERE bookmark LIKE '/pricing%'")->fields())->toBe([BookmarkField::Bookmark])
        ->and(app(TranslateRule::class)->instructions())
        ->toContain('- bookmark: bookmarked page (text). only on bookmarked pages')
        ->toContain('- bookmark: only once the team bookmarks a page.');

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::rules.index')->call('newRule')->assertSeeHtml('title="bookmarked page · only on bookmarked pages"');
    Livewire::test('pages::activity.index')->assertSeeHtml('title="bookmarked page · only on bookmarked pages"');
});

test('a rule can test an add-on\'s field, whose table is joined only into the queries that read it', function () {
    installExampleAddon();
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    Bookmark::create(['team_id' => $user->current_team_id, 'path' => '/pricing']);
    $rule = EdgeRule::factory()->for($user->currentTeam)->script("TAG ip AS 'saved-page' FOR 1 DAY WHERE bookmark IS NOT NULL")->create(['last_request_id' => (int) EdgeRequest::max('id')]);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.7', 'path' => '/pricing']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.4', 'path' => '/home']);

    app(EvaluateRule::class)->handle($rule);

    expect(Verdict::sole()->client_ip)->toBe('203.0.113.7');

    $sql = fn (string $source) => app(BuildRuleQuery::class)->handle($user->currentTeam, RuleScript::parse($source))->toSql();

    expect($sql("TAG ip AS 'x' FOR 1 DAY WHERE status = 404"))->not->toContain('example_bookmarks')
        ->and(substr_count($sql("TAG ip AS 'x' FOR 1 DAY WHERE bookmark = '/pricing' OR bookmark LIKE '/docs%'"), 'left join "example_bookmarks"'))->toBe(1)
        ->and($sql("TAG ip AS 'x' FOR 1 DAY WHERE status = 200 WITHIN 1 HOUR HAVING COUNT(DISTINCT bookmark) >= 2"))->toContain('left join "example_bookmarks"')
        ->and($sql("TAG ip AS 'x' FOR 1 DAY WITHIN 1 HOUR HAVING COUNT(*) FILTER (WHERE bookmark IS NULL) = 0"))->toContain('left join "example_bookmarks"');
});

test('an add-on\'s starter rules and gym routines join the galleries after the core\'s, without replacing one', function () {
    installExampleAddon();
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(array_slice(array_keys(app(RuleTemplates::class)->all()), -1))->toBe(['bookmark-hammering'])
        ->and(app(RuleTemplates::class)->find('probe-scanners')['name'])->toBe('Probe scanners')
        ->and(array_slice(array_keys(RoutineTemplates::all()), -1))->toBe(['bookmark-knock'])
        ->and(RoutineTemplates::all()['probe-scanners']['name'])->toBe('Probe scanners');

    Livewire::test('pages::rules.index')
        ->assertSeeInOrder(['Probe scanners', 'Datacenter burst', 'Bookmark hammering'])
        ->call('newRule', 'bookmark-hammering')
        ->assertSet('name', 'Bookmark hammering')
        ->assertSee('Reads as')
        ->call('save')
        ->assertHasNoErrors();

    expect(EdgeRule::sole()->name)->toBe('Bookmark hammering');

    $this->get(route('gym.index'))->assertOk()->assertSeeInOrder(['Probe scanners', 'Knock for bookmarks']);

    Livewire::withQueryParams(['template' => 'bookmark-knock'])
        ->test('pages::gym.edit')
        ->assertSet('name', 'Knock for bookmarks')
        ->assertSee('Knock twice');
});

test('an add-on keeps what it reads from the edge\'s lines beside each request, in the batch\'s order', function () {
    Storage::fake('edge-logs');
    installExampleAddon();
    $domain = Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);
    $line = fn (string $path, array $more = []) => json_encode(['occurred_at' => '2026-10-03T21:39:28Z', 'host' => 'claims.example.com', 'client_ip' => '203.0.113.7', 'method' => 'GET', 'path' => $path, 'status' => 200, ...$more]);
    Storage::disk('edge-logs')->put('raw/node-1.ndjson', implode("\n", [
        $line('/one'),
        $line('/two', ['bookmark' => '/pricing']),
        $line('/three', ['bookmark' => 'not a path']),
        $line('/four'),
        $line('/five', ['bookmark' => '/docs']),
        json_encode(['host' => 'claims.example.com', 'bookmark' => '/never-stored']),
    ]));

    expect(app(ImportEdgeLogFile::class)->handle('raw/node-1.ndjson')->rows_imported)->toBe(5);

    $paths = EdgeRequest::query()->orderBy('id')->get()->pluck('path', 'id');

    expect($paths->values()->all())->toBe(['/one', '/two', '/three', '/four', '/five'])
        ->and(Bookmark::query()->orderBy('id')->get()->map(fn (Bookmark $bookmark) => [$paths[$bookmark->edge_request_id], $bookmark->path, $bookmark->team_id])->all())
        ->toBe([['/two', '/pricing', $domain->team_id], ['/five', '/docs', $domain->team_id]]);
});

test('an add-on marks Activity\'s requests for a page at once, and adds to a request\'s details and buttons', function () {
    installExampleAddon();
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    Bookmark::create(['team_id' => $user->current_team_id, 'path' => '/pricing']);
    $bookmarked = EdgeRequest::factory()->for($domain)->create(['path' => '/pricing', 'occurred_at' => now()->subMinutes(2)]);
    EdgeRequest::factory()->for($domain)->create(['path' => '/home', 'occurred_at' => now()->subMinute()]);
    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index');

    expect(substr_count($page->html(), 'data-test="example-bookmarked"'))->toBe(1);

    $html = $page->call('inspect', $bookmarked->id)->html();
    $at = fn (string $text) => strpos($html, $text);

    expect($at('Bookmark details for /pricing'))->toBeInt()
        ->toBeLessThan($at('Bookmark it'))
        ->and($at('Bookmark it'))->toBeLessThan($at('Everything from this IP'));
});
