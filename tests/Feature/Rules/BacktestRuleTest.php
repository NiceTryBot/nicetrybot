<?php

use App\Actions\Rules\BacktestRule;
use App\Data\RuleScript;
use App\Enums\NetworkKind;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;
use App\Models\Verdict;
use Livewire\Livewire;

test('a backtest counts who a rule would have matched, flags subjects off hosting networks, and changes nothing', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->fromNetwork()->count(3)->create(['client_ip' => '203.0.113.7', 'path' => '/.env', 'occurred_at' => now()->subDays(2)]);
    EdgeRequest::factory()->for($domain)->fromNetwork(7922, 'Comcast', NetworkKind::Unknown)->create(['client_ip' => '192.0.2.9', 'path' => '/wp-login.php']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.4', 'path' => '/contact']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.5', 'path' => '/.env', 'occurred_at' => now()->subDays(9)]);

    $backtest = app(BacktestRule::class)->handle($user->currentTeam, RuleScript::parse("BAN ip FOR 1 DAY WHERE category = 'probe'"));

    expect($backtest->subjectCount)->toBe(2)
        ->and($backtest->requestCount)->toBe(4)
        ->and($backtest->outsideHostingCount)->toBe(1)
        ->and($backtest->subjects[0]['client_ip'])->toBe('203.0.113.7')
        ->and($backtest->subjects[0]['is_hosting'])->toBeTrue()
        ->and(Verdict::count())->toBe(0);
});

test('a windowed backtest finds a burst anywhere in the period but not spread-out requests', function () {
    $user = User::factory()->create();
    [$first, $second] = Domain::factory()->for($user->currentTeam)->count(2)->create();
    $burstAt = now()->subDays(3);
    EdgeRequest::factory()->for($first)->notFound()->create(['client_ip' => '203.0.113.7', 'occurred_at' => $burstAt]);
    EdgeRequest::factory()->for($second)->notFound()->create(['client_ip' => '203.0.113.7', 'occurred_at' => $burstAt->addMinutes(4)]);
    EdgeRequest::factory()->for($first)->notFound()->create(['client_ip' => '198.51.100.4', 'occurred_at' => now()->subHours(5)]);
    EdgeRequest::factory()->for($second)->notFound()->create(['client_ip' => '198.51.100.4', 'occurred_at' => now()->subHours(2)]);

    $backtest = app(BacktestRule::class)->handle($user->currentTeam, RuleScript::parse(
        'BAN ip FOR 1 HOUR WHERE status = 404 WITHIN 10 MINUTES HAVING COUNT(*) >= 2 AND COUNT(DISTINCT host) >= 2'
    ));

    expect($backtest->subjectCount)->toBe(1)
        ->and($backtest->subjects[0]['client_ip'])->toBe('203.0.113.7');
});

test('a backtest counts only the requests a FILTER keeps', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'settlement.example']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.7', 'path' => '/claim/thank-you', 'occurred_at' => now()->subDays(2)]);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.4', 'path' => '/', 'occurred_at' => now()->subDays(2)]);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.4', 'path' => '/claim/thank-you', 'occurred_at' => now()->subDays(2)->addMinute()]);

    $backtest = app(BacktestRule::class)->handle($user->currentTeam, RuleScript::parse("TAG ip AS 'skipped-home' FOR 1 DAY WHERE host = 'settlement.example' WITHIN 1 HOUR HAVING COUNT(*) FILTER (WHERE path LIKE '%/thank-you') >= 1 AND COUNT(*) FILTER (WHERE path = '/') = 0"));

    expect($backtest->subjectCount)->toBe(1)
        ->and($backtest->subjects[0]['client_ip'])->toBe('203.0.113.7');
});

test('the rule editor opens before its backtest, which loads after and is kept for the next look', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->count(2)->create(['client_ip' => '203.0.113.7', 'path' => '/.env']);

    $this->actingAs($user);

    Livewire::test('pages::rules.index')
        ->call('newRule')
        ->set('source', "BAN ip FOR 1 DAY WHERE category = 'probe'")
        ->assertSee('Replaying the last 7 days')
        ->assertDontSee('Subjects matched')
        ->call('loadBacktest')
        ->assertSee('Subjects matched')
        ->assertSee('203.0.113.7');

    Livewire::test('pages::rules.index')
        ->call('newRule')
        ->set('source', "BAN ip FOR 1 DAY WHERE category = 'probe'")
        ->assertSee('Subjects matched')
        ->assertDontSee('Replaying the last 7 days');
});

test('a plain rule\'s backtest counts each subject\'s requests and sites across the whole period', function () {
    $user = User::factory()->create();
    $claims = Domain::factory()->for($user->currentTeam)->create();
    $shop = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($claims)->count(2)->create(['client_ip' => '203.0.113.7', 'path' => '/.env', 'occurred_at' => now()->subDays(5)]);
    EdgeRequest::factory()->for($shop)->fromNetwork(7922, 'Comcast', NetworkKind::Unknown)->create(['client_ip' => '203.0.113.7', 'path' => '/.git/config', 'occurred_at' => now()->subHour()]);
    EdgeRequest::factory()->for($shop)->create(['client_ip' => '192.0.2.9', 'path' => '/.env']);

    $backtest = app(BacktestRule::class)->handle($user->currentTeam, RuleScript::parse("BAN ip FOR 1 DAY WHERE category = 'probe'"));

    expect($backtest->subjectCount)->toBe(2)
        ->and($backtest->requestCount)->toBe(4)
        ->and($backtest->subjects[0])->toMatchArray(['client_ip' => '203.0.113.7', 'requests' => 3, 'hosts' => 2, 'network' => 'Comcast', 'is_hosting' => false])
        ->and($backtest->subjects[0]['first_seen'])->toStartWith(now()->subDays(5)->toDateString())
        ->and($backtest->subjects[1]['client_ip'])->toBe('192.0.2.9');
});
