<?php

use App\Actions\Gym\SendRuleToRoutine;
use App\Actions\Rules\BackfillRuleDestinations;
use App\Actions\Rules\SaveEdgeRule;
use App\Addons\Addons;
use App\Data\RuleScript;
use App\Data\RuleScriptSyntaxError;
use App\Enums\TeamRole;
use App\Models\Administrator;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\User;
use App\Support\RuleDestinationError;
use NiceTry\Blackhole\Models\BlackholeFeeder;
use NiceTry\Tarpit\Models\Tarpit;

/**
 * Save a rule from its script, as the editor does.
 */
function saveRuleScript(User $user, string $source, ?EdgeRule $rule = null): EdgeRule
{
    return app(SaveEdgeRule::class)->handle(
        $user->currentTeam,
        $rule,
        ['name' => 'Test rule', 'source' => $source, 'enabled' => true],
        RuleScript::parse($source),
    );
}

test('a GYM clause sends the rule\'s clients through a routine, made by name', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $rule = saveRuleScript($user, "TAG ip AS 'probe' FOR 1 DAY\nGYM 'Curl probes'\nWHERE category = 'probe'");

    $routine = $user->currentTeam->gymRoutines()->where('name', 'Curl probes')->sole();
    expect($routine->entry()->takesVerdictsFrom($rule->id))->toBeTrue();
});

test('a GYM clause joins an existing routine, matched by name', function () {
    $user = User::factory()->create();
    $routine = GymRoutine::factory()->for($user->currentTeam)->entering([['field' => 'path', 'operator' => 'starts_with', 'values' => ['/login']]])->create(['name' => 'Login wall']);
    $this->actingAs($user);

    $rule = saveRuleScript($user, "TAG ip AS 'probe' FOR 1 DAY\nGYM 'login wall'\nWHERE category = 'probe'");

    expect($routine->fresh()->entry()->takesVerdictsFrom($rule->id))->toBeTrue()
        ->and($user->currentTeam->gymRoutines()->count())->toBe(1);
});

test('dropping the GYM clause takes the rule back out of the routine', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $rule = saveRuleScript($user, "TAG ip AS 'probe' FOR 1 DAY\nGYM 'Curl probes'\nWHERE category = 'probe'");
    $routine = $user->currentTeam->gymRoutines()->where('name', 'Curl probes')->sole();
    expect($routine->entry()->takesVerdictsFrom($rule->id))->toBeTrue();

    saveRuleScript($user, "TAG ip AS 'probe' FOR 1 DAY\nWHERE category = 'probe'", $rule);

    expect($routine->fresh()->entry()->takesVerdictsFrom($rule->id))->toBeFalse();
});

test('a TARPIT clause makes the tarpit by name and takes the rule', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $rule = saveRuleScript($user, "TAG ip AS 'curl' FOR 1 DAY\nTARPIT 'curl-probes'\nWHERE ua LIKE 'curl/%'");

    $tarpit = $user->currentTeam->tarpits()->where('name', 'curl-probes')->sole();
    expect($tarpit->rules()->whereKey($rule->id)->exists())->toBeTrue();

    saveRuleScript($user, "TAG ip AS 'curl' FOR 1 DAY\nWHERE ua LIKE 'curl/%'", $rule);
    expect($tarpit->fresh()->rules()->whereKey($rule->id)->exists())->toBeFalse();
});

test('a BLACKHOLE clause from an administrator sets up a feeder', function () {
    $user = Administrator::factory()->create()->user;
    $this->actingAs($user);

    $rule = saveRuleScript($user, "TAG ip AS 'sweep' FOR 30 DAYS\nBLACKHOLE\nWHERE category = 'probe' WITHIN 1 DAY HAVING COUNT(DISTINCT host) >= 2");

    expect(BlackholeFeeder::query()->where('edge_rule_id', $rule->id)->exists())->toBeTrue();

    saveRuleScript($user, "TAG ip AS 'sweep' FOR 30 DAYS\nWHERE category = 'probe' WITHIN 1 DAY HAVING COUNT(DISTINCT host) >= 2", $rule);
    expect(BlackholeFeeder::query()->where('edge_rule_id', $rule->id)->exists())->toBeFalse();
})->skip(fn () => ! app(Addons::class)->has('blackhole'), 'Needs the paid Blackhole add-on (nicetry/blackhole).');

test('a non-administrator naming BLACKHOLE is refused, and the rule is not saved', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(fn () => saveRuleScript($user, "TAG ip AS 'sweep' FOR 1 DAY\nBLACKHOLE\nWHERE category = 'probe'"))
        ->toThrow(RuleDestinationError::class);

    expect(EdgeRule::count())->toBe(0);
})->skip(fn () => ! app(Addons::class)->has('blackhole'), 'Needs the paid Blackhole add-on (nicetry/blackhole).');

test('a non-administrator saving a rule leaves an administrator\'s blackhole feeder alone', function () {
    $admin = Administrator::factory()->create()->user;
    $team = $admin->currentTeam;
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Admin->value]);
    $member->switchTeam($team);

    $this->actingAs($admin);
    $rule = saveRuleScript($admin, "TAG ip AS 'sweep' FOR 1 DAY\nBLACKHOLE\nWHERE category = 'probe'");
    expect(BlackholeFeeder::query()->where('edge_rule_id', $rule->id)->exists())->toBeTrue();

    // The member can write rules but can't manage the blackhole, so their save must not remove the feeder.
    $this->actingAs($member);
    saveRuleScript($member, "TAG ip AS 'sweep' FOR 1 DAY\nWHERE category = 'probe'", $rule);

    expect(BlackholeFeeder::query()->where('edge_rule_id', $rule->id)->exists())->toBeTrue();
})->skip(fn () => ! app(Addons::class)->has('blackhole'), 'Needs the paid Blackhole add-on (nicetry/blackhole).');

test('the script names its destinations in the parse and the description', function () {
    $script = RuleScript::parse("TAG ip AS 'curl' FOR 1 DAY\nGYM 'Curl probes'\nTARPIT 'curl-probes'\nWHERE ua LIKE 'curl/%'");

    expect($script->destinations)->toBe(['GYM' => 'Curl probes', 'TARPIT' => 'curl-probes'])
        ->and($script->describe())->toContain('sends its clients through the gym routine “Curl probes”')
        ->and($script->describe())->toContain('holds its clients in the tarpit “curl-probes”');
});

test('a destination keyword can appear at most once', function () {
    expect(fn () => RuleScript::parse("TAG ip AS 'x' FOR 1 DAY GYM 'a' GYM 'b' WHERE path = '/x'"))
        ->toThrow(RuleScriptSyntaxError::class, 'GYM is already set.');
});

test('the backfill writes a rule\'s existing links into its script, once', function () {
    $admin = Administrator::factory()->create()->user;
    $team = $admin->currentTeam;
    $this->actingAs($admin);

    // A rule linked the old way: no destination clauses in its source.
    $rule = EdgeRule::factory()->for($team)->create(['source' => "TAG ip AS 'curl' FOR 1 DAY\nWHERE ua LIKE 'curl/%'"]);
    app(SendRuleToRoutine::class)->handle($rule, 'Curl gym');
    Tarpit::factory()->for($team)->create(['name' => 'curl-probes'])->rules()->attach($rule->id);
    BlackholeFeeder::query()->create(['edge_rule_id' => $rule->id, 'min_requests' => 1, 'duration_seconds' => null, 'created_by_id' => $admin->id]);

    app(BackfillRuleDestinations::class)->handle();

    $source = $rule->fresh()->source;
    expect($source)->toContain("GYM 'Curl gym'")
        ->toContain("TARPIT 'curl-probes'")
        ->toContain('BLACKHOLE');

    // The rewritten script still parses and names exactly those destinations.
    expect(RuleScript::parse($source)->destinations)->toBe(['GYM' => 'Curl gym', 'TARPIT' => 'curl-probes', 'BLACKHOLE' => null]);

    // Running it again changes nothing (no duplicate clauses).
    app(BackfillRuleDestinations::class)->handle();
    expect($rule->fresh()->source)->toBe($source);
})->skip(fn () => ! app(Addons::class)->has('blackhole'), 'Needs the paid Blackhole add-on (nicetry/blackhole).');
