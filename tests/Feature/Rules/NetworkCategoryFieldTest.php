<?php

use App\Actions\Analytics\CountTrafficSearch;
use App\Actions\Rules\BacktestRule;
use App\Actions\Rules\BuildRuleQuery;
use App\Actions\Rules\EvaluateRule;
use App\Actions\Rules\ReachLiveVerdicts;
use App\Data\EdgeExpression;
use App\Data\LiveRequest;
use App\Data\RequestQuery;
use App\Data\RuleScript;
use App\Data\RuleScriptSyntaxError;
use App\Enums\NetworkCategory as Category;
use App\Enums\NetworkKind;
use App\Enums\RuleField;
use App\Enums\TrafficPeriod;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\NetworkCategory;
use App\Models\Team;
use App\Models\User;
use App\Models\Verdict;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * A team's traffic: one client on a hosting network, one on an education network, one on a network with no
 * category, and one a correction has left with none.
 */
function trafficOfEachCategory(Team $team): void
{
    $domain = Domain::factory()->for($team)->create();

    NetworkCategory::factory()->create(['asn' => 64496, 'category' => Category::Hosting]);
    NetworkCategory::factory()->create(['asn' => 64497, 'category' => Category::Education]);
    NetworkCategory::factory()->override()->create(['asn' => 64499, 'category' => null]);

    EdgeRequest::factory()->for($domain)->fromNetwork(64496, 'Example Hosting', NetworkKind::Unknown)->create(['client_ip' => '203.0.113.1']);
    EdgeRequest::factory()->for($domain)->fromNetwork(64497, 'Example University', NetworkKind::Unknown)->create(['client_ip' => '203.0.113.2']);
    EdgeRequest::factory()->for($domain)->fromNetwork(64498, 'Example Unlisted', NetworkKind::Hosting)->create(['client_ip' => '203.0.113.3']);
    EdgeRequest::factory()->for($domain)->fromNetwork(64499, 'Example Corrected', NetworkKind::Unknown)->create(['client_ip' => '203.0.113.4']);
}

function clientsMatching(Team $team, string $where): array
{
    return app(BuildRuleQuery::class)
        ->handle($team, RuleScript::parse("TAG ip AS 'x' FOR 1 DAY WHERE {$where}"))
        ->orderBy('edge_requests.client_ip')
        ->pluck('edge_requests.client_ip')
        ->all();
}

test('network.category matches rows by what it says', function (string $where, array $clients) {
    $team = Team::factory()->create();
    trafficOfEachCategory($team);

    expect(clientsMatching($team, $where))->toBe($clients);
})->with([
    'equal' => ["network.category = 'hosting'", ['203.0.113.1']],
    'any case' => ["network.category = 'EDUCATION'", ['203.0.113.2']],
    'not equal leaves out what has none' => ["network.category != 'hosting'", ['203.0.113.2']],
    'in' => ["network.category IN ('hosting', 'education')", ['203.0.113.1', '203.0.113.2']],
    'not in' => ["network.category NOT IN ('hosting')", ['203.0.113.2']],
    'not' => ["NOT network.category = 'hosting'", ['203.0.113.2', '203.0.113.3', '203.0.113.4']],
    'none, whether absent or corrected away' => ['network.category IS NULL', ['203.0.113.3', '203.0.113.4']],
    'some' => ['network.category IS NOT NULL', ['203.0.113.1', '203.0.113.2']],
    'beside network.kind' => ["network.kind = 'hosting' OR network.category = 'education'", ['203.0.113.2', '203.0.113.3']],
]);

test('network.kind goes on working from the curated list', function () {
    $team = Team::factory()->create();
    trafficOfEachCategory($team);

    expect(clientsMatching($team, "network.kind = 'hosting'"))->toBe(['203.0.113.3']);
});

test('an unknown network.category value is refused, naming the values', function () {
    RuleScript::parse("TAG ip AS 'x' FOR 1 DAY WHERE network.category = 'cloud'");
})->throws(RuleScriptSyntaxError::class, "network.category is 'hosting', 'isp', 'business', 'education' or 'government'.");

test('network categories are only joined for what reads them', function () {
    $team = Team::factory()->create();
    $build = app(BuildRuleQuery::class);

    expect($build->matching($team, null)->toSql())->not->toContain('network_categories')
        ->and($build->matching($team, RuleScript::parse("TAG ip AS 'x' FOR 1 DAY WHERE network.kind = 'hosting'")->where)->toSql())->not->toContain('network_categories')
        ->and($build->matching($team, RuleScript::parse("TAG ip AS 'x' FOR 1 DAY WHERE network.category = 'hosting'")->where)->toSql())->toContain('network_categories');
});

test('a HAVING\'s FILTER can read network.category', function () {
    $team = Team::factory()->create();
    trafficOfEachCategory($team);

    $search = new RequestQuery("HAVING COUNT(*) FILTER (WHERE network.category = 'education') >= 1");

    expect(app(CountTrafficSearch::class)->totals($team, $search, TrafficPeriod::Day))->toBe(['requests' => 1, 'unique_ips' => 1]);
});

test('the traffic count groups by network.category, whether or not the search reads it', function (string $search, array $values) {
    $team = Team::factory()->create();
    trafficOfEachCategory($team);

    $groups = app(CountTrafficSearch::class)->groups($team, new RequestQuery($search), TrafficPeriod::Day, RuleField::NetworkCategory, 10);

    expect(collect($groups)->pluck('value')->sort()->values()->all())->toBe($values)
        ->and(collect($groups)->firstWhere('value', 'hosting')['search'] ?? "network.category = 'hosting'")->toContain("network.category = 'hosting'");
})->with([
    'not read' => ['', [null, 'education', 'hosting']],
    'read' => ["network.category IN ('hosting', 'education')", ['education', 'hosting']],
]);

test('Activity\'s search finds requests by network.category, and says what it doesn\'t know', function () {
    $user = User::factory()->create();
    trafficOfEachCategory($user->currentTeam);
    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', "network.category = 'education'")
        ->assertSeeHtml('data-client-value="203.0.113.2"')
        ->assertDontSeeHtml('data-client-value="203.0.113.1"');

    Livewire::test('pages::activity.index')
        ->set('search', "network.category = 'cloud'")
        ->assertSeeText('network.category is');
});

test('a rule reading network.category is judged from the logs, and backtests', function () {
    $user = User::factory()->create();
    trafficOfEachCategory($user->currentTeam);
    $rule = EdgeRule::factory()->for($user->currentTeam)->script("TAG ip AS 'campus' FOR 1 DAY WHERE network.category = 'education'")->create();

    $backtest = app(BacktestRule::class)->handle($user->currentTeam, $rule->script());

    app(EvaluateRule::class)->handle($rule);

    expect($backtest->subjectCount)->toBe(1)
        ->and(Verdict::query()->sole()->client_ip)->toBe('203.0.113.2');
});

test('a rule reading network.category never reaches an edge, and nothing can judge it live', function () {
    config(['services.nicetry_edge.token' => 'edge-token']);

    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'shop.example']);
    $categorised = EdgeRule::factory()->for($team)->create(['source' => "TAG ip AS 'a' FOR 1 DAY WHERE network.category = 'hosting' OR path = '/login'"]);
    $kind = EdgeRule::factory()->for($team)->create(['source' => "TAG ip AS 'b' FOR 1 DAY WHERE network.kind = 'hosting'"]);
    GymRoutine::factory()->for($team)
        ->entering([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $categorised->id, (string) $kind->id]]])
        ->obstacles(['waiting-room' => ['seconds' => 10, 'intensity' => 'light', 'show_time' => true, 'headline' => 'Hold on']])
        ->create();

    $feed = $this->withToken('edge-token')->withHeaders(['X-NiceTry-Features' => 'gym-rules'])->getJson(route('edge.gym.triggers'))->assertOk();

    $live = new LiveRequest('GET', 'shop.example', '/login', ip: '203.0.113.1', described: true, asn: 64496);
    $script = RuleScript::parse($categorised->source);

    expect($script->isLive())->toBeFalse()
        ->and(EdgeExpression::forScript($script))->toBeNull()
        ->and($feed->getContent())->not->toContain('network.category')
        ->and($feed->getContent())->toContain('network.kind')
        ->and($live->evaluate(RuleScript::parse("TAG ip AS 'x' FOR 1 DAY WHERE network.category = 'hosting'")->where))->toBeNull()
        ->and(app(ReachLiveVerdicts::class)->handle($team, $live, [$categorised->id]))->toBe([])
        ->and(Verdict::query()->count())->toBe(0);
});

test('the network categories\' join doesn\'t touch a query that doesn\'t need it', function () {
    $user = User::factory()->create();
    trafficOfEachCategory($user->currentTeam);
    $this->actingAs($user);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    Livewire::test('pages::activity.index')->set('search', 'status = 200');

    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'join "network_categories"')))->toBeEmpty();
});
