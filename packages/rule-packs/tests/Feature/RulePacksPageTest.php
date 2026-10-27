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
        ->assertSeeInOrder(['Scanners and 404 probes', 'version 1', 'Not installed', 'Secret and source hunters', 'Scanners work for it', 'PHP exploit attempts'])
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
