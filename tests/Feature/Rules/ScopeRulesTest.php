<?php

use App\Actions\Rules\BacktestRule;
use App\Actions\Rules\EvaluateRule;
use App\Data\RequestQuery;
use App\Data\RuleScript;
use App\Data\RuleScriptSyntaxError;
use App\Enums\RequestAction;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\ScopeVerdict;
use App\Models\Team;
use App\Models\Verdict;
use Illuminate\Support\Facades\DB;

/**
 * A rule over a scope: 3 or more IPs getting a 403 from the origin on the same URL within a minute tags
 * that URL (the edge's own 403s, the Bouncer's say, don't count).
 */
const URL_SWARM = "TAG host, path AS 'swarmed' FOR 5 MINUTES WHERE status = 403 AND action = 'passed' WITHIN 1 MINUTE HAVING COUNT(DISTINCT ip) >= 3";

/**
 * Three IPs (or as many as given) each getting a 403 on one path, a few seconds apart.
 */
function forbiddenFrom(Domain $domain, string $path, int $ips = 3, RequestAction $action = RequestAction::Passed): void
{
    foreach (range(1, $ips) as $i) {
        EdgeRequest::factory()->for($domain)->create(['client_ip' => "203.0.113.{$i}", 'path' => $path, 'status_code' => 403, 'action' => $action, 'occurred_at' => now()->subSeconds(10 + $i)]);
    }
}

test('a rule over host and path is a scope rule, and keeps clients and scopes apart', function () {
    $script = RuleScript::parse(URL_SWARM);

    expect($script->isScoped())->toBeTrue()
        ->and($script->isLive())->toBeFalse()
        ->and($script->describe())->toContain('site and path')
        ->and(RuleScript::parse("TAG ip AS 'x' FOR 1 HOUR WHERE status = 403")->isScoped())->toBeFalse();

    foreach ([
        "TAG ip, host AS 'x' FOR 5 MINUTES WHERE status = 403" => 'not both',
        'BLOCK host FOR 5 MINUTES WHERE status = 403' => 'TAG host',
        "TAG host AS 'x' FOR 5 MINUTES WHERE status = 403 UNTIL status = 200" => 'just runs out',
    ] as $source => $message) {
        expect(fn () => RuleScript::parse($source))->toThrow(RuleScriptSyntaxError::class, $message);
    }

    expect(fn () => (new RequestQuery('status = 403 GROUP BY host'))->subjects())->toThrow(RuleScriptSyntaxError::class);
});

test('it reaches a verdict on the URL, not its clients, and only once enough IPs swarm it', function () {
    $team = Team::factory()->create();
    $domain = Domain::factory()->for($team)->create(['hostname' => 'shop.example']);
    $rule = EdgeRule::factory()->for($team)->script(URL_SWARM)->create(['last_request_id' => 0]);

    forbiddenFrom($domain, '/login');
    forbiddenFrom($domain, '/account', ips: 2);
    forbiddenFrom($domain, '/checkout', action: RequestAction::Bouncer);

    expect(app(EvaluateRule::class)->handle($rule))->toBe(1);

    $verdict = ScopeVerdict::sole();

    expect(Verdict::count())->toBe(0)
        ->and($verdict->domain_id)->toBe($domain->id)
        ->and(DB::table('request_paths')->where('id', $verdict->path_id)->value('path'))->toBe('/login')
        ->and($verdict->tag)->toBe('swarmed')
        ->and(ScopeVerdict::query()->active()->covering($domain->id, $verdict->path_id)->exists())->toBeTrue()
        ->and(ScopeVerdict::query()->active()->covering($domain->id, null)->exists())->toBeFalse();
});

test('a swarm that keeps going keeps its scope verdict going', function () {
    $team = Team::factory()->create();
    $domain = Domain::factory()->for($team)->create();
    $rule = EdgeRule::factory()->for($team)->script("TAG host AS 'swarmed' FOR 5 MINUTES WHERE status = 403 WITHIN 1 MINUTE HAVING COUNT(DISTINCT ip) >= 3")->create(['last_request_id' => 0]);

    forbiddenFrom($domain, '/login');
    app(EvaluateRule::class)->handle($rule, now()->toImmutable());
    $first = ScopeVerdict::sole()->expires_at;

    $this->travel(2)->minutes();
    forbiddenFrom($domain, '/api/x');
    app(EvaluateRule::class)->handle($rule->fresh(), now()->toImmutable());

    expect(ScopeVerdict::count())->toBe(1)
        ->and(ScopeVerdict::sole()->path_id)->toBeNull()
        ->and(ScopeVerdict::sole()->expires_at->greaterThan($first))->toBeTrue();
});

test('the backtest of a scope rule lists the URLs it would have covered', function () {
    $team = Team::factory()->create();
    $domain = Domain::factory()->for($team)->create(['hostname' => 'shop.example']);
    forbiddenFrom($domain, '/login');

    $backtest = app(BacktestRule::class)->handle($team, RuleScript::parse(URL_SWARM));

    expect($backtest->subjectCount)->toBe(1)
        ->and($backtest->subjects[0]['scope'])->toBe('shop.example/login');
});
