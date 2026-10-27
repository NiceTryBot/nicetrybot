<?php

use App\Enums\ApiAbility;
use App\Enums\GymExit;
use App\Enums\TeamRole;
use App\Mcp\Tools\Gym\AddObstacle;
use App\Mcp\Tools\Gym\CreateGym;
use App\Mcp\Tools\Gym\DeleteGym;
use App\Mcp\Tools\Gym\ListGyms;
use App\Mcp\Tools\Gym\ListObstacleTypes;
use App\Mcp\Tools\Gym\RemoveObstacle;
use App\Mcp\Tools\Gym\UpdateGym;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\GymSession;
use App\Models\Team;
use App\Models\User;

test('a read-only token can look at gyms but not change them', function () {
    [$token] = apiToken();

    mcpAs($token)->tools()
        ->assertRegistered([ListGyms::class, ListObstacleTypes::class])
        ->assertNotRegistered([CreateGym::class, UpdateGym::class, AddObstacle::class, RemoveObstacle::class, DeleteGym::class]);
});

test('a token with the gym ability can\'t change gyms for a user who may not manage them', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    [$token] = apiToken([ApiAbility::ManageGym], $user);

    mcpAs($token)->tools()
        ->assertRegistered(ListGyms::class)
        ->assertNotRegistered([CreateGym::class, UpdateGym::class, AddObstacle::class, RemoveObstacle::class, DeleteGym::class]);
});

test('list-gyms shows the team\'s gyms in stack order with who cleared them this week', function () {
    [$token] = apiToken();
    $team = $token->team;
    $second = GymRoutine::factory()->for($team)->create(['name' => 'Bad actors', 'position' => 2]);
    $first = GymRoutine::factory()->for($team)->entering([['field' => 'country', 'operator' => 'in', 'values' => ['NL']]])->create(['name' => 'Netherlands gym', 'position' => 1]);
    GymRoutine::factory()->create(['name' => 'Their secret gym']);

    GymSession::factory()->cleared()->create(['team_id' => $team->id])->routines()->attach($first);
    GymSession::factory()->create(['team_id' => $team->id])->routines()->attach($first);
    GymSession::factory()->cleared()->create(['team_id' => $team->id, 'started_at' => now()->subDays(8)])->routines()->attach($first);
    GymSession::factory()->preview()->cleared()->create(['team_id' => $team->id])->routines()->attach($first);
    GymSession::factory()->cleared()->create()->routines()->attach(GymRoutine::factory()->create());

    mcpAs($token)->tool(ListGyms::class)
        ->assertOk()
        ->assertDontSee('Their secret gym')
        ->assertStructuredContent(fn ($json) => $json
            ->has('gyms', 2)
            ->where('gyms.0.id', $first->id)
            ->where('gyms.0.who_enters', 'Visitors whose country is Netherlands.')
            ->where('gyms.0.last_7_days', ['entered' => 2, 'cleared' => 1])
            ->where('gyms.0.exit_action', 'continue')
            ->where('gyms.1.id', $second->id)
            ->where('last_7_days.entered', 2)
            ->where('last_7_days.cleared', 1)
            ->etc());
});

test('list-obstacle-types describes each obstacle and its settings', function () {
    [$token] = apiToken();

    mcpAs($token)->tool(ListObstacleTypes::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('obstacle_types.0.type', 'proof-of-work')
            ->where('obstacle_types.0.can_escalate', true)
            ->where('obstacle_types.0.settings.0.key', 'difficulty')
            ->where('obstacle_types.0.settings.0.type', 'range')
            ->etc());
});

test('create-gym makes a gym for every visitor from the Netherlands', function () {
    [$token] = apiToken([ApiAbility::ManageGym]);
    GymRoutine::factory()->for($token->team)->create(['position' => 3]);

    mcpAs($token)->tool(CreateGym::class, [
        'name' => 'Netherlands gym',
        'conditions' => [['field' => 'country', 'operator' => 'in', 'values' => ['nl']]],
        'obstacles' => [
            ['type' => 'proof-of-work', 'config' => ['difficulty' => 99], 'escalate' => 'screen-code'],
            ['type' => 'waiting-room', 'config' => ['seconds' => 30]],
        ],
        'exit_action' => 'not_found',
        'pass_seconds' => 86400,
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('gym.who_enters', 'Visitors whose country is Netherlands.')
            ->where('gym.obstacles.0.config.difficulty', 26)
            ->etc());

    $gym = GymRoutine::query()->where('name', 'Netherlands gym')->sole();

    expect($gym->team_id)->toBe($token->team_id)
        ->and($gym->enabled)->toBeTrue()
        ->and($gym->position)->toBe(4)
        ->and($gym->match_all)->toBeTrue()
        ->and($gym->conditions)->toBe([['field' => 'country', 'operator' => 'in', 'values' => ['NL']]])
        ->and(array_column($gym->obstacles, 'type'))->toBe(['proof-of-work', 'waiting-room'])
        ->and($gym->obstacles[0]['escalate'])->toBe('screen-code')
        ->and($gym->obstacles[1]['config']['seconds'])->toBe(30)
        ->and($gym->exit_action)->toBe(GymExit::NotFound)
        ->and($gym->pass_seconds)->toBe(86400)
        ->and($gym->one_try)->toBeFalse();
});

test('create-gym refuses what the routine editor wouldn\'t save, saying what to fix', function (array $arguments, string $error) {
    [$token] = apiToken([ApiAbility::ManageGym]);

    mcpAs($token)->tool(CreateGym::class, ['name' => 'Broken', ...$arguments])->assertHasErrors([$error]);

    expect(GymRoutine::count())->toBe(0);
})->with([
    'unknown country' => [['conditions' => [['field' => 'country', 'values' => ['XX']]]], 'ISO 3166'],
    'operator the field doesn\'t take' => [['conditions' => [['field' => 'country', 'operator' => 'contains', 'values' => ['NL']]]], 'country takes the operator in or not_in'],
    'no values' => [['conditions' => [['field' => 'country', 'values' => []]]], 'give at least one value'],
    'unknown obstacle' => [['obstacles' => [['type' => 'moat']]], 'type must be one of'],
    'two of one obstacle' => [['obstacles' => [['type' => 'waiting-room'], ['type' => 'waiting-room']]], 'at most one of each obstacle type'],
    'made-up setting' => [['obstacles' => [['type' => 'waiting-room', 'config' => ['minutes' => 5]]]], 'waiting-room has no setting minutes'],
    'escalating an obstacle that never flags' => [['obstacles' => [['type' => 'waiting-room', 'escalate' => 'proof-of-work']]], 'never flags a visitor'],
    'escalating to an obstacle already there' => [['obstacles' => [['type' => 'proof-of-work', 'escalate' => 'waiting-room'], ['type' => 'waiting-room']]], 'already in the gym'],
    'made-up pass length' => [['pass_seconds' => 120], 'pass_seconds must be one of'],
    'made-up exit' => [['exit_action' => 'teleport'], 'exit_action must be one of'],
]);

test('a gym can take the verdicts of the team\'s rules, but not another team\'s', function () {
    [$token] = apiToken([ApiAbility::ManageGym]);
    $rule = EdgeRule::factory()->for($token->team)->create();
    $foreign = EdgeRule::factory()->create();

    mcpAs($token)->tool(CreateGym::class, ['name' => 'Theirs', 'conditions' => [['field' => 'verdict', 'values' => [$foreign->id]]]])
        ->assertHasErrors(['isn\'t a verdict value']);

    mcpAs($token)->tool(CreateGym::class, ['name' => 'Ours', 'conditions' => [['field' => 'verdict', 'values' => [$rule->id]]]])->assertOk();

    expect(GymRoutine::sole()->entry()->takesVerdictsFrom($rule->id))->toBeTrue();
});

test('update-gym changes only what it\'s given, and replaced obstacles keep their ids by type', function () {
    [$token] = apiToken([ApiAbility::ManageGym]);
    $gym = GymRoutine::factory()->for($token->team)->obstacles(['proof-of-work' => ['difficulty' => 14], 'waiting-room' => ['seconds' => 60]])->create(['name' => 'Bad actors']);
    [$proofOfWork] = $gym->obstacles;

    mcpAs($token)->tool(UpdateGym::class, [
        'id' => $gym->id,
        'obstacles' => [['type' => 'screen-code'], ['type' => 'proof-of-work', 'config' => ['difficulty' => 20]]],
        'one_try' => true,
    ])->assertOk();

    $gym->refresh();

    expect($gym->name)->toBe('Bad actors')
        ->and($gym->conditions)->toBe(GymRoutine::factory()->make()->conditions)
        ->and($gym->one_try)->toBeTrue()
        ->and(array_column($gym->obstacles, 'type'))->toBe(['screen-code', 'proof-of-work'])
        ->and($gym->obstacles[1]['id'])->toBe($proofOfWork['id'])
        ->and($gym->obstacles[1]['config']['difficulty'])->toBe(20);
});

test('add-obstacle puts an obstacle where it\'s asked, once', function () {
    [$token] = apiToken([ApiAbility::ManageGym]);
    $gym = GymRoutine::factory()->for($token->team)->obstacles(['proof-of-work' => [], 'screen-code' => []])->create(['name' => 'Bad actor']);

    mcpAs($token)->tool(AddObstacle::class, ['gym_id' => $gym->id, 'type' => 'waiting-room', 'config' => ['seconds' => 90], 'position' => 2])->assertOk();

    expect(array_column($gym->fresh()->obstacles, 'type'))->toBe(['proof-of-work', 'waiting-room', 'screen-code'])
        ->and($gym->fresh()->obstacles[1]['config']['seconds'])->toBe(90);

    mcpAs($token)->tool(AddObstacle::class, ['gym_id' => $gym->id, 'type' => 'waiting-room'])
        ->assertHasErrors(['already has a waiting-room']);

    expect($gym->fresh()->obstacles)->toHaveCount(3);
});

test('remove-obstacle takes one obstacle out by id', function () {
    [$token] = apiToken([ApiAbility::ManageGym]);
    $gym = GymRoutine::factory()->for($token->team)->obstacles(['proof-of-work' => [], 'waiting-room' => []])->create();
    [$proofOfWork, $waitingRoom] = $gym->obstacles;

    mcpAs($token)->tool(RemoveObstacle::class, ['gym_id' => $gym->id, 'obstacle_id' => 'nope'])
        ->assertHasErrors(['has no obstacle with id nope']);

    mcpAs($token)->tool(RemoveObstacle::class, ['gym_id' => $gym->id, 'obstacle_id' => $proofOfWork['id']])->assertOk();

    expect(array_column($gym->fresh()->obstacles, 'id'))->toBe([$waitingRoom['id']]);
});

test('delete-gym deletes the gym', function () {
    [$token] = apiToken([ApiAbility::ManageGym]);
    $gym = GymRoutine::factory()->for($token->team)->create();

    mcpAs($token)->tool(DeleteGym::class, ['id' => $gym->id])->assertOk();

    expect(GymRoutine::count())->toBe(0);
});

test('another team\'s gym can\'t be changed or deleted', function (string $tool, Closure $arguments) {
    [$token] = apiToken([ApiAbility::ManageGym]);
    $foreign = GymRoutine::factory()->create(['name' => 'Their secret gym']);
    $before = $foreign->fresh()->getAttributes();

    mcpAs($token)->tool($tool, $arguments($foreign))->assertHasErrors(['This team has no gym with id']);

    expect($foreign->fresh()?->getAttributes())->toBe($before);
})->with([
    'update' => [UpdateGym::class, fn (GymRoutine $gym) => ['id' => $gym->id, 'name' => 'Mine now']],
    'add obstacle' => [AddObstacle::class, fn (GymRoutine $gym) => ['gym_id' => $gym->id, 'type' => 'waiting-room']],
    'remove obstacle' => [RemoveObstacle::class, fn (GymRoutine $gym) => ['gym_id' => $gym->id, 'obstacle_id' => $gym->obstacles[0]['id']]],
    'delete' => [DeleteGym::class, fn (GymRoutine $gym) => ['id' => $gym->id]],
]);
