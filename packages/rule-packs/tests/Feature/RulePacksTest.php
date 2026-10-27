<?php

use App\Enums\GymExit;
use App\Enums\RuleAction;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\User;
use Livewire\Livewire;
use NiceTry\RulePacks\Actions\InstallRulePack;
use NiceTry\RulePacks\Actions\UninstallRulePack;
use NiceTry\RulePacks\Models\RulePackInstall;
use NiceTry\RulePacks\RulePack;
use NiceTry\RulePacks\RulePacks;

/**
 * A pack whose second rule reads a field only an add-on that isn't installed brings, and whose second
 * routine is entered by that rule alone.
 */
class RulePacksFixturePack extends RulePack
{
    public function key(): string
    {
        return 'fixture';
    }

    public function version(): int
    {
        return 1;
    }

    public function name(): string
    {
        return 'Fixture pack';
    }

    public function description(): string
    {
        return 'Bookmarks and probes.';
    }

    public function icon(): string
    {
        return 'bookmark';
    }

    public function rules(): array
    {
        return [
            'probes' => ['name' => 'Fixture probes', 'blurb' => 'Probes.', 'source' => "TAG ip AS 'probe' FOR 1 DAY WHERE category = 'probe'"],
            'bookmarks' => ['name' => 'Fixture bookmarks', 'blurb' => 'Bookmarks.', 'source' => "TAG ip AS 'bookmarked' FOR 1 DAY WHERE bookmark IS NOT NULL"],
        ];
    }

    public function routines(): array
    {
        $routine = ['icon' => 'bookmark', 'match_all' => true, 'conditions' => [], 'obstacles' => [], 'exit' => GymExit::Block, 'pass_seconds' => 3600];

        return [
            'probes-blocked' => ['name' => 'Fixture probes blocked', 'blurb' => '', 'rules' => ['probes', 'bookmarks'], ...$routine],
            'bookmarks-blocked' => ['name' => 'Fixture bookmarks blocked', 'blurb' => '', 'rules' => ['bookmarks'], ...$routine],
        ];
    }
}

/**
 * The pack with this key.
 */
function rulePack(string $key): RulePack
{
    return app(RulePacks::class)->find($key);
}

test('installing a pack adds its rules switched on and only tagging, its routine switched off, and records where each came from', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $lastRequest = EdgeRequest::factory()->create();

    $changes = app(InstallRulePack::class)->handle($team, rulePack('scanners'));

    $rules = $team->edgeRules()->orderBy('id')->get();
    $routine = $team->gymRoutines()->sole();

    expect($changes->added)->toBe(['Secret and source hunters', 'CMS and admin probes that miss', 'Wordlist sweep', '404s across your sites', 'Scanners work for it'])
        ->and($changes->rulesStartOff)->toBeFalse()
        ->and($rules->pluck('enabled')->unique()->all())->toBe([true])
        ->and($rules->pluck('action')->unique()->all())->toBe([RuleAction::Tag])
        ->and($rules->pluck('last_request_id')->unique()->all())->toBe([$lastRequest->id])
        ->and($routine->enabled)->toBeFalse()
        ->and($routine->exit_action)->toBe(GymExit::Continue)
        ->and($routine->conditions)->toBe([['field' => 'verdict', 'operator' => 'in', 'values' => $rules->map(fn (EdgeRule $rule) => (string) $rule->id)->all()]])
        ->and($routine->obstacles[0])->toMatchArray(['type' => 'proof-of-work', 'config' => ['difficulty' => 20, 'display' => 'quiet'], 'escalate' => 'waiting-room']);

    expect(RulePackInstall::query()->where('team_id', $team->id)->orderBy('id')->get(['pack', 'item', 'version', 'edge_rule_id', 'gym_routine_id'])->toArray())->toBe([
        ['pack' => 'scanners', 'item' => 'secret-hunters', 'version' => 1, 'edge_rule_id' => $rules[0]->id, 'gym_routine_id' => null],
        ['pack' => 'scanners', 'item' => 'cms-probes', 'version' => 1, 'edge_rule_id' => $rules[1]->id, 'gym_routine_id' => null],
        ['pack' => 'scanners', 'item' => 'wordlist-sweep', 'version' => 1, 'edge_rule_id' => $rules[2]->id, 'gym_routine_id' => null],
        ['pack' => 'scanners', 'item' => 'multi-site-404s', 'version' => 1, 'edge_rule_id' => $rules[3]->id, 'gym_routine_id' => null],
        ['pack' => 'scanners', 'item' => 'scanners-work', 'version' => 1, 'edge_rule_id' => null, 'gym_routine_id' => $routine->id],
    ]);
});

test('installing a pack again skips what it already installed', function () {
    $team = User::factory()->create()->currentTeam;
    app(InstallRulePack::class)->handle($team, rulePack('php-exploits'));

    $changes = app(InstallRulePack::class)->handle($team, rulePack('php-exploits'));

    expect($changes->added)->toBe([])
        ->and($changes->updated)->toBe([])
        ->and($team->edgeRules()->count())->toBe(3)
        ->and($team->gymRoutines()->count())->toBe(1)
        ->and(RulePackInstall::query()->count())->toBe(4);
});

test('a pack\'s rules install switched off while a routine of the team\'s takes every rule\'s verdicts', function () {
    $team = User::factory()->create()->currentTeam;
    GymRoutine::factory()->for($team)->disabled()->create(['conditions' => [['field' => 'verdict', 'operator' => 'in', 'values' => ['any']]]]);
    $openRules = app(InstallRulePack::class)->handle($team, rulePack('ai-crawlers'));
    $flagged = GymRoutine::factory()->for($team)->create(['name' => 'Flagged by your rules', 'conditions' => [['field' => 'verdict', 'operator' => 'in', 'values' => ['any']]]]);

    $changes = app(InstallRulePack::class)->handle($team, rulePack('browser-impostors'));

    expect($openRules->rulesStartOff)->toBeFalse()
        ->and($changes->rulesStartOff)->toBeTrue()
        ->and(app(InstallRulePack::class)->routinesTakingEveryVerdict($team)->modelKeys())->toBe([$flagged->id])
        ->and($team->edgeRules()->where('name', 'AI training crawlers')->sole()->enabled)->toBeTrue()
        ->and($team->edgeRules()->where('name', 'Spoofed browser')->sole()->enabled)->toBeFalse();
});

test('a rule reading a field of an add-on that isn\'t installed is left out, with a routine that needs only it, and the page says why', function () {
    app()->tag([RulePacksFixturePack::class], RulePacks::TAG);
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $changes = app(InstallRulePack::class)->handle($team, rulePack('fixture'));

    $probes = $team->edgeRules()->sole();

    expect($changes->added)->toBe(['Fixture probes', 'Fixture probes blocked'])
        ->and($changes->unavailable)->toBe(['Fixture bookmarks', 'Fixture bookmarks blocked'])
        ->and($probes->name)->toBe('Fixture probes')
        ->and($team->gymRoutines()->sole()->conditions[0]['values'])->toBe([(string) $probes->id])
        ->and(rulePack('fixture')->unavailableBecause('bookmarks'))->toBe('Unknown field “bookmark”. If an add-on brings it, that add-on isn’t installed.');

    $this->actingAs($user);

    Livewire::test('rule-packs::index')
        ->assertSeeInOrder(['Fixture bookmarks', 'Needs an add-on', 'Unknown field “bookmark”. If an add-on brings it, that add-on isn’t installed.', 'Fixture bookmarks blocked', 'Needs an add-on'])
        ->assertDontSeeHtml('data-test="rule-pack-install-missing"');
});

test('an update rewrites what the team hasn\'t changed, keeps what it has, and brings the pack to its version', function () {
    $team = User::factory()->create()->currentTeam;
    $older = EdgeRule::factory()->for($team)->script("TAG ip AS 'secret-hunter' FOR 1 DAY WHERE category = 'probe/creds'")->create(['name' => 'Secret hunters']);
    $edited = EdgeRule::factory()->for($team)->script("TAG ip AS 'scanner' FOR 1 DAY WHERE category = 'probe'")->create(['name' => 'Wordlist sweep']);
    RulePackInstall::factory()->of($older)->create(['pack' => 'scanners', 'item' => 'secret-hunters', 'version' => 0]);
    RulePackInstall::factory()->of($edited)->create(['pack' => 'scanners', 'item' => 'wordlist-sweep', 'version' => 0]);
    $edited->update(['source' => "TAG ip AS 'scanner' FOR 1 DAY WHERE category = 'probe' AND status = 404"]);

    $changes = app(InstallRulePack::class)->handle($team, rulePack('scanners'));

    expect($changes->updated)->toBe(['Secret and source hunters'])
        ->and($changes->kept)->toBe(['Wordlist sweep'])
        ->and($changes->added)->toBe(['CMS and admin probes that miss', '404s across your sites', 'Scanners work for it'])
        ->and($older->fresh())->name->toBe('Secret and source hunters')->source->toBe(rulePack('scanners')->rules()['secret-hunters']['source'])->duration_seconds->toBe(7 * 86400)
        ->and($edited->fresh()->source)->toContain('AND status = 404')
        ->and(RulePackInstall::query()->pluck('version')->unique()->all())->toBe([1])
        ->and(RulePackInstall::query()->where('item', 'secret-hunters')->sole()->isEdited())->toBeFalse()
        ->and(RulePackInstall::query()->where('item', 'wordlist-sweep')->sole()->isEdited())->toBeTrue();
});

test('installing the rest brings back a deleted rule, and the routine the team hasn\'t changed is pointed at it again', function () {
    $team = User::factory()->create()->currentTeam;
    app(InstallRulePack::class)->handle($team, rulePack('php-exploits'));
    $team->edgeRules()->where('name', 'Web shell lookups')->sole()->delete();

    $changes = app(InstallRulePack::class)->handle($team, rulePack('php-exploits'));

    $routine = $team->gymRoutines()->sole();

    expect($changes->added)->toBe(['Web shell lookups'])
        ->and($changes->updated)->toBe(['Exploit attempts are turned away'])
        ->and($routine->conditions[0]['values'])->toBe(collect(['PHP remote-code attempts', 'Web shell lookups', 'Reading server files'])->map(fn (string $name) => (string) $team->edgeRules()->where('name', $name)->sole()->id)->all())
        ->and($routine->enabled)->toBeFalse();
});

test('a routine saved in the gym unchanged still counts as the pack\'s', function () {
    $user = User::factory()->create();
    app(InstallRulePack::class)->handle($user->currentTeam, rulePack('browser-impostors'));
    $this->actingAs($user);

    foreach ($user->currentTeam->gymRoutines as $routine) {
        Livewire::test('pages::gym.edit', ['routine' => $routine])->call('save')->assertHasNoErrors();
    }

    expect(RulePackInstall::query()->whereNotNull('gym_routine_id')->with('routine')->get()->map->isEdited()->all())->toBe([false, false]);

    Livewire::test('pages::gym.edit', ['routine' => $user->currentTeam->gymRoutines()->first()])->set('passSeconds', 600)->call('save');

    expect(RulePackInstall::query()->whereNotNull('gym_routine_id')->with('routine')->orderBy('id')->get()->map->isEdited()->all())->toBe([true, false]);
});

test('uninstalling a pack deletes what the team hasn\'t changed and keeps what it has as its own', function () {
    $team = User::factory()->create()->currentTeam;
    app(InstallRulePack::class)->handle($team, rulePack('browser-impostors'));
    $team->edgeRules()->where('name', 'Headless browser')->sole()->update(['enabled' => false]);
    $team->edgeRules()->where('name', 'Outdated browser')->sole()->update(['source' => "TAG ip AS 'outdated-browser' FOR 1 DAY WHERE client = 'outdated'"]);

    $changes = app(UninstallRulePack::class)->handle($team, rulePack('browser-impostors'));

    expect($changes->removed)->toBe(['Spoofed browsers wait', 'Outdated browsers wait', 'Spoofed browser', 'Headless browser'])
        ->and($changes->kept)->toBe(['Outdated browser'])
        ->and($team->edgeRules()->pluck('name')->all())->toBe(['Outdated browser'])
        ->and($team->gymRoutines()->count())->toBe(0)
        ->and(RulePackInstall::query()->count())->toBe(0);
});
