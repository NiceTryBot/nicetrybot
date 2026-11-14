<?php

use App\Actions\Rules\EvaluateRule;
use App\Data\LiveRequest;
use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\RequestPath;
use App\Models\ScopeVerdict;
use App\Models\Team;
use App\Models\User;
use App\Models\Verdict;
use Livewire\Livewire;
use NiceTry\SwarmMode\Models\ManualSwarm;
use NiceTry\SwarmMode\Models\SwarmRule;
use NiceTry\SwarmMode\Rules\SwarmDoorField;

/**
 * One of a team's swarm rules, with a URL it has put into Swarm Mode until a few minutes from now.
 *
 * @return array{0: EdgeRule, 1: ScopeVerdict}
 */
function tripped(Domain $domain, string $path = '/login', int $minutesLeft = 4): array
{
    $rule = EdgeRule::factory()->for($domain->team)->script("TAG host, path AS 'swarm-page' FOR 5 MINUTES WHERE status = 403")->create(['name' => 'Swarm on a page']);
    SwarmRule::create(['edge_rule_id' => $rule->id]);

    $verdict = ScopeVerdict::create([
        'team_id' => $domain->team_id,
        'edge_rule_id' => $rule->id,
        'tag' => 'swarm-page',
        'domain_id' => $domain->id,
        'path_id' => RequestPath::idFor($path),
        'matched_requests' => 7,
        'first_matched_at' => now()->subMinute(),
        'last_matched_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes($minutesLeft),
    ]);

    return [$rule, $verdict];
}

test('the page shows what a rule put into Swarm Mode and how long it has left, and it can be extended or ended', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'shop.example']);
    [, $verdict] = tripped($domain);
    $this->actingAs($user);

    $this->get(route('swarm-mode.index'))->assertOk()->assertSee(['shop.example/login', 'Swarm on a page', '7 errors in its busiest minute']);

    $page = Livewire::test('swarm-mode::index')->assertSeeHtml('data-test="swarm-left"');

    $page->call('extend', 'rule-'.$verdict->id);
    expect($verdict->fresh()->expires_at->diffInMinutes(now(), absolute: true))->toBeGreaterThan(8.9);

    $page->call('end', 'rule-'.$verdict->id)
        ->assertSee('Nothing is in Swarm Mode.')
        ->assertSee('shop.example/login'); // now under the last 24 hours

    expect($verdict->fresh()->lifted_at)->not->toBeNull()
        ->and(app(SwarmDoorField::class)->value($domain, new LiveRequest('GET', 'shop.example', '/login')))->toBeNull();
});

test('Swarm Mode turned on by hand covers its site or URL until it ends or is ended', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'shop.example']);
    $this->actingAs($user);
    $door = fn (string $path) => app(SwarmDoorField::class)->value($domain, new LiveRequest('GET', 'shop.example', $path));

    Livewire::test('swarm-mode::index')
        ->set('domain', 'elsewhere.example')
        ->call('turnOn')
        ->assertHasErrors('domain')
        ->set('domain', 'shop.example')
        ->set('path', 'login')
        ->call('turnOn')
        ->assertHasErrors('path')
        ->set('path', '/login')
        ->set('minutes', 30)
        ->call('turnOn')
        ->assertHasNoErrors()
        ->assertSee(['shop.example/login', 'Turned on by '.$user->name]);

    $swarm = ManualSwarm::sole();

    expect($swarm->ends_at->diffInMinutes(now(), absolute: true))->toBeGreaterThan(29.9)
        ->and($door('/login'))->toBe('swarm-manual')
        ->and($door('/about'))->toBeNull();

    Livewire::test('swarm-mode::index')->call('end', 'manual-'.$swarm->id);

    expect($door('/login'))->toBeNull();

    // The whole site, this time.
    Livewire::test('swarm-mode::index')->set('domain', 'shop.example')->set('minutes', 5)->call('turnOn');
    expect($door('/about'))->toBe('swarm-manual');
});

test('a member can see Swarm Mode but not turn it on or off, and another team\'s is never touched', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);
    [, $verdict] = tripped(Domain::factory()->for($team)->create(['hostname' => 'shop.example']));
    [, $theirs] = tripped(Domain::factory()->create(['hostname' => 'theirs.example']));
    $this->actingAs($member);

    Livewire::test('swarm-mode::index')
        ->assertSee('shop.example/login')
        ->assertDontSee('theirs.example')
        ->assertDontSeeHtml('data-test="swarm-turn-on"')
        ->call('end', 'rule-'.$verdict->id)
        ->assertForbidden();

    $owner = User::factory()->create();
    $this->actingAs($owner);
    Livewire::test('swarm-mode::index')->call('end', 'rule-'.$theirs->id);

    expect($verdict->fresh()->lifted_at)->toBeNull()
        ->and($theirs->fresh()->lifted_at)->toBeNull();
});

test('rules reading swarm from the logs see Swarm Mode turned on by hand too', function () {
    $team = Team::factory()->create();
    $domain = Domain::factory()->for($team)->create();
    ManualSwarm::create(['team_id' => $team->id, 'domain_id' => $domain->id, 'path_id' => null, 'started_at' => now()->subMinutes(2), 'ends_at' => now()->addMinutes(3)]);
    $during = EdgeRule::factory()->for($team)->script("TAG ip AS 'during' FOR 1 HOUR WHERE swarm = 'swarm-manual'")->create(['last_request_id' => 0]);

    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.1', 'occurred_at' => now()->subMinute()]);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.2', 'occurred_at' => now()->subMinutes(10)]);

    app(EvaluateRule::class)->handle($during);

    expect(Verdict::query()->where('edge_rule_id', $during->id)->pluck('client_ip')->all())->toBe(['203.0.113.1']);
});

test('while a site or URL is in Swarm Mode the edge asks about every request there, and stops once it ends', function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
    $team = Team::factory()->create();
    $shop = Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'shop.example']);
    $blog = Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'blog.example']);
    $observed = Domain::factory()->for($team)->verified()->create(['hostname' => 'observed.example']);
    $swarms = fn () => collect(test()->withToken('edge-token')->withHeaders(['X-NiceTry-Features' => 'gym-rules'])->getJson(route('edge.gym.triggers'))->assertOk()->json('triggers'))
        ->where('limited', false)
        ->map(fn (array $trigger) => implode(',', $trigger['hosts']).' '.$trigger['path'])
        ->sort()->values()->all();

    expect($swarms())->toBe([]);

    $site = ManualSwarm::create(['team_id' => $team->id, 'domain_id' => $shop->id, 'started_at' => now(), 'ends_at' => now()->addMinutes(5)]);
    tripped($blog, '/api/orders');
    ManualSwarm::create(['team_id' => $team->id, 'domain_id' => $observed->id, 'started_at' => now(), 'ends_at' => now()->addMinutes(5)]);

    expect($swarms())->toBe(['blog-example.preview.example,blog.example /api/orders', 'shop-example.preview.example,shop.example *']);

    $site->update(['ended_at' => now()]);

    expect($swarms())->toBe(['blog-example.preview.example,blog.example /api/orders']);
});
