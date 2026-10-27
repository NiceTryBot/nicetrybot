<?php

use App\Data\EdgeExpression;
use App\Data\LiveRequest;
use App\Data\RuleScript;
use App\Enums\PathCategory;
use App\Models\Domain;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\GymSession;
use App\Models\Team;
use App\Models\Verdict;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
});

const NON_US_RULE = "TAG ip, ja4 AS 'non-us' FOR 1 DAY\nWHERE host = 'sign.example' AND country != 'US' AND NOT path LIKE '/sign/%'";

/**
 * A team enforcing on sign.example with a rule, and a routine taking in whoever holds its verdict.
 *
 * @return array{0: EdgeRule, 1: GymRoutine}
 */
function ruleWithGym(string $source = NON_US_RULE): array
{
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'sign.example']);
    $rule = EdgeRule::factory()->for($team)->create(['source' => $source]);
    $routine = GymRoutine::factory()->for($team)
        ->entering([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]]])
        ->obstacles(['waiting-room' => ['seconds' => 10, 'intensity' => 'light', 'show_time' => true, 'headline' => 'Spot me, bro']])
        ->create();

    return [$rule, $routine];
}

/**
 * Ask the gym check about a request the way an edge that judges rules does: describing the client and
 * naming the rules it saw the request match.
 *
 * @param  list<int>  $rules
 * @param  array<string, string>  $client
 */
function judgedGymCheck(string $uri, array $rules, array $client = ['X-NiceTry-Country' => 'AL', 'X-NiceTry-ASN' => '42313', 'X-NiceTry-City' => 'Tiran%C3%AB'])
{
    return test()->withToken('edge-token')
        ->withHeaders([
            'X-NiceTry-Host' => 'sign.example',
            'X-NiceTry-Uri' => $uri,
            'X-NiceTry-Client-Ip' => '203.0.113.188',
            'X-NiceTry-JA4' => 't13d1516h2_8daaf6152771_02713d6af862',
            'User-Agent' => 'Mozilla/5.0 Chrome/141.0',
            'X-NiceTry-Features' => 'gym-rules',
            'X-NiceTry-Rules' => implode(',', $rules),
            ...$client,
        ])
        ->get(route('edge.gym.check'));
}

test('a single-request rule the edge flags reaches its verdict at once, and its gym takes the client in on that request', function () {
    [$rule] = ruleWithGym();

    judgedGymCheck('/phpmyadmin.php', [$rule->id])->assertOk()->assertSee('Spot me, bro');

    expect(Verdict::sole())
        ->edge_rule_id->toBe($rule->id)
        ->client_ip->toBe('203.0.113.188')
        ->tag->toBe('non-us')
        ->matched_requests->toBe(1);
    expect(GymSession::sole()->client_ip)->toBe('203.0.113.188');
});

test('the gym check judges a flagged rule again, and passes over what doesn\'t truly match', function (string $uri, string $source, array $client) {
    [$rule] = ruleWithGym($source);

    judgedGymCheck($uri, [$rule->id], $client)->assertNoContent();

    expect(Verdict::count())->toBe(0);
})->with([
    'a client in the US' => ['/phpmyadmin.php', NON_US_RULE, ['X-NiceTry-Country' => 'US']],
    'a path the rule leaves out' => ['/sign/abc', NON_US_RULE, ['X-NiceTry-Country' => 'AL']],
    'a rule that counts over a window' => ['/phpmyadmin.php', "TAG ip AS 'busy' FOR 1 DAY WHERE country != 'US' WITHIN 1 MINUTE HAVING COUNT(*) >= 1", ['X-NiceTry-Country' => 'AL']],
    'a rule that needs the response' => ['/phpmyadmin.php', "TAG ip AS 'missing' FOR 1 DAY WHERE status = 404", ['X-NiceTry-Country' => 'AL']],
]);

test('another team\'s rule, or one switched off, isn\'t reached from a flag', function () {
    [$rule] = ruleWithGym();
    $otherTeams = EdgeRule::factory()->create(['source' => NON_US_RULE]);
    $off = EdgeRule::factory()->for($rule->team)->create(['source' => NON_US_RULE, 'enabled' => false]);

    judgedGymCheck('/phpmyadmin.php', [$otherTeams->id, $off->id])->assertNoContent();

    expect(Verdict::count())->toBe(0);
});

test('an edge that judges rules hears them on the triggers of the routines their verdicts lead to, with what they need', function () {
    [$rule, $routine] = ruleWithGym();
    EdgeRule::factory()->for($rule->team)->create(['source' => "TAG ip AS 'missing' FOR 1 DAY WHERE status = 404"]);

    $feed = test()->withToken('edge-token')->withHeaders(['X-NiceTry-Features' => 'gym-rules'])->getJson(route('edge.gym.triggers'))->assertOk();

    expect($feed->json('triggers'))->toBe([[
        'id' => $routine->id,
        'hosts' => ['sign-example.preview.example', 'sign.example'],
        'method' => null,
        'path' => '*',
        'clients' => [],
        'limited' => true,
        'rules' => [['id' => $rule->id, 'expr' => EdgeExpression::forScript(RuleScript::parse(NON_US_RULE))]],
    ]])
        ->and($feed->json('categories.0'))->toHaveKeys(['id', 'patterns'])
        ->and($feed->json('networks.0.asns'))->toContain(14061)
        ->and($feed->json('domains'))->toBe([['hostname' => 'sign.example', 'aliases' => ['sign.example', 'sign-example.preview.example']]]);
});

test('a rule becomes the expression the edge judges, in the edge\'s own terms', function (string $where, ?array $expression) {
    expect(EdgeExpression::forScript(RuleScript::parse("TAG ip AS 'x' FOR 1 DAY".($where === '' ? '' : " WHERE {$where}"))))->toBe($expression);
})->with([
    'every request' => ['', ['k' => 'true']],
    'a country, as stored' => ["country != 'us'", ['k' => 'cmp', 'f' => 'country', 'op' => '!=', 'v' => 'US']],
    'a method, by its verb' => ["method IN ('post', 'GET')", ['k' => 'in', 'f' => 'method', 'vs' => ['POST', 'GET'], 'neg' => false]],
    'a category family, by number' => ["category = 'edge'", ['k' => 'in', 'f' => 'category', 'vs' => array_map(fn ($c) => $c->value, array_values(array_filter(PathCategory::cases(), fn ($c) => $c->family() === 'edge'))), 'neg' => false]],
    'a pattern and an IP range' => ["ua LIKE '%curl%' AND NOT ip = '66.249.64.0/19'", ['k' => 'and', 'l' => ['k' => 'like', 'f' => 'ua', 'p' => '%curl%', 'neg' => false], 'r' => ['k' => 'not', 'e' => ['k' => 'cidr', 'range' => '66.249.64.0/19']]]],
    'something only the response tells' => ['status = 404', null],
]);

test('a live request knows the client the edge describes, and only then', function () {
    $where = RuleScript::parse("TAG ip AS 'x' FOR 1 DAY WHERE country != 'US' AND network.kind = 'hosting' AND lang IS NULL")->where;

    expect((new LiveRequest('GET', 'sign.example', '/'))->evaluate($where))->toBeNull()
        ->and((new LiveRequest('GET', 'sign.example', '/', described: true, countryCode: 'de', asn: 24940))->evaluate($where))->toBeTrue()
        ->and((new LiveRequest('GET', 'sign.example', '/', described: true, countryCode: 'US', asn: 24940))->evaluate($where))->toBeFalse();
});

test('a category pattern matches the categories it names, live as in a search', function () {
    $where = RuleScript::parse("TAG ip AS 'x' FOR 1 DAY WHERE category LIKE 'probe/%'")->where;

    expect((new LiveRequest('GET', 'sign.example', '/.env'))->evaluate($where))->toBeTrue()
        ->and((new LiveRequest('GET', 'sign.example', '/pricing'))->evaluate($where))->toBeFalse();
});
