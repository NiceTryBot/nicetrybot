<?php

use App\Data\RuleScript;
use App\Data\RuleScriptSyntaxError;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\User;
use App\Models\Verdict;
use Livewire\Livewire;

test('a field no installed add-on brings is unknown, and a typo of one that\'s here is suggested', function (string $where, string $message) {
    expect(fn () => RuleScript::parse("TAG ip AS 'x' FOR 1 DAY WHERE {$where}"))->toThrow(RuleScriptSyntaxError::class, $message);
})->with([
    'missing add-on' => ['bookmark IS NOT NULL', 'Unknown field “bookmark”. If an add-on brings it, that add-on isn’t installed.'],
    'typo' => ['stauts = 404', 'Unknown field “stauts”. Did you mean status?'],
]);

test('rules:evaluate skips a saved rule that no longer parses, and runs the rest', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $since = (int) EdgeRequest::max('id');
    EdgeRule::factory()->for($user->currentTeam)->create(['name' => 'Saved pages', 'source' => "TAG ip AS 'saved-page' FOR 1 DAY WHERE bookmark IS NOT NULL", 'last_request_id' => $since]);
    EdgeRule::factory()->for($user->currentTeam)->script("BAN ip FOR 1 DAY WHERE category = 'probe'")->create(['last_request_id' => $since]);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.7', 'path' => '/.env']);

    $this->artisan('rules:evaluate')
        ->expectsOutputToContain('(Saved pages) skipped, its script doesn\'t parse: Unknown field “bookmark”.')
        ->expectsOutputToContain('Reached 1 new verdict(s). 1 rule(s) skipped.')
        ->assertSuccessful();

    expect(Verdict::sole()->client_ip)->toBe('203.0.113.7');
});

test('a saved rule that no longer parses is flagged in the list, and says why when opened', function () {
    $user = User::factory()->create();
    $rule = EdgeRule::factory()->for($user->currentTeam)->create(['source' => "TAG ip AS 'saved-page' FOR 1 DAY WHERE bookmark IS NOT NULL"]);
    $this->actingAs($user);

    Livewire::test('pages::rules.index')
        ->assertSeeHtml('data-test="rule-card-unparsed"')
        ->call('edit', $rule->id)
        ->assertSee('If an add-on brings it, that add-on isn’t installed.');
});
