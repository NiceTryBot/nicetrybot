<?php

use App\Enums\TeamRole;
use App\Gym\RoutineTemplates;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\User;
use App\Support\RuleTemplates;
use Livewire\Livewire;
use NiceTry\RulePacks\Actions\InstallRulePack;
use NiceTry\RulePacks\Models\RulePackInstall;
use NiceTry\RulePacks\RulePacks;

test('the Rules page links to the packs, which list each pack with its rules and routines', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('rules.index'))
        ->assertOk()
        ->assertSeeHtml('data-test="rule-packs-link"')
        ->assertSee(route('rules.packs'));

    $this->get(route('rules.packs'))
        ->assertOk()
        ->assertSeeInOrder(['Scanners and 404 probes', 'version 3', 'Not installed', 'Secret and source hunters', 'Scanners work for it', 'PHP exploit attempts'])
        ->assertSee("WHERE category IN ('probe/creds', 'probe/source')")
        ->assertSeeHtml('data-test="rule-pack-install"');
});

test('installing from the page tells what it did, and the pack shows as installed with its rules tagging and its routine off', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('rule-packs::index')
        ->call('install', 'php-exploits')
        ->assertDispatched('toast-show', fn (string $event, array $params) => $params['slots']['text'] === 'PHP exploit attempts: 4 added.')
        ->assertSeeHtml('data-test="rule-pack-uninstall"')
        ->assertSee(['Installed', 'Tagging', 'Off']);

    expect($user->currentTeam->edgeRules()->count())->toBe(3)
        ->and($user->currentTeam->gymRoutines()->sole()->enabled)->toBeFalse();
});

test('the page offers an update when the team has an older version, and says what it kept', function () {
    $user = User::factory()->create();
    $rule = EdgeRule::factory()->for($user->currentTeam)->script("TAG ip AS 'php-rce' FOR 1 DAY WHERE path LIKE '%eval-stdin.php%'")->create(['name' => 'PHP remote-code attempts']);
    RulePackInstall::factory()->of($rule)->create(['pack' => 'php-exploits', 'item' => 'php-rce', 'version' => 0]);
    $rule->update(['source' => "TAG ip AS 'php-rce' FOR 30 DAYS WHERE path LIKE '%eval-stdin.php%'"]);
    $this->actingAs($user);

    Livewire::test('rule-packs::index')
        ->assertSee(['Update available', 'Update to version 1', 'Changed by you'])
        ->call('install', 'php-exploits')
        ->assertSeeHtml('data-test="rule-pack-notice"')
        ->assertSee('Left as you changed them: PHP remote-code attempts.')
        ->assertDontSee('Update available');
});

test('a routine of the team\'s taking every verdict is named, and the rules it would take start switched off', function () {
    $user = User::factory()->create();
    GymRoutine::factory()->for($user->currentTeam)->create(['name' => 'Flagged by your rules', 'conditions' => [['field' => 'verdict', 'operator' => 'in', 'values' => ['any']]]]);
    $this->actingAs($user);

    Livewire::test('rule-packs::index')
        ->assertSeeHtml('data-test="rule-packs-every-verdict"')
        ->assertSee('Flagged by your rules takes every rule’s verdicts')
        ->call('install', 'ai-crawlers')
        ->assertSee('The new rules are switched off, because a routine of yours takes every rule’s verdicts.');

    expect($user->currentTeam->edgeRules()->pluck('enabled')->unique()->all())->toBe([false]);
});

test('uninstalling from the page removes the pack', function () {
    $user = User::factory()->create();
    app(InstallRulePack::class)->handle($user->currentTeam, app(RulePacks::class)->find('ai-crawlers'));
    $this->actingAs($user);

    Livewire::test('rule-packs::index')
        ->assertSeeHtml('wire:click="uninstall(\'ai-crawlers\')"')
        ->call('uninstall', 'ai-crawlers')
        ->assertDispatched('toast-show', fn (string $event, array $params) => $params['slots']['text'] === 'AI crawlers: 3 removed.');

    expect($user->currentTeam->edgeRules()->count())->toBe(0);
});

test('a member sees the packs but can\'t install one', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $owner->currentTeam->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($owner->currentTeam);
    $this->actingAs($member);

    Livewire::test('rule-packs::index')
        ->assertSee('Scanners and 404 probes')
        ->assertDontSeeHtml('data-test="rule-pack-install"')
        ->call('install', 'scanners')
        ->assertForbidden();

    expect(EdgeRule::count())->toBe(0);
});

test('an unknown pack is not found', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('rule-packs::index')->call('install', 'nope')->assertNotFound();
});

test('each pack\'s first rule, and its routines that need none of its rules, join the template galleries', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(app(RuleTemplates::class)->find('scanners-secret-hunters')['name'])->toBe('Secret and source hunters')
        ->and(app(RuleTemplates::class)->find('scanners-cms-probes'))->toBeNull()
        ->and(RoutineTemplates::all())->toHaveKey('form-scripts-scripts-on-forms')
        ->not->toHaveKey('scanners-scanners-work');

    Livewire::test('pages::rules.index')
        ->call('newRule', 'form-scripts-scripts-posting-forms')
        ->assertSet('name', 'Scripts posting forms')
        ->assertSee('Reads as');

    Livewire::withQueryParams(['template' => 'form-scripts-scripts-on-forms'])
        ->test('pages::gym.edit')
        ->assertSet('name', 'Scripts on sign-in and forms')
        ->call('save')
        ->assertHasNoErrors();

    expect(GymRoutine::sole()->entry()->edgePathGlobs())->toContain('*login*');
});

test('the Rules page narrows its rules to what one pack installed, never another team\'s, counting an edited rule as installed', function () {
    $user = User::factory()->create();
    app(InstallRulePack::class)->handle($user->currentTeam, app(RulePacks::class)->find('ai-crawlers'));
    $user->currentTeam->edgeRules()->where('name', 'AI training crawlers')->sole()->update(['source' => "TAG ip AS 'ai-training' FOR 30 DAYS WHERE ua LIKE '%GPTBot%'"]);
    EdgeRule::factory()->for($user->currentTeam)->create(['name' => 'Office uptime monitor']);
    $other = User::factory()->create();
    app(InstallRulePack::class)->handle($other->currentTeam, app(RulePacks::class)->find('php-exploits'));
    $this->actingAs($user);

    $page = Livewire::test('pages::rules.index')
        ->assertSeeHtml('data-test="rule-filter-pack"')
        ->assertSee(['All active rules', 'Rule pack: Scanners and 404 probes (0/6 installed)', 'Rule pack: AI crawlers (3/3 installed)'])
        ->assertSee(['AI training crawlers', 'Office uptime monitor'])
        ->assertDontSeeHtml('data-test="rule-candidate"');

    $page->set('filters.pack', 'ai-crawlers')
        ->assertSee(['AI training crawlers', 'AI assistants and answer engines', 'AI crawler names, probing', 'Tags · 3'])
        ->assertDontSee('Office uptime monitor')
        ->assertDontSeeHtml('data-test="rule-candidate"');

    $page->set('filters.pack', 'nope')
        ->assertSee(['AI training crawlers', 'Office uptime monitor', 'Tags · 4']);

    $page->set('filters.pack', '')
        ->assertSee(['AI training crawlers', 'Office uptime monitor', 'Tags · 4']);
});

test('a pack the team hasn\'t installed keeps the board, with its rules as cards to install one at a time', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = Livewire::withQueryParams(['filters' => ['pack' => 'php-exploits']])
        ->test('pages::rules.index')
        ->assertSee(['None of these are installed yet.', 'Timeouts · 0', 'PHP remote-code attempts', 'Install rule'])
        ->assertSeeHtml(['data-test="rules-filtered-empty"', 'data-test="rules-view"', 'data-test="rule-board"'])
        ->assertDontSeeHtml(['data-test="rule-list"', 'data-test="rule-card"']);
    // Picked on the server too, so the dropdown shows the pack before Livewire loads.
    expect($page->html())->toMatch('/selected="selected"\s+value="php-exploits"/')
        // Each card waits in the column its routine acts in: this pack's turns clients away.
        ->and(substr_count($page->html(), '>PHP remote-code attempts</p>'))->toBe(1);
    $page->assertSeeHtmlInOrder(['data-test="board-column-blocked"', 'PHP remote-code attempts', 'Exploit attempts are turned away'])
        ->assertSeeHtml('wire:confirm="Install “PHP remote-code attempts” and switch on “Exploit attempts are turned away”?');

    $page->call('addFromFilter', 'pack', 'php-rce')
        ->assertDispatched('toast-show', fn (string $event, array $params) => $params['slots']['text'] === 'Installed: PHP remote-code attempts, Exploit attempts are turned away.')
        ->assertSee(['Rule pack: PHP exploit attempts (1/3 installed)', 'Timeouts · 1'])
        ->assertSeeHtml('data-test="rule-card"');

    expect($user->currentTeam->edgeRules()->sole())->name->toBe('PHP remote-code attempts')->enabled->toBeTrue()
        ->and($user->currentTeam->gymRoutines()->sole())->name->toBe('Exploit attempts are turned away')->enabled->toBeTrue()
        ->and(RulePackInstall::query()->where('team_id', $user->currentTeam->id)->orderBy('item')->pluck('item')->all())->toBe(['exploits-turned-away', 'php-rce']);

    $page->set('view', 'list')
        ->assertSeeHtml(['data-test="rule-list"', 'data-test="rule-candidate-install"']);

    // A routine the team switched off keeps its switch, and the cards say so (under Tags, now nothing acts).
    $user->currentTeam->gymRoutines()->sole()->update(['enabled' => false]);
    $page->set('view', 'board')
        ->set('tab', 'tags')
        ->assertSee('Exploit attempts are turned away · off')
        ->assertDontSeeHtml('wire:confirm')
        ->call('addFromFilter', 'pack', 'php-shells');

    expect($user->currentTeam->gymRoutines()->sole()->enabled)->toBeFalse()
        ->and($user->currentTeam->edgeRules()->count())->toBe(2);

    $page->call('addFromFilter', 'pack', 'nope')->assertNotFound();
    Livewire::withQueryParams([])->test('pages::rules.index')->call('addFromFilter', 'pack', 'php-shells')->assertNotFound();
    expect($user->currentTeam->edgeRules()->count())->toBe(2);
});

test('a member sees a pack\'s rules to install but can\'t install one', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $owner->currentTeam->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($owner->currentTeam);
    $this->actingAs($member);

    Livewire::withQueryParams(['filters' => ['pack' => 'php-exploits']])
        ->test('pages::rules.index')
        ->assertSee('PHP remote-code attempts')
        ->assertDontSeeHtml('data-test="rule-candidate-install"')
        ->call('addFromFilter', 'pack', 'php-rce')
        ->assertForbidden();

    expect(EdgeRule::count())->toBe(0);
});

test('a pack routine left behind when its rules were deleted is switched on by installing one from the board', function () {
    $user = User::factory()->create();
    app(InstallRulePack::class)->handle($user->currentTeam, app(RulePacks::class)->find('php-exploits'), ['php-rce']);
    $user->currentTeam->edgeRules()->sole()->delete();
    $this->actingAs($user);

    Livewire::withQueryParams(['filters' => ['pack' => 'php-exploits']])
        ->test('pages::rules.index')
        ->assertDontSee('Exploit attempts are turned away · off')
        ->assertSeeHtml('wire:confirm="Install “PHP remote-code attempts” and switch on “Exploit attempts are turned away”?')
        ->call('addFromFilter', 'pack', 'php-rce');

    $routine = $user->currentTeam->gymRoutines()->sole();
    expect($routine->enabled)->toBeTrue()
        ->and($routine->conditions[0]['values'])->toBe([(string) $user->currentTeam->edgeRules()->sole()->id]);
});

test('a rule a pack installed is labelled with the pack on its card, linking to that pack\'s rules', function () {
    $user = User::factory()->create();
    app(InstallRulePack::class)->handle($user->currentTeam, app(RulePacks::class)->find('ai-crawlers'));
    EdgeRule::factory()->for($user->currentTeam)->create(['name' => 'Office uptime monitor']);
    $this->actingAs($user);

    $page = Livewire::withQueryParams([])->test('pages::rules.index');

    expect(substr_count($page->html(), 'data-test="rule-card-label"'))->toBe(3);
    $page->assertSeeHtml(e(route('rules.index', ['filters' => ['pack' => 'ai-crawlers']])))
        ->set('view', 'list')
        ->assertSeeHtmlInOrder(['AI assistants and answer engines', 'data-test="rule-card-label"', 'Office uptime monitor']);

    expect(substr_count($page->html(), 'data-test="rule-card-label"'))->toBe(3);
});
