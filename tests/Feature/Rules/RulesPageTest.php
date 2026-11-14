<?php

use App\Enums\RuleAction;
use App\Enums\TeamRole;
use App\Models\AiCredential;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use App\Models\User;
use App\Models\Verdict;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('a template becomes a saved rule that starts watching from now', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $existing = EdgeRequest::factory()->for($domain)->create();

    $this->actingAs($user);

    Livewire::test('pages::rules.index')
        ->call('newRule', 'probe-scanners')
        ->assertSee('Reads as')
        ->call('save')
        ->assertHasNoErrors();

    $rule = EdgeRule::sole();

    expect($rule->team_id)->toBe($user->currentTeam->id)
        ->and($rule->name)->toBe('Probe scanners')
        ->and($rule->action)->toBe(RuleAction::Block)
        ->and($rule->duration_seconds)->toBe(30 * 86400)
        ->and($rule->last_request_id)->toBe($existing->id);
});

test('a rule that does not parse shows where it breaks and is not saved', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::rules.index')
        ->call('newRule')
        ->set('name', 'Broken')
        ->set('source', "BLOCK ip FOR 1 HOUR\nWHERE stauts = 404")
        ->assertSee('Line 2, column 7')
        ->assertSee('Did you mean status?')
        ->call('save')
        ->assertHasErrors('source');

    expect(EdgeRule::count())->toBe(0);
});

test('members can read rules but not write them', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $this->actingAs($member);

    Livewire::test('pages::rules.index')
        ->assertDontSee('New rule')
        ->call('newRule', 'probe-scanners')
        ->assertForbidden();
});

test('another team\'s rule cannot be opened', function () {
    $foreignRule = EdgeRule::factory()->create(['name' => 'Their secret rule']);

    $this->actingAs(User::factory()->create());

    Livewire::test('pages::rules.index')
        ->call('edit', $foreignRule->id)
        ->assertStatus(404)
        ->assertDontSee('Their secret rule');
});

test('an active verdict can be lifted', function () {
    $user = User::factory()->create();
    $verdict = Verdict::factory()->create(['team_id' => $user->currentTeam->id, 'client_ip' => '203.0.113.7']);

    $this->actingAs($user);

    Livewire::test('pages::rules.index', ['tab' => 'verdicts'])
        ->set('tab', 'verdicts')
        ->assertSee('203.0.113.7')
        ->call('lift', $verdict->id)
        ->assertDontSee('203.0.113.7');

    expect($verdict->fresh()->lifted_at)->not->toBeNull();
});

test('a draft passed in the link opens in the editor for people who can write rules', function () {
    $user = User::factory()->create();
    $draft = "TAG ip AS 'drafted-from-activity' FOR 1 DAY WHERE path LIKE '%/wp-login%'";

    $this->actingAs($user)
        ->get(route('rules.index', ['draft' => $draft]))
        ->assertOk()
        ->assertSee('drafted-from-activity');

    $this->get(route('rules.index'))->assertDontSee('drafted-from-activity');
});

test('a draft from Activity opens in the editor with the name it suggests', function () {
    $this->actingAs(User::factory()->create());

    Livewire::withQueryParams(['draft' => "TAG ip AS 'skipped-home' FOR 1 DAY\nWHERE host = 'settlement.example'", 'name' => 'Thank-you without the homepage'])
        ->test('pages::rules.index')
        ->assertSet('isEditing', true)
        ->assertSet('name', 'Thank-you without the homepage')
        ->assertSet('source', "TAG ip AS 'skipped-home' FOR 1 DAY\nWHERE host = 'settlement.example'");
});

test('saving a GYM clause sends the rule\'s clients through a routine, made by name', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::rules.index')
        ->call('newRule')
        ->set('name', 'Gymbros')
        ->set('source', "TAG ip AS 'probe' FOR 1 DAY\nGYM 'Gymbros'\nWHERE category = 'probe'")
        ->call('save')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="rule-destination"');

    $routine = $user->currentTeam->gymRoutines()->where('name', 'Gymbros')->sole();
    expect($routine->entry()->takesVerdictsFrom(EdgeRule::sole()->id))->toBeTrue();
});

test('naming a gym routine that can\'t take the rule is refused, and the rule is not saved', function () {
    $user = User::factory()->create();
    $conditions = [['field' => 'network', 'operator' => 'in', 'values' => ['hetzner']], ['field' => 'country', 'operator' => 'in', 'values' => ['DE']]];
    $routine = GymRoutine::factory()->for($user->currentTeam)->entering($conditions)->create(['name' => 'German clouds']);

    $this->actingAs($user);

    Livewire::test('pages::rules.index')
        ->call('newRule')
        ->set('name', 'Probes')
        ->set('source', "TAG ip AS 'probe' FOR 1 DAY\nGYM 'German clouds'\nWHERE category = 'probe'")
        ->call('save')
        ->assertHasErrors('source')
        ->assertSee('can’t take this rule without changing who else enters');

    expect($routine->fresh()->conditions)->toBe($conditions)
        ->and(EdgeRule::count())->toBe(0);
});

test('a GYM name the team already uses joins that routine instead of making another', function () {
    $user = User::factory()->create();
    $routine = GymRoutine::factory()->for($user->currentTeam)->entering([['field' => 'path', 'operator' => 'contains', 'values' => ['phpmyadmin']]])->create(['name' => 'PhpMyAdmin Gym']);

    $this->actingAs($user);

    Livewire::test('pages::rules.index')
        ->call('newRule')
        ->set('name', 'Probes')
        ->set('source', "TAG ip AS 'probe' FOR 1 DAY\nGYM 'phpmyadmin gym'\nWHERE category = 'probe'")
        ->call('save')
        ->assertHasNoErrors();

    expect(GymRoutine::count())->toBe(1)
        ->and($routine->fresh()->match_all)->toBeFalse();
});

test('members see where a rule\'s clients go, read-only', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);
    $rule = EdgeRule::factory()->for($team)->create();
    GymRoutine::factory()->for($team)->entering([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]]])->create(['name' => 'Flagged clients']);

    $this->actingAs($member);

    Livewire::test('pages::rules.index')
        ->call('edit', $rule->id)
        ->assertSeeHtml('data-test="rule-destinations"')
        ->assertSee('Flagged clients');
});

test('the destinations warn while no domain sends anyone to Enforce', function () {
    $user = User::factory()->create();
    $rule = EdgeRule::factory()->for($user->currentTeam)->create();
    GymRoutine::factory()->for($user->currentTeam)->entering([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]]])->create(['name' => 'Waiting room']);
    Domain::factory()->for($user->currentTeam)->verified()->create();

    $this->actingAs($user);

    $component = Livewire::test('pages::rules.index')
        ->call('edit', $rule->id)
        ->assertSee('None of your domains is in Enforce mode');

    Domain::factory()->for($user->currentTeam)->verified()->enforcing()->create();

    $component->call('edit', $rule->id)->assertDontSee('None of your domains is in Enforce mode');
});

const ASKED_PHPMYADMIN_RULE = "TAG ip, ja4 AS 'phpmyadmin-probe' FOR 1 DAY\nWHERE host = 'mysite.com' AND path LIKE '%phpmyadmin%'";

const ASKED_PHPMYADMIN_GYM_RULE = "TAG ip, ja4 AS 'phpmyadmin-probe' FOR 1 DAY\nGYM 'PhpMyAdmin Gym'\nWHERE host = 'mysite.com' AND path LIKE '%phpmyadmin%'";

test('a rule asked for in plain English waits in the editor, then its destination is set on save', function () {
    $user = User::factory()->create();
    AiCredential::factory()->for($user)->create();
    Domain::factory()->for($user->currentTeam)->create(['hostname' => 'mysite.com']);
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeRuleAnswer([
        'name' => 'phpMyAdmin probes on mysite.com',
        'source' => ASKED_PHPMYADMIN_GYM_RULE,
        'note' => 'Matched any path with phpmyadmin in it, not just phpmyadmin.php.',
    ]))]);

    $this->actingAs($user);

    $component = Livewire::test('pages::rules.index')
        ->set('question', 'send everyone on mysite.com who hits phpmyadmin.php to the PhpMyAdmin Gym')
        ->call('ask')
        ->assertSet('name', 'phpMyAdmin probes on mysite.com')
        ->assertSet('source', ASKED_PHPMYADMIN_GYM_RULE)
        ->assertSee('Matched any path with phpmyadmin in it, not just phpmyadmin.php.');

    expect(EdgeRule::count())->toBe(0);
    expect(GymRoutine::count())->toBe(0);
    Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], "<hostnames>\nmysite.com\n</hostnames>"));

    $component->call('save')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="rule-destination"');

    expect(GymRoutine::sole())
        ->name->toBe('PhpMyAdmin Gym')
        ->team_id->toBe($user->currentTeam->id)
        ->enabled->toBeTrue()
        ->conditions->toBe([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) EdgeRule::sole()->id]]]);
});

test('a gym routine Claude names in the script takes the rule on save', function () {
    $user = User::factory()->create();
    AiCredential::factory()->for($user)->create();
    $routine = GymRoutine::factory()->for($user->currentTeam)->entering([['field' => 'path', 'operator' => 'starts_with', 'values' => ['/login']]])->create(['name' => 'Login wall']);
    $source = "TAG ip, ja4 AS 'phpmyadmin-probe' FOR 1 DAY\nGYM 'Login wall'\nWHERE path LIKE '%phpmyadmin%'";
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(claudeRuleAnswer(['name' => 'phpMyAdmin probes', 'source' => $source]))]);

    $this->actingAs($user);

    Livewire::test('pages::rules.index')
        ->set('question', 'put phpmyadmin probes through the login wall')
        ->call('ask')
        ->assertSet('source', $source)
        ->call('save')
        ->assertHasNoErrors();

    expect($routine->fresh()->conditions)->toBe([
        ['field' => 'path', 'operator' => 'starts_with', 'values' => ['/login']],
        ['field' => 'verdict', 'operator' => 'in', 'values' => [(string) EdgeRule::sole()->id]],
    ]);
    expect(GymRoutine::count())->toBe(1);
    Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], "{$routine->id}: Login wall. Visitors whose path starts with “/login”."));
});

test('asking again changes the unsaved draft instead of starting another', function () {
    $user = User::factory()->create();
    AiCredential::factory()->for($user)->create();
    $longer = str_replace('FOR 1 DAY', 'FOR 7 DAYS', ASKED_PHPMYADMIN_RULE);
    Http::preventStrayRequests();
    Http::fakeSequence('api.anthropic.com/v1/messages')
        ->push(claudeRuleAnswer(['name' => 'phpMyAdmin probes', 'source' => ASKED_PHPMYADMIN_RULE]))
        ->push(claudeRuleAnswer(['name' => 'phpMyAdmin probes', 'source' => $longer]));

    $this->actingAs($user);

    Livewire::test('pages::rules.index')
        ->set('question', 'tag phpmyadmin probes')
        ->call('ask')
        ->set('question', 'make it a week')
        ->call('ask')
        ->assertSet('source', $longer);

    Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], "<current_rule>\n".ASKED_PHPMYADMIN_RULE."\n</current_rule>")
        && str_contains($request['messages'][0]['content'], '<destinations>')
        && str_contains($request['messages'][0]['content'], 'GYM'));
});

test('Claude\'s errors show beside the ask box and leave the editor closed', function () {
    $user = User::factory()->create();
    AiCredential::factory()->for($user)->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401)]);

    $this->actingAs($user);

    Livewire::test('pages::rules.index')
        ->set('question', 'tag phpmyadmin probes')
        ->call('ask')
        ->assertSet('isEditing', false)
        ->assertSee(['Anthropic didn’t accept your API key. Check it in', 'Settings → Artificial Intelligence'])
        ->assertSeeHtml('href="'.route('ai.edit').'"');
});

test('without an Anthropic key the ask box says how to connect Claude', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::rules.index')
        ->assertSeeHtml('data-test="rule-ask-connect"')
        ->assertDontSeeHtml('data-test="rule-question"');
});

test('members cannot ask Claude for rules', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);
    AiCredential::factory()->for($member)->create();
    Http::preventStrayRequests();
    Http::fake();

    $this->actingAs($member);

    Livewire::test('pages::rules.index')
        ->assertDontSeeHtml('data-test="rule-ask"')
        ->set('question', 'tag phpmyadmin probes')
        ->call('ask')
        ->assertForbidden();

    Http::assertNothingSent();
});

test('the rule in the editor links to the traffic it acts on in Activity', function () {
    $this->actingAs(User::factory()->create());

    $page = Livewire::test('pages::rules.index')
        ->call('newRule')
        ->set('source', "TAG ip, ja4 AS 'non-us' FOR 1 DAY\nWHERE host = 'sign.example'\nAND country != 'US'")
        ->assertSeeHtml('target="_blank"');

    expect($page->instance()->activityUrl)->toBe(route('activity.index', ['q' => "host = 'sign.example'\nAND country != 'US'", 'period' => '7d']));

    $page->set('source', 'TAG ip FOR 1 DAY')->assertDontSeeHtml('data-test="rule-view-in-activity"');
});
