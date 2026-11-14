<?php

use App\Enums\GymExit;
use App\Enums\RuleAction;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use App\Models\User;
use App\Support\RuleDestinations;
use Livewire\Livewire;

/**
 * The names of the rules in each column of a team's board, by column key.
 *
 * @return array<string, list<string>>
 */
function ruleBoard(Team $team): array
{
    return collect(app(RuleDestinations::class)->board($team, $team->edgeRules()->orderBy('name')->get()))
        ->mapWithKeys(fn (array $lane) => [$lane['column']->key => array_map(fn (array $card) => $card['rule']->name, $lane['cards'])])
        ->all();
}

/**
 * @param  list<array{field: string, operator: string, values: list<string>}>  $conditions
 */
function routineFor(EdgeRule $rule, GymExit $exit, bool $enabled = true, array $conditions = []): GymRoutine
{
    return GymRoutine::factory()->for($rule->team)->create([
        'enabled' => $enabled,
        'exit_action' => $exit,
        'conditions' => [['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]], ...$conditions],
    ]);
}

test('a rule goes in the column of what acts on its clients, whatever its action says', function () {
    $team = Team::factory()->create();
    $tagged = EdgeRule::factory()->for($team)->create(['name' => 'Just tags', 'action' => RuleAction::Tag]);
    $idleBan = EdgeRule::factory()->for($team)->create(['name' => 'Idle ban', 'action' => RuleAction::Block]);
    $trained = EdgeRule::factory()->for($team)->create(['name' => 'Trained', 'action' => RuleAction::Tag]);
    $blocked = EdgeRule::factory()->for($team)->create(['name' => 'Blocked by routine', 'action' => RuleAction::Tag]);
    $notFound = EdgeRule::factory()->for($team)->create(['name' => 'Sent a 404', 'action' => RuleAction::Block]);
    routineFor($trained, GymExit::Continue);
    routineFor($blocked, GymExit::Block);
    routineFor($notFound, GymExit::NotFound);

    expect(ruleBoard($team))->toMatchArray([
        'tag' => ['Idle ban', 'Just tags'],
        'gym' => ['Trained'],
        'blocked' => ['Blocked by routine', 'Sent a 404'],
    ]);
});

test('a routine that is off leaves its rule in Tag only, linked', function () {
    $team = Team::factory()->create();
    $rule = EdgeRule::factory()->for($team)->create(['name' => 'Waiting on a routine']);
    routineFor($rule, GymExit::Block, enabled: false);

    $board = app(RuleDestinations::class)->board($team, $team->edgeRules()->get());

    expect(ruleBoard($team)['tag'])->toBe(['Waiting on a routine'])
        ->and(ruleBoard($team)['blocked'])->toBe([])
        ->and($board[0]['cards'][0]['links'][0]->column)->toBeNull();
});

test('a rule in two routines of the same kind is one card, naming both, and what scopes them', function () {
    $team = Team::factory()->create();
    $rule = EdgeRule::factory()->for($team)->create(['name' => 'Twice']);
    routineFor($rule, GymExit::Block);
    routineFor($rule, GymExit::Block, conditions: [['field' => 'domain', 'operator' => 'not_in', 'values' => ['a.example', 'b.example']]]);

    $cards = collect(app(RuleDestinations::class)->board($team, $team->edgeRules()->get()))
        ->firstWhere(fn (array $lane) => $lane['column']->key === 'blocked')['cards'];

    expect($cards)->toHaveCount(1)
        ->and($cards[0]['destinations'])->toHaveCount(2)
        ->and($cards[0]['destinations'][1]->note)->toBe('Skips 2 domains');
});

test('another team\'s routine naming the rule does not move it', function () {
    $team = Team::factory()->create();
    $rule = EdgeRule::factory()->for($team)->create(['name' => 'Mine']);
    GymRoutine::factory()->create(['conditions' => [['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]]]]);

    expect(ruleBoard($team)['tag'])->toBe(['Mine']);
});

test('the page opens on the board, and opening a rule from it brings the editor modal', function () {
    $user = User::factory()->create();
    $rule = EdgeRule::factory()->for($user->currentTeam)->create(['name' => 'Idle ban', 'action' => RuleAction::Block]);
    $this->actingAs($user);

    // A team with only a rule nothing acts on opens on Tags; Timeouts is a click away.
    Livewire::test('pages::rules.index')
        ->assertSet('tab', 'tags')
        ->assertSeeHtml('data-test="rule-board"')
        ->assertSeeHtml('data-test="rule-card-idle-block"')
        ->assertDontSeeHtml('data-test="board-column-blocked"')
        ->set('tab', 'timeouts')
        ->assertSeeHtml('data-test="board-column-blocked"')
        ->assertDontSeeHtml('data-test="rule-card-idle-block"')
        ->set('tab', 'tags')
        ->assertSet('isEditing', false)
        ->call('edit', $rule->id)
        ->assertSet('isEditing', true)
        ->assertSeeHtml('data-test="rule-editor"')
        ->assertSeeHtml('data-test="rule-name"')
        ->assertSeeHtml('data-test="rule-board"') // the board stays in view behind the modal
        ->call('cancel')
        ->assertSet('isEditing', false)
        ->assertSeeHtml('data-test="rule-board"');
});

test('the list stays a click away, and a team without rules starts there, with the templates', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::rules.index')
        ->assertDontSeeHtml('data-test="rule-board"')
        ->assertSee('Start from a template');

    EdgeRule::factory()->for($user->currentTeam)->create();

    Livewire::test('pages::rules.index')
        ->assertSeeHtml('data-test="rule-board"')
        ->set('view', 'list')
        ->assertDontSeeHtml('data-test="rule-board"')
        ->assertSee('Start from a template');
});
