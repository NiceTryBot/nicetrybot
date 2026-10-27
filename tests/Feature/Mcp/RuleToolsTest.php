<?php

use App\Enums\ApiAbility;
use App\Enums\GymExit;
use App\Enums\RuleAction;
use App\Enums\TeamRole;
use App\Mcp\Tools\Rules\BacktestRule;
use App\Mcp\Tools\Rules\CreateRule;
use App\Mcp\Tools\Rules\DeleteRule;
use App\Mcp\Tools\Rules\DescribeRuleLanguage;
use App\Mcp\Tools\Rules\ListRules;
use App\Mcp\Tools\Rules\UpdateRule;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use App\Models\User;
use App\Models\Verdict;

test('a read-only token can look at rules but not change them', function () {
    [$token] = apiToken();

    mcpAs($token)->tools()
        ->assertRegistered([ListRules::class, BacktestRule::class, DescribeRuleLanguage::class])
        ->assertNotRegistered([CreateRule::class, UpdateRule::class, DeleteRule::class]);
});

test('a token with the rules ability can\'t change rules for a user who may not manage them', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);
    [$token] = apiToken([ApiAbility::ManageRules], $user);

    mcpAs($token)->tools()
        ->assertRegistered(ListRules::class)
        ->assertNotRegistered([CreateRule::class, UpdateRule::class, DeleteRule::class]);
});

test('list-rules shows how each of the team\'s rules is doing and which gyms take its verdicts', function () {
    [$token] = apiToken();
    $team = $token->team;
    $rule = EdgeRule::factory()->for($team)->create(['name' => 'Datacenter burst']);
    Verdict::factory()->create(['team_id' => $team->id, 'edge_rule_id' => $rule->id, 'matched_requests' => 4, 'last_matched_at' => now()->subHour()]);
    Verdict::factory()->expired()->create(['team_id' => $team->id, 'edge_rule_id' => $rule->id, 'matched_requests' => 2, 'last_matched_at' => now()->subDays(2)]);
    $gym = GymRoutine::factory()->for($team)->entering([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]]])->create(['name' => 'Bad actors']);
    GymRoutine::factory()->for($team)->create(['name' => 'Datacenter waiting room']);
    EdgeRule::factory()->create(['name' => 'Their secret rule']);

    mcpAs($token)->tool(ListRules::class)
        ->assertOk()
        ->assertDontSee('Their secret rule')
        ->assertStructuredContent(fn ($json) => $json
            ->has('rules', 1)
            ->where('rules.0.name', 'Datacenter burst')
            ->where('rules.0.action', 'ban')
            ->where('rules.0.active_verdicts', 1)
            ->where('rules.0.total_verdicts', 2)
            ->where('rules.0.matched_requests', 6)
            ->where('rules.0.gyms', [['id' => $gym->id, 'name' => 'Bad actors', 'enabled' => true]])
            ->etc());
});

test('list-rules won\'t show another team\'s rule by id', function () {
    [$token] = apiToken();
    $foreign = EdgeRule::factory()->create(['name' => 'Their secret rule']);

    mcpAs($token)->tool(ListRules::class, ['id' => $foreign->id])
        ->assertHasErrors(["This team has no rule with id {$foreign->id}"])
        ->assertDontSee('Their secret rule');
});

test('backtest-rule says who a draft would have matched, without saving anything', function () {
    [$token] = apiToken();
    $domain = Domain::factory()->for($token->team)->create();
    EdgeRequest::factory()->for($domain)->count(2)->create(['client_ip' => '203.0.113.7', 'country_code' => 'NL']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.4', 'country_code' => 'US']);
    EdgeRequest::factory()->for(Domain::factory())->create(['client_ip' => '192.0.2.1', 'country_code' => 'NL']);

    mcpAs($token)->tool(BacktestRule::class, ['source' => "TAG ip AS 'dutch' FOR 1 DAY WHERE country = 'NL'"])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('days', 7)
            ->where('clients_matched', 1)
            ->where('requests_matched', 2)
            ->where('busiest_clients.0.client_ip', '203.0.113.7')
            ->etc());

    expect(EdgeRule::count())->toBe(0);
});

test('a script that doesn\'t parse says where it breaks', function (string $tool, array $arguments) {
    [$token] = apiToken([ApiAbility::ManageRules]);

    mcpAs($token)->tool($tool, [...$arguments, 'source' => "BAN ip FOR 1 HOUR\nWHERE stauts = 404"])
        ->assertHasErrors(['Line 2, column 7', 'Did you mean status?']);

    expect(EdgeRule::count())->toBe(0);
})->with([
    'backtest' => [BacktestRule::class, []],
    'create' => [CreateRule::class, ['name' => 'Broken']],
]);

test('create-rule saves a rule that judges traffic from now on', function () {
    [$token] = apiToken([ApiAbility::ManageRules]);
    $existing = EdgeRequest::factory()->for(Domain::factory()->for($token->team))->create();

    mcpAs($token)->tool(CreateRule::class, ['name' => 'Dutch visitors', 'source' => "TAG ip, ja4 AS 'dutch' FOR 2 DAYS WHERE country = 'NL'", 'enabled' => false])
        ->assertOk()
        ->assertDontSee('"gym"');

    $rule = EdgeRule::sole();

    expect($rule->team_id)->toBe($token->team_id)
        ->and($rule->name)->toBe('Dutch visitors')
        ->and($rule->enabled)->toBeFalse()
        ->and($rule->action)->toBe(RuleAction::Tag)
        ->and($rule->duration_seconds)->toBe(2 * 86400)
        ->and($rule->last_request_id)->toBe($existing->id);
});

test('create-rule sends the rule\'s clients to a new gym by name', function () {
    [$token] = apiToken([ApiAbility::ManageRules, ApiAbility::ManageGym]);

    mcpAs($token)->tool(CreateRule::class, ['name' => 'Dutch visitors', 'source' => "TAG ip AS 'dutch' FOR 1 DAY WHERE country = 'NL'", 'gym_name' => 'Netherlands gym'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('gym.linked', true)
            ->where('gym.gym.name', 'Netherlands gym')
            ->where('gym.gym.created', true)
            ->etc());

    $rule = EdgeRule::sole();
    $gym = GymRoutine::sole();

    expect($gym->team_id)->toBe($token->team_id)
        ->and($gym->entry()->takesVerdictsFrom($rule->id))->toBeTrue()
        ->and($gym->obstacles)->not->toBeEmpty();
});

test('create-rule sends the rule\'s clients to one of the team\'s gyms by id', function () {
    [$token] = apiToken([ApiAbility::ManageRules, ApiAbility::ManageGym]);
    $gym = GymRoutine::factory()->for($token->team)->entering([['field' => 'verdict', 'operator' => 'in', 'values' => ['999']]])->create(['name' => 'Bad actors']);

    mcpAs($token)->tool(CreateRule::class, ['name' => 'Probes', 'source' => "BAN ip FOR 1 DAY WHERE category = 'probe'", 'gym_id' => $gym->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('gym.linked', true)->where('gym.gym.created', false)->etc());

    expect($gym->fresh()->conditions[0]['values'])->toBe(['999', (string) EdgeRule::sole()->id]);
});

test('create-rule saves the rule but says why it couldn\'t send its clients to the gym', function (Closure $setUp, string $reason) {
    [$token, $gym] = $setUp();

    mcpAs($token)->tool(CreateRule::class, ['name' => 'Probes', 'source' => "BAN ip FOR 1 DAY WHERE category = 'probe'", 'gym_id' => $gym->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('gym.linked', false)->etc())
        ->assertSee($reason);

    expect(EdgeRule::count())->toBe(1)
        ->and($gym->fresh()->conditions)->toBe($gym->conditions);
})->with([
    'token can\'t change gyms' => [function () {
        [$token] = apiToken([ApiAbility::ManageRules]);

        return [$token, GymRoutine::factory()->for($token->team)->create()];
    }, 'this token can\'t change gyms'],
    'another team\'s gym' => [function () {
        [$token] = apiToken([ApiAbility::ManageRules, ApiAbility::ManageGym]);

        return [$token, GymRoutine::factory()->create()];
    }, 'this team has no gym with id'],
    'gym that can\'t take it' => [function () {
        [$token] = apiToken([ApiAbility::ManageRules, ApiAbility::ManageGym]);

        return [$token, GymRoutine::factory()->for($token->team)->entering([
            ['field' => 'network', 'operator' => 'in', 'values' => ['digitalocean', 'hetzner']],
            ['field' => 'country', 'operator' => 'in', 'values' => ['NL']],
        ])->create()];
    }, 'can\'t take it without changing who else enters'],
]);

test('update-rule changes only what it\'s given', function () {
    [$token] = apiToken([ApiAbility::ManageRules]);
    $rule = EdgeRule::factory()->for($token->team)->create(['name' => 'Probe scanners']);
    $source = $rule->source;

    mcpAs($token)->tool(UpdateRule::class, ['id' => $rule->id, 'enabled' => false])->assertOk();

    expect($rule->fresh())
        ->enabled->toBeFalse()
        ->name->toBe('Probe scanners')
        ->source->toBe($source);
});

test('update-rule with a new script lifts the verdicts it no longer reaches only when asked', function (bool $lift) {
    [$token] = apiToken([ApiAbility::ManageRules]);
    $rule = EdgeRule::factory()->for($token->team)->create();
    $verdict = Verdict::factory()->create(['team_id' => $token->team_id, 'edge_rule_id' => $rule->id]);

    mcpAs($token)->tool(UpdateRule::class, ['id' => $rule->id, 'source' => "TAG ip AS 'probe' FOR 1 DAY WHERE category = 'probe'", 'lift_stale_verdicts' => $lift])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('stale_verdicts', ['count' => 1, 'lifted' => $lift])->etc());

    expect($rule->fresh()->action)->toBe(RuleAction::Tag)
        ->and($verdict->fresh()->isActive())->toBe(! $lift);
})->with([true, false]);

test('another team\'s rule can\'t be changed or deleted', function (string $tool, array $arguments) {
    [$token] = apiToken([ApiAbility::ManageRules]);
    $foreign = EdgeRule::factory()->create(['name' => 'Their secret rule']);

    mcpAs($token)->tool($tool, ['id' => $foreign->id, ...$arguments])->assertHasErrors(['This team has no rule with id']);

    expect($foreign->fresh())->not->toBeNull()->name->toBe('Their secret rule');
})->with([
    'update' => [UpdateRule::class, ['name' => 'Mine now']],
    'delete' => [DeleteRule::class, []],
]);

test('delete-rule deletes the rule and names the gyms still waiting on its verdicts', function () {
    [$token] = apiToken([ApiAbility::ManageRules]);
    $rule = EdgeRule::factory()->for($token->team)->create();
    $gym = GymRoutine::factory()->for($token->team)->entering([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]]])->create(['name' => 'Bad actors', 'exit_action' => GymExit::Block]);

    mcpAs($token)->tool(DeleteRule::class, ['id' => $rule->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('gyms_still_naming_it', [['id' => $gym->id, 'name' => 'Bad actors']])->etc());

    expect(EdgeRule::count())->toBe(0);
});

test('describe-rule-language gives the grammar and the fields', function () {
    [$token] = apiToken();

    mcpAs($token)->tool(DescribeRuleLanguage::class)
        ->assertOk()
        ->assertSee(['BAN | TAG', 'country']);
});
