<?php

use App\Enums\GymExit;
use App\Enums\GymSessionStatus;
use App\Enums\HttpMethod;
use App\Enums\LabelKind;
use App\Enums\RuleAction;
use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\GymSession;
use App\Models\Team;
use App\Models\User;
use App\Models\Verdict;
use Livewire\Livewire;

afterEach(fn () => putenv('LAMBDA_TASK_ROOT'));

/**
 * Run as on Vapor, where the runtime sets LAMBDA_TASK_ROOT and X-Vapor-Source-Ip carries the viewer's IP.
 */
function onVapor(): void
{
    putenv('LAMBDA_TASK_ROOT=/var/task');
}

/**
 * A TAG rule on the team, with the script saved as the editor would.
 */
function tagRule(Team $team, string $name, string $source): EdgeRule
{
    return EdgeRule::factory()->for($team)->create(['name' => $name, 'source' => $source, 'action' => RuleAction::Tag, 'duration_seconds' => null]);
}

/**
 * A client the rule tagged.
 *
 * @param  array<string, mixed>  $attributes
 */
function taggedClient(EdgeRule $rule, string $tag, string $clientIp, array $attributes = []): Verdict
{
    return Verdict::factory()->for($rule, 'rule')->create([
        'team_id' => $rule->team_id,
        'action' => RuleAction::Tag,
        'tag' => $tag,
        'client_ip' => $clientIp,
        'expires_at' => null,
        ...$attributes,
    ]);
}

test('tags are listed with how many clients hold them, the rules that set them and the gym routines that act on them', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $hunt = tagRule($team, 'Bad actor hunt', "TAG ip AS 'bad-actor' FOR FOREVER\nWHERE ip = '203.0.113.147'");
    $logins = tagRule($team, 'Failed Login Watch', "TAG ip AS 'failed-login' FOR 1 DAY\nWHERE status = 401");
    GymRoutine::factory()->for($team)->entering([['field' => 'verdict', 'operator' => 'in', 'values' => [(string) $hunt->id]]])->create(['name' => 'Bad actor gym']);
    taggedClient($hunt, 'bad-actor', '203.0.113.147');
    taggedClient($hunt, 'bad-actor', '203.0.113.40');
    taggedClient($logins, 'failed-login', '198.51.100.4');
    taggedClient($hunt, 'bad-actor', '198.51.100.9', ['lifted_at' => now()]);
    taggedClient(tagRule(Team::factory()->create(), 'Theirs', "TAG ip AS 'theirs' FOR 1 DAY\nWHERE status = 404"), 'theirs', '203.0.113.50');
    $team->clientLabels()->create(['kind' => LabelKind::Ip, 'value' => '203.0.113.40', 'name' => 'Me - Home']);

    $this->actingAs($user);

    $this->get(route('tags.index'))->assertOk()->assertSeeInOrder(['bad-actor', '2', 'Bad actor hunt', 'Bad actor gym', 'failed-login', '1', 'Failed Login Watch'])->assertDontSee('theirs');

    Livewire::test('pages::tags.index')
        ->call('show', 'bad-actor')
        ->assertSee(['203.0.113.147', 'Me - Home'])
        ->assertDontSee(['198.51.100.4', '198.51.100.9'])
        ->set('search', 'home')
        ->assertSee('Me - Home')
        ->assertDontSee('203.0.113.147');
});

test('tagged clients can be lifted one at a time, by selection, or all at once, within the tag being shown', function () {
    $user = User::factory()->create();
    $rule = tagRule($user->currentTeam, 'Hunt', "TAG ip AS 'bad-actor' FOR FOREVER\nWHERE ip = '203.0.113.147'");
    $one = taggedClient($rule, 'bad-actor', '203.0.113.1');
    $two = taggedClient($rule, 'bad-actor', '203.0.113.2');
    $three = taggedClient($rule, 'bad-actor', '203.0.113.3');
    $four = taggedClient($rule, 'bad-actor', '203.0.113.4');
    $otherTag = taggedClient($rule, 'failed-login', '203.0.113.5');
    $otherTeam = taggedClient(tagRule(Team::factory()->create(), 'Theirs', "TAG ip AS 'bad-actor' FOR 1 DAY\nWHERE status = 404"), 'bad-actor', '203.0.113.6');

    $this->actingAs($user);

    $component = Livewire::test('pages::tags.index')->call('show', 'bad-actor')->call('lift', $one->id)->call('lift', $otherTeam->id);

    $component->set('selected', [(string) $two->id, (string) $otherTag->id])->call('liftSelected');

    expect(Verdict::active()->pluck('id')->sort()->values()->all())->toBe([$three->id, $four->id, $otherTag->id, $otherTeam->id]);

    $component->call('liftAll');

    expect(Verdict::active()->pluck('id')->sort()->values()->all())->toBe([$otherTag->id, $otherTeam->id]);
});

test('members can see tags but not lift them', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $owner->currentTeam->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($owner->currentTeam);
    $verdict = taggedClient(tagRule($owner->currentTeam, 'Hunt', "TAG ip AS 'bad-actor' FOR FOREVER\nWHERE status = 404"), 'bad-actor', '203.0.113.1');

    $this->actingAs($member);

    Livewire::test('pages::tags.index')->call('show', 'bad-actor')->assertSee('203.0.113.1')->assertDontSee('Lift all')->call('lift', $verdict->id)->assertForbidden();

    expect($verdict->fresh()->lifted_at)->toBeNull();
});

test('a search looks through every tag and ban, an IP range included, and lifts across them', function () {
    $user = User::factory()->create();
    $rule = tagRule($user->currentTeam, 'Probe paths', "TAG ip, ja4 AS 'probe' FOR 1 DAY\nWHERE category = 'probe'");
    $probe = taggedClient($rule, 'probe', '198.51.100.115');
    $ban = Verdict::factory()->create(['team_id' => $user->currentTeam->id, 'client_ip' => '198.51.100.12']);
    $elsewhere = taggedClient($rule, 'probe', '203.0.113.9');
    $theirs = taggedClient(tagRule(Team::factory()->create(), 'Theirs', "TAG ip AS 'probe' FOR 1 DAY\nWHERE status = 404"), 'probe', '198.51.100.34');

    $this->actingAs($user);

    Livewire::test('pages::tags.index')
        ->call('show', 'probe')
        ->set('search', '198.51.100.0/24')
        ->assertSet('tag', '')
        ->assertSee(['198.51.100.115', '198.51.100.12', 'Banned'])
        ->assertDontSee(['203.0.113.9', '198.51.100.34'])
        ->call('liftAll');

    expect(Verdict::active()->pluck('id')->sort()->values()->all())->toBe([$elsewhere->id, $theirs->id]);
});

test('each tag and listed client shows the requests sent the team\'s sites over the period picked, the last hour at first', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $verdict = taggedClient(tagRule($user->currentTeam, 'Hunt', "TAG ip AS 'bad-actor' FOR FOREVER\nWHERE status = 404"), 'bad-actor', '198.51.100.7');
    EdgeRequest::factory()->for($domain)->count(2)->create(['client_ip' => '198.51.100.7', 'occurred_at' => now()->subMinutes(30)]);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.7', 'occurred_at' => now()->subHours(3)]);
    EdgeRequest::factory()->for(Domain::factory())->create(['client_ip' => '198.51.100.7', 'occurred_at' => now()->subMinutes(10)]);

    $this->actingAs($user);

    $page = Livewire::test('pages::tags.index')->call('show', 'bad-actor')->assertSet('period', '1h');

    expect($page->instance()->requestsFrom($verdict))->toBe(2)
        ->and($page->instance()->groups->firstWhere('tag', 'bad-actor')['requests'])->toBe(2);

    $page->set('period', '24h');

    expect($page->instance()->requestsFrom($verdict))->toBe(3)
        ->and($page->instance()->groups->firstWhere('tag', 'bad-actor')['requests'])->toBe(3);

    $page->set('period', '30d')->assertSet('period', '1h');
});

test('each listed client shows the network and place its latest request came from', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    taggedClient(tagRule($user->currentTeam, 'Probe paths', "TAG ip AS 'probe' FOR 1 DAY\nWHERE category = 'probe'"), 'probe', '203.0.113.93');
    EdgeRequest::factory()->for($domain)->fromNetwork(16276, 'OVH SAS')->locatedIn('FR', 'Hauts-de-France', 'Roubaix')->create(['client_ip' => '203.0.113.93', 'occurred_at' => now()->subHours(2)]);
    EdgeRequest::factory()->for($domain)->fromNetwork(211590, 'UNMANAGED LTD')->locatedIn('NL', 'North Holland', 'Amsterdam')->create(['client_ip' => '203.0.113.93', 'occurred_at' => now()->subMinutes(5)]);

    $this->actingAs($user);

    Livewire::test('pages::tags.index')
        ->call('show', 'probe')
        ->assertSeeInOrder(['203.0.113.93', 'NL', 'North Holland', 'UNMANAGED LTD'])
        ->assertSee('AS211590')
        ->assertDontSee(['OVH SAS', 'Hauts-de-France']);
});

test('the page says when your rules hold the IP you browse from, and lets you lift yourself and the gym\'s 403', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $rule = tagRule($team, 'Probe paths', "TAG ip, ja4 AS 'probe' FOR 1 DAY\nWHERE category = 'probe'");
    $mine = taggedClient($rule, 'probe', '198.51.100.115');
    $someoneElse = taggedClient($rule, 'probe', '203.0.113.9');
    $turnedAway = GymSession::factory()->for($team)->create(['client_ip' => '198.51.100.115', 'status' => GymSessionStatus::TurnedAway, 'exit_action' => GymExit::Block]);

    onVapor();
    $this->actingAs($user);

    $this->withHeaders(['X-Vapor-Source-Ip' => '198.51.100.115'])->get(route('dashboard'))->assertSeeInOrder(['data-test="nav-tags"', 'data-flux-navlist-badge>You</span>', 'Tags &amp; bans'], escape: false);
    $this->withHeaders(['X-Vapor-Source-Ip' => '198.51.100.20'])->get(route('dashboard'))->assertSee('Tags &amp; bans', escape: false)->assertDontSee('data-flux-navlist-badge>You</span>', escape: false);

    Livewire::withHeaders(['X-Vapor-Source-Ip' => '198.51.100.115'])
        ->test('pages::tags.index')
        ->assertSeeHtml('data-test="viewer-held"')
        ->assertSee(['198.51.100.115', 'Probe paths'])
        ->call('liftMine');

    expect($mine->fresh()->lifted_at)->not->toBeNull()
        ->and($someoneElse->fresh()->lifted_at)->toBeNull()
        ->and($turnedAway->fresh()->exit_action)->toBeNull()
        ->and($turnedAway->fresh()->status)->toBe(GymSessionStatus::TurnedAway);

    Livewire::withHeaders(['X-Vapor-Source-Ip' => '198.51.100.115'])->test('pages::tags.index')->assertSeeHtml('data-test="viewer-free"')->assertDontSeeHtml('data-test="viewer-held"');
});

test('members can\'t lift the tags on their own IP either', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $owner->currentTeam->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($owner->currentTeam);
    $verdict = taggedClient(tagRule($owner->currentTeam, 'Hunt', "TAG ip AS 'bad-actor' FOR FOREVER\nWHERE status = 404"), 'bad-actor', '198.51.100.115');

    onVapor();
    $this->actingAs($member);

    Livewire::withHeaders(['X-Vapor-Source-Ip' => '198.51.100.115'])->test('pages::tags.index')->assertDontSeeHtml('data-test="lift-mine"')->call('liftMine')->assertForbidden();

    expect($verdict->fresh()->lifted_at)->toBeNull();
});

test('off Vapor, a visitor can\'t claim an IP with X-Vapor-Source-Ip', function () {
    $user = User::factory()->create();
    taggedClient(tagRule($user->currentTeam, 'Probe paths', "TAG ip AS 'probe' FOR 1 DAY\nWHERE category = 'probe'"), 'probe', '198.51.100.115');

    $this->actingAs($user);

    $this->withHeaders(['X-Vapor-Source-Ip' => '198.51.100.115'])->get(route('dashboard'))->assertDontSee('data-flux-navlist-badge>You</span>', escape: false);
    Livewire::withHeaders(['X-Vapor-Source-Ip' => '198.51.100.115'])->test('pages::tags.index')->assertDontSeeHtml('data-test="viewer-held"');
});

test('saving a rule offers to lift the tags its new version no longer matches', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'sign.example']);
    $rule = tagRule($user->currentTeam, 'Hunt', "TAG ip AS 'bad-actor' FOR FOREVER\nWHERE ip IN ('203.0.113.147', '203.0.113.40') AND path = '/login'");

    foreach (['203.0.113.147', '203.0.113.40'] as $clientIp) {
        EdgeRequest::factory()->for($domain)->create(['client_ip' => $clientIp, 'method' => HttpMethod::Post, 'path' => '/login', 'occurred_at' => now()->subHours(3)]);
    }

    $stillMatches = taggedClient($rule, 'bad-actor', '203.0.113.147', ['first_matched_at' => now()->subHours(3), 'last_matched_at' => now()->subHours(3)]);
    $removed = taggedClient($rule, 'bad-actor', '203.0.113.40', ['first_matched_at' => now()->subHours(3), 'last_matched_at' => now()->subHours(3)]);

    $this->actingAs($user);

    $component = Livewire::test('pages::rules.index')
        ->call('edit', $rule->id)
        ->set('source', "TAG ip AS 'bad-actor' FOR FOREVER\nWHERE ip IN ('203.0.113.147') AND path = '/login'")
        ->call('save')
        ->assertSet('staleVerdictIds', [$removed->id])
        ->assertSee('1 client still holds a verdict this rule no longer matches.');

    $component->call('liftStale')->assertSet('staleVerdictIds', []);

    expect($removed->fresh()->lifted_at)->not->toBeNull()
        ->and($stillMatches->fresh()->lifted_at)->toBeNull();
});

test('a tag is stale when the rule now tags under another name, or a HAVING threshold the client no longer reaches', function (string $newSource) {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $rule = tagRule($user->currentTeam, 'Failed logins', "TAG ip AS 'failed-login' FOR 1 DAY\nWHERE status = 401\nWITHIN 10 MINUTES\nHAVING COUNT(*) >= 3");

    foreach ([0, 1, 2] as $minutes) {
        EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.4', 'status_code' => 401, 'occurred_at' => now()->subHour()->addMinutes($minutes)]);
    }

    $verdict = taggedClient($rule, 'failed-login', '198.51.100.4', ['first_matched_at' => now()->subHour(), 'last_matched_at' => now()->subHour()->addMinutes(2)]);

    $this->actingAs($user);

    $component = Livewire::test('pages::rules.index')->call('edit', $rule->id);
    $component->call('save')->assertSet('staleVerdictIds', []);
    $component->set('source', $newSource)->call('save')->assertSet('staleVerdictIds', [$verdict->id]);
})->with([
    'renamed tag' => ["TAG ip AS 'serial-login' FOR 1 DAY\nWHERE status = 401\nWITHIN 10 MINUTES\nHAVING COUNT(*) >= 3"],
    'higher threshold' => ["TAG ip AS 'failed-login' FOR 1 DAY\nWHERE status = 401\nWITHIN 10 MINUTES\nHAVING COUNT(*) >= 5"],
]);

test('a request\'s details show the tags its client holds, and lift them', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $request = EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.40']);
    $rule = tagRule($user->currentTeam, 'Bad actor hunt', "TAG ip AS 'bad-actor' FOR FOREVER\nWHERE status = 404");
    $verdict = taggedClient($rule, 'bad-actor', '203.0.113.40');
    taggedClient($rule, 'someone-else', '198.51.100.4');

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->call('inspect', $request->id)
        ->assertSeeInOrder(['This client is tagged right now', 'bad-actor', 'Bad actor hunt'])
        ->assertDontSee('someone-else')
        ->call('liftVerdict', $verdict->id)
        ->assertDontSee('This client is tagged right now');

    expect($verdict->fresh()->lifted_at)->not->toBeNull();
});

test('a tag shows its most recent clients first and the rest a page at a time', function () {
    $user = User::factory()->create();
    $rule = tagRule($user->currentTeam, 'Outdated', "TAG ip AS 'outdated' FOR 1 DAY\nWHERE status = 404");
    Verdict::factory()->for($rule, 'rule')->count(60)->sequence(fn ($sequence) => [
        'team_id' => $rule->team_id,
        'action' => RuleAction::Tag,
        'tag' => 'outdated',
        'client_ip' => '198.51.100.'.($sequence->index + 1),
        'expires_at' => null,
        'last_matched_at' => now()->subMinutes($sequence->index),
    ])->create();

    $this->actingAs($user);

    Livewire::test('pages::tags.index')
        ->call('show', 'outdated')
        ->assertSee('Showing 50 of 60')
        ->assertSee('198.51.100.1<', false)
        ->assertDontSee('198.51.100.60<', false)
        ->assertSee('Lift all 60')
        ->call('showMore')
        ->assertSee('198.51.100.60<', false)
        ->assertDontSee('Showing 50 of 60');
});
