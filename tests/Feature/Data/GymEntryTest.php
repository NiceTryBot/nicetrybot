<?php

use App\Actions\Gym\BacktestGymRoutine;
use App\Data\GymEntry;
use App\Data\GymVisitor;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\Team;
use App\Models\User;
use App\Models\Verdict;

const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

test('a condition admits the visitors it describes', function (array $condition, GymVisitor $visitor, bool $admitted) {
    expect(GymEntry::fromArray([$condition])->admits($visitor))->toBe($admitted);
})->with([
    'a named provider' => [['field' => 'network', 'operator' => 'in', 'values' => ['digitalocean']], new GymVisitor('203.0.113.7', asn: 14061), true],
    'another provider' => [['field' => 'network', 'operator' => 'in', 'values' => ['digitalocean']], new GymVisitor('203.0.113.7', asn: 24940), false],
    'any hosting network' => [['field' => 'network', 'operator' => 'in', 'values' => ['hosting']], new GymVisitor('203.0.113.7', asn: 24940), true],
    'a home network as hosting' => [['field' => 'network', 'operator' => 'in', 'values' => ['hosting']], new GymVisitor('203.0.113.7', asn: 7922), false],
    'a script as not a browser' => [['field' => 'browser', 'operator' => 'not_in', 'values' => ['chrome', 'firefox']], new GymVisitor('203.0.113.7', userAgent: 'curl/8.4'), true],
    'no user agent as not a browser' => [['field' => 'browser', 'operator' => 'not_in', 'values' => ['chrome', 'firefox']], new GymVisitor('203.0.113.7'), true],
    'Chrome as not a browser' => [['field' => 'browser', 'operator' => 'not_in', 'values' => ['chrome', 'firefox']], new GymVisitor('203.0.113.7', userAgent: CHROME_UA), false],
    'a user agent containing text' => [['field' => 'ua', 'operator' => 'contains', 'values' => ['python']], new GymVisitor('203.0.113.7', userAgent: 'Python-Requests/2.32'), true],
    'an IP inside a range' => [['field' => 'ip', 'operator' => 'in', 'values' => ['198.51.100.0/24']], new GymVisitor('198.51.100.7'), true],
    'an IP outside a range' => [['field' => 'ip', 'operator' => 'in', 'values' => ['198.51.100.0/24']], new GymVisitor('203.0.113.7'), false],
    'a probe path by family' => [['field' => 'category', 'operator' => 'in', 'values' => ['probe']], new GymVisitor('203.0.113.7', path: '/app/.env.backup'), true],
    'an ordinary page by family' => [['field' => 'category', 'operator' => 'in', 'values' => ['probe']], new GymVisitor('203.0.113.7', path: '/pricing'), false],
    'an unknown country as not one of' => [['field' => 'country', 'operator' => 'not_in', 'values' => ['US']], new GymVisitor('203.0.113.7'), true],
    'a verdict from the named rule' => [['field' => 'verdict', 'operator' => 'in', 'values' => ['7']], new GymVisitor('203.0.113.7', verdictRuleIds: [7]), true],
    'a verdict from another rule' => [['field' => 'verdict', 'operator' => 'in', 'values' => ['7']], new GymVisitor('203.0.113.7', verdictRuleIds: [8]), false],
]);

test('match all needs every condition while match any needs one', function () {
    $rows = [
        ['field' => 'network', 'operator' => 'in', 'values' => ['hetzner']],
        ['field' => 'country', 'operator' => 'in', 'values' => ['DE']],
    ];
    $visitor = new GymVisitor('203.0.113.7', countryCode: 'DE', asn: 14061);

    expect(GymEntry::fromArray($rows, matchAll: true)->admits($visitor))->toBeFalse()
        ->and(GymEntry::fromArray($rows, matchAll: false)->admits($visitor))->toBeTrue();
});

test('incomplete or unknown rows are dropped, so an empty entry admits everyone', function () {
    $entry = GymEntry::fromArray([
        ['field' => 'network', 'operator' => 'in', 'values' => []],
        ['field' => 'shoe-size', 'operator' => 'in', 'values' => ['11']],
        ['field' => 'ip', 'operator' => 'in', 'values' => ['not-an-ip']],
    ]);

    expect($entry->isEveryone())->toBeTrue()
        ->and($entry->admits(new GymVisitor('203.0.113.7')))->toBeTrue();
});

test('an entry reads as one plain sentence', function () {
    $entry = GymEntry::fromArray([
        ['field' => 'network', 'operator' => 'in', 'values' => ['digitalocean', 'hetzner']],
        ['field' => 'browser', 'operator' => 'not_in', 'values' => ['chrome', 'firefox']],
    ]);

    expect($entry->describe(Team::factory()->make()))
        ->toBe('Visitors whose network is DigitalOcean or Hetzner, and whose browser is not Chrome or Firefox.');
});

test('the backtest sends the same visitors to the gym as live matching does', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->count(2)->create(['client_ip' => '203.0.113.1', 'asn' => 14061, 'user_agent' => 'python-requests/2.32']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.2', 'asn' => 24940, 'user_agent' => null]);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.3', 'asn' => 14061, 'user_agent' => CHROME_UA]);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.4', 'asn' => 7922, 'user_agent' => 'curl/8.4']);
    $entry = GymEntry::fromArray([
        ['field' => 'network', 'operator' => 'in', 'values' => ['digitalocean', 'hetzner']],
        ['field' => 'browser', 'operator' => 'not_in', 'values' => ['chrome', 'firefox']],
    ]);

    $backtest = app(BacktestGymRoutine::class)->handle($user->currentTeam, $entry);

    expect(collect($backtest->subjects)->pluck('client_ip')->sort()->values()->all())->toBe(['203.0.113.1', '203.0.113.2'])
        ->and($backtest->requestCount)->toBe(3);
});

test('a verdict entry backtests the clients each kind of verdict covers', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $domain = Domain::factory()->for($team)->create();
    $rule = EdgeRule::factory()->for($team)->create();
    $otherRule = EdgeRule::factory()->for($team)->create();
    $flagged = 't13d1516h2_8daaf6152771_02713d6af862';

    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.1']);
    EdgeRequest::factory()->for($domain)->withFingerprint($flagged)->create(['client_ip' => '203.0.113.2']);
    EdgeRequest::factory()->for($domain)->withFingerprint($flagged)->create(['client_ip' => '203.0.113.3']);
    EdgeRequest::factory()->for($domain)->withFingerprint()->create(['client_ip' => '203.0.113.3']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.4']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.5']);
    $ja4Id = EdgeRequest::query()->where('client_ip', '203.0.113.2')->value('ja4_id');
    $onRule = ['team_id' => $team->id, 'edge_rule_id' => $rule->id];

    Verdict::factory()->create([...$onRule, 'client_ip' => '203.0.113.1']);
    Verdict::factory()->create([...$onRule, 'client_ip' => null, 'ja4_id' => $ja4Id]);
    Verdict::factory()->expired()->create([...$onRule, 'client_ip' => '203.0.113.4']);
    Verdict::factory()->create(['team_id' => $team->id, 'edge_rule_id' => $otherRule->id, 'client_ip' => '203.0.113.5']);
    Verdict::factory()->create(['edge_rule_id' => $rule->id, 'client_ip' => '203.0.113.5']);

    $backtest = fn () => app(BacktestGymRoutine::class)->handle($team, GymEntry::fromArray([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $rule->id]]]));

    expect(collect($backtest()->subjects)->pluck('client_ip')->sort()->values()->all())->toBe(['203.0.113.1', '203.0.113.2', '203.0.113.3'])
        ->and($backtest()->requestCount)->toBe(3);

    Verdict::factory()->create([...$onRule, 'client_ip' => '203.0.113.4', 'ja4_id' => null]);
    Verdict::query()->where('ja4_id', $ja4Id)->update(['client_ip' => '203.0.113.2']);

    expect(collect($backtest()->subjects)->pluck('client_ip')->sort()->values()->all())->toBe(['203.0.113.1', '203.0.113.2', '203.0.113.4']);

    Verdict::factory()->create([...$onRule, 'client_ip' => null, 'ja4_id' => null]);

    expect($backtest()->subjectCount)->toBe(5);
});

test('a rule joins an entry so its clients enter and everyone who entered before still does', function (array $rows, bool $matchAll, array $expectedRows, bool $expectedMatchAll) {
    $entry = GymEntry::fromArray($rows, $matchAll)->withVerdictsFrom(34);

    expect(array_map(fn ($condition) => $condition->toArray(), $entry->conditions))->toBe($expectedRows)
        ->and($entry->matchAll)->toBe($expectedMatchAll)
        ->and($entry->takesVerdictsFrom(34))->toBeTrue();
})->with([
    'into the verdict condition, keeping the others' => [
        [['field' => 'domain', 'operator' => 'in', 'values' => ['sign.example']], ['field' => 'verdict', 'operator' => 'in', 'values' => ['12']]], true,
        [['field' => 'domain', 'operator' => 'in', 'values' => ['sign.example']], ['field' => 'verdict', 'operator' => 'in', 'values' => ['12', '34']]], true,
    ],
    'as another way in beside one condition' => [
        [['field' => 'path', 'operator' => 'starts_with', 'values' => ['/gymbros']]], true,
        [['field' => 'path', 'operator' => 'starts_with', 'values' => ['/gymbros']], ['field' => 'verdict', 'operator' => 'in', 'values' => ['34']]], false,
    ],
    'as another way in when any condition is enough' => [
        [['field' => 'network', 'operator' => 'in', 'values' => ['hetzner']], ['field' => 'country', 'operator' => 'in', 'values' => ['DE']]], false,
        [['field' => 'network', 'operator' => 'in', 'values' => ['hetzner']], ['field' => 'country', 'operator' => 'in', 'values' => ['DE']], ['field' => 'verdict', 'operator' => 'in', 'values' => ['34']]], false,
    ],
    'not at all when any rule already enters' => [
        [['field' => 'verdict', 'operator' => 'in', 'values' => ['any']]], true,
        [['field' => 'verdict', 'operator' => 'in', 'values' => ['any']]], true,
    ],
]);

test('a rule can\'t join an entry without changing who else enters', function (array $rows) {
    expect(GymEntry::fromArray($rows, matchAll: true)->withVerdictsFrom(34))->toBeNull();
})->with([
    'every visitor enters already' => [[]],
    'several conditions must all hold' => [[['field' => 'network', 'operator' => 'in', 'values' => ['hetzner']], ['field' => 'country', 'operator' => 'in', 'values' => ['DE']]]],
]);
