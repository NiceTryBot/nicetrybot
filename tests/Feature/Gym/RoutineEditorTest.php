<?php

use App\Actions\Gym\BacktestGymRoutine;
use App\Data\RuleBacktest;
use App\Enums\GymExit;
use App\Enums\TeamRole;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\GymSession;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('a template becomes a saved routine', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::withQueryParams(['template' => 'probe-scanners'])
        ->test('pages::gym.edit')
        ->assertSee('Reads as')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('gym.routines.edit', ['routine' => GymRoutine::sole()]));

    expect(GymRoutine::sole())
        ->team_id->toBe($user->currentTeam->id)
        ->name->toBe('Probe scanners')
        ->conditions->toBe([['field' => 'category', 'operator' => 'in', 'values' => ['probe']]])
        ->exit_action->toBe(GymExit::NotFound)
        ->and(GymRoutine::sole()->obstacles[0])->toMatchArray(['type' => 'proof-of-work', 'config' => ['difficulty' => 20, 'display' => 'quiet'], 'escalate' => 'waiting-room']);
});

test('conditions and obstacles built in the editor are saved in pipeline order', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::gym.edit')
        ->set('name', 'Scripts from clouds')
        ->call('addCondition', 'network')
        ->call('addValue', 0, 'hetzner')
        ->call('addCondition', 'ua')
        ->set('drafts.1', 'python, curl')
        ->call('addDraft', 1)
        ->call('addObstacle', 'proof-of-work')
        ->call('addObstacle', 'screen-code');
    $codeId = $component->get('obstacles')[1]['id'];
    $component->call('sortObstacle', $codeId, 0)
        ->set('obstacles.1.config.difficulty', '99')
        ->call('save')
        ->assertHasNoErrors();

    $routine = GymRoutine::sole();
    expect($routine->conditions)->toBe([
        ['field' => 'network', 'operator' => 'in', 'values' => ['hetzner']],
        ['field' => 'ua', 'operator' => 'contains', 'values' => ['python', 'curl']],
    ])
        ->and(array_column($routine->obstacles, 'type'))->toBe(['screen-code', 'proof-of-work'])
        ->and($routine->obstacles[1]['config']['difficulty'])->toBe(26);
});

test('a condition without values stops the save and says why', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::gym.edit')
        ->set('name', 'Half done')
        ->call('addCondition', 'country')
        ->call('save')
        ->assertHasErrors(['conditions.0'])
        ->assertSee('Pick at least one value, or remove this condition.');

    expect(GymRoutine::count())->toBe(0);
});

test('a typed value that isn\'t valid for its field is refused', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::gym.edit')
        ->call('addCondition', 'ip')
        ->set('drafts.0', '203.0.113.999')
        ->call('addDraft', 0)
        ->assertHasErrors(['conditions.0'])
        ->assertSet('conditions.0.values', []);
});

test('trying a routine starts a preview of the unsaved draft', function () {
    $user = User::factory()->create();
    $routine = GymRoutine::factory()->for($user->currentTeam)->create(['name' => 'Saved name']);
    $this->actingAs($user);

    Livewire::test('pages::gym.edit', ['routine' => $routine])
        ->set('name', 'Draft name')
        ->call('tryIt')
        ->assertReturned(fn (string $url) => $url === route('gym.show', GymSession::sole()));

    $preview = GymSession::sole();
    expect($preview->is_preview)->toBeTrue()
        ->and($preview->draft[0]['name'])->toBe('Draft name')
        ->and($routine->fresh()->name)->toBe('Saved name');
});

test('members can read a routine but not change it', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);
    $routine = GymRoutine::factory()->for($team)->create(['name' => 'Team routine']);
    $this->actingAs($member);

    Livewire::test('pages::gym.edit', ['routine' => $routine])
        ->assertSee('Team routine')
        ->assertDontSee('Save routine')
        ->set('name', 'Renamed')
        ->call('save')
        ->assertForbidden();

    expect($routine->fresh()->name)->toBe('Team routine');
});

test('another team\'s routine cannot be opened', function () {
    $foreign = GymRoutine::factory()->create(['name' => 'Their secret routine']);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::gym.edit', ['routine' => $foreign])
        ->assertNotFound();
});

test('a routine can be deleted', function () {
    $user = User::factory()->create();
    $routine = GymRoutine::factory()->for($user->currentTeam)->create();
    $this->actingAs($user);

    Livewire::test('pages::gym.edit', ['routine' => $routine])
        ->call('delete')
        ->assertRedirect(route('gym.index'));

    $this->assertModelMissing($routine);
});

test('a routine\'s pass can last as little as a minute, but not a made-up length', function () {
    $user = User::factory()->create();
    $routine = GymRoutine::factory()->for($user->currentTeam)->create(['pass_seconds' => 3600]);
    $this->actingAs($user);

    $page = Livewire::test('pages::gym.edit', ['routine' => $routine])
        ->assertSee('1 minute')
        ->set('passSeconds', 60)
        ->call('save')
        ->assertHasNoErrors();

    expect($routine->fresh()->pass_seconds)->toBe(60);

    $page->set('passSeconds', 30)->call('save')->assertHasErrors('passSeconds');

    expect($routine->fresh()->pass_seconds)->toBe(60);
});

test('a routine\'s pass can be limited to one form submission', function () {
    $user = User::factory()->create();
    $routine = GymRoutine::factory()->for($user->currentTeam)->create();
    $this->actingAs($user);

    Livewire::test('pages::gym.edit', ['routine' => $routine])
        ->assertSet('oneTry', false)
        ->set('oneTry', true)
        ->assertSee('trains again before the next')
        ->call('save')
        ->assertHasNoErrors();

    expect($routine->fresh()->one_try)->toBeTrue();
});

test('a rule opens a new routine for the clients it flags', function () {
    $user = User::factory()->create();
    $rule = EdgeRule::factory()->for($user->currentTeam)->create(['name' => 'Failed logins']);

    $this->actingAs($user);

    Livewire::withQueryParams(['rule' => $rule->id])
        ->test('pages::gym.edit')
        ->assertSet('name', 'Failed logins')
        ->call('save')
        ->assertHasNoErrors();

    expect(GymRoutine::sole()->conditions)->toBe([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]]])
        ->and(array_column(GymRoutine::sole()->obstacles, 'type'))->toBe(['browser-check', 'proof-of-work']);
});

test('a verdict condition links to its rule', function () {
    $user = User::factory()->create();
    $rule = EdgeRule::factory()->for($user->currentTeam)->create(['name' => 'Failed logins']);

    $this->actingAs($user);

    Livewire::withQueryParams(['rule' => $rule->id])
        ->test('pages::gym.edit')
        ->assertSeeHtml('href="'.e(route('rules.index', ['rule' => $rule->id])).'"');
});

test('another team\'s rule doesn\'t fill a new routine', function () {
    $foreignRule = EdgeRule::factory()->create(['name' => 'Their secret rule']);

    $this->actingAs(User::factory()->create());

    Livewire::withQueryParams(['rule' => $foreignRule->id])
        ->test('pages::gym.edit')
        ->assertSet('name', '')
        ->assertSet('conditions', []);
});

test('the editor opens before the week\'s backtest, which runs again only when who enters changes', function () {
    $user = User::factory()->create();
    $routine = GymRoutine::factory()->for($user->currentTeam)->create();
    $this->actingAs($user);
    $this->mock(BacktestGymRoutine::class, fn ($mock) => $mock->shouldReceive('handle')->twice()->andReturn(new RuleBacktest(7, 0, 0, 0, [])));

    Livewire::test('pages::gym.edit', ['routine' => $routine])
        ->assertSeeHtml('data-test="routine-backtest-pending"')
        ->call('loadBacktest')
        ->assertSeeHtml('data-test="routine-backtest"')
        ->call('select', $routine->obstacles[0]['id'] ?? 'none')
        ->call('addObstacle', 'screen-code')
        ->call('addCondition', 'network')
        ->call('addValue', 0, 'hetzner')
        ->assertSeeHtml('data-test="routine-backtest-pending"')
        ->call('loadBacktest')
        ->assertSeeHtml('data-test="routine-backtest"');
});
