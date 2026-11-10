<?php

use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\Team;
use App\Models\User;
use App\Support\RuleDestinations;
use Livewire\Livewire;
use NiceTry\Tarpit\Enums\TarpitAfterWait;
use NiceTry\Tarpit\Enums\TarpitOutcome;
use NiceTry\Tarpit\Enums\TarpitReason;
use NiceTry\Tarpit\Enums\TarpitWhenFull;
use NiceTry\Tarpit\Models\Tarpit;
use NiceTry\Tarpit\Models\TarpitHold;

test('an admin sets up a tarpit from the page, with its rules, waits and limits', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $rule = EdgeRule::factory()->for($team)->create(['name' => 'Probers']);
    $shop = Domain::factory()->for($team)->verified()->enforcing()->create();
    $this->actingAs($user);

    $this->get(route('tarpits.index'))->assertOk()->assertSee('No tarpits yet.');

    Livewire::test('tarpit::index')
        ->call('create')
        ->set('name', 'Probers')
        ->set('ruleIds', [$rule->id])
        ->set('countsNotFound', true)
        ->set('notFoundCount', 6)
        ->set('delays', '3, 9,27')
        ->set('maxDelay', 20)
        ->set('afterWait', TarpitAfterWait::NotFound->value)
        ->set('whenFull', TarpitWhenFull::Pass->value)
        ->set('allDomains', false)
        ->set('domainIds', [$shop->id])
        ->call('save')
        ->assertHasNoErrors();

    $tarpit = Tarpit::sole();

    expect($tarpit->team_id)->toBe($team->id)
        ->and($tarpit->delays)->toBe([3, 9, 20])
        ->and($tarpit->not_found_count)->toBe(6)
        ->and($tarpit->burst_count)->toBeNull()
        ->and($tarpit->after_wait)->toBe(TarpitAfterWait::NotFound)
        ->and($tarpit->when_full)->toBe(TarpitWhenFull::Pass)
        ->and($tarpit->rules->modelKeys())->toBe([$rule->id])
        ->and($tarpit->domains->modelKeys())->toBe([$shop->id])
        ->and($tarpit->describeWaits())->toBe('free, 3 s, 9 s, 20 s, then 20 s');

    Livewire::test('tarpit::index')
        ->assertSee('Probers')
        ->call('edit', $tarpit->id)
        ->assertSet('delays', '3, 9, 20')
        ->set('ruleIds', [])
        ->set('countsNotFound', false)
        ->set('takesGymNoShows', false)
        ->call('save')
        ->assertHasErrors('ruleIds')
        ->set('countsBursts', true)
        ->call('save')
        ->assertHasNoErrors()
        ->call('toggle', $tarpit->id);

    expect($tarpit->fresh()->burst_count)->toBe(30)
        ->and($tarpit->fresh()->enabled)->toBeFalse()
        ->and($tarpit->fresh()->rules)->toBeEmpty();
});

test('the activity chart counts holds over the period and toggles to tarpits added', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $domain = Domain::factory()->for($team)->create();
    $tarpit = Tarpit::factory()->for($team)->create(['name' => 'Probers']);

    $held = function ($when) use ($domain, $tarpit) {
        $request = EdgeRequest::factory()->for($domain)->create();

        return TarpitHold::create([
            'edge_request_id' => $request->id,
            'tarpit_id' => $tarpit->id,
            'reason' => TarpitReason::Rule,
            'outcome' => TarpitOutcome::Held,
            'try' => 1,
            'held_ms' => 1000,
            'created_at' => $when,
        ]);
    };

    $held(now());
    $held(now());
    $held(now()->subDays(3));
    $held(now()->subDays(10)); // Older than the 7-day window: off the chart.

    // A hold in another team's tarpit never shows here.
    $theirs = Tarpit::factory()->create();
    TarpitHold::create(['edge_request_id' => EdgeRequest::factory()->create()->id, 'tarpit_id' => $theirs->id, 'reason' => TarpitReason::Rule, 'outcome' => TarpitOutcome::Held, 'try' => 1, 'held_ms' => 1000, 'created_at' => now()]);

    $this->actingAs($user);

    $component = Livewire::test('tarpit::index')
        ->assertSeeHtml('data-test="tarpit-chart"')
        ->assertSeeHtml('data-test="tarpit-chart-source-tarpits"');

    $holdBars = $component->instance()->chartBuckets;
    expect(collect($holdBars)->sum('count'))->toBe(3)
        ->and(end($holdBars)['count'])->toBe(2);

    // Each bar with holds is tinted by the time it held clients (two 1 s holds today), and names that
    // time in its tooltip.
    $today = end($holdBars);
    expect($today['weight'])->toBe(2000)
        ->and($today)->toHaveKeys(['fill', 'note']);

    $component->set('chartSource', 'tarpits');

    // Just the one tarpit the team added within the window, and the tarpits view stays a single colour.
    expect(collect($component->instance()->chartBuckets)->sum('count'))->toBe(1)
        ->and(collect($component->instance()->chartBuckets)->every(fn ($bucket) => ! array_key_exists('fill', $bucket)))->toBeTrue();
});

test('the editor refuses waits it can\'t read and domains of another team', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('tarpit::index')
        ->call('create')
        ->set('name', 'Probers')
        ->set('delays', 'two, eight')
        ->set('allDomains', false)
        ->set('domainIds', [Domain::factory()->create()->id])
        ->call('save')
        ->assertHasErrors(['delays', 'domainIds.0']);

    expect(Tarpit::count())->toBe(0);
});

test('members see the tarpits but can\'t change them, and no one reaches another team\'s', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);
    $tarpit = Tarpit::factory()->for($team)->create(['name' => 'Probers']);
    $theirs = Tarpit::factory()->create();

    $this->actingAs($member)->get(route('tarpits.index'))->assertOk()->assertSee('Probers')->assertDontSeeHtml('data-test="new-tarpit"');

    Livewire::test('tarpit::index')->call('create')->assertForbidden();
    Livewire::test('tarpit::index')->call('toggle', $tarpit->id)->assertForbidden();
    Livewire::test('tarpit::index')->call('edit', $theirs->id)->assertNotFound();

    expect($tarpit->fresh()->enabled)->toBeTrue();
});

test('a TARPIT clause in the script sends the rule\'s clients to a tarpit, made by name, and dropping it releases them', function () {
    $user = User::factory()->create();
    $rule = EdgeRule::factory()->for($user->currentTeam)->script("TAG ip AS 'prober' FOR 1 DAY WHERE category = 'probe'")->create();
    $this->actingAs($user);

    Livewire::test('pages::rules.index')
        ->call('edit', $rule->id)
        ->assertSeeHtml('data-test="rule-destinations"')
        ->set('source', "TAG ip AS 'prober' FOR 1 DAY\nTARPIT 'Probers'\nWHERE category = 'probe'")
        ->call('save')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="rule-destination"');

    $tarpit = $user->currentTeam->tarpits()->where('name', 'Probers')->sole();
    expect($tarpit->rules()->pluck('edge_rules.id')->all())->toBe([$rule->id]);

    Livewire::test('pages::rules.index')
        ->call('edit', $rule->id)
        ->set('source', "TAG ip AS 'prober' FOR 1 DAY\nWHERE category = 'probe'")
        ->call('save')
        ->assertHasNoErrors();

    expect($tarpit->fresh()->rules()->count())->toBe(0);
});

test('a rule connected to a tarpit sits in the board\'s Tarpit column, until the tarpit is off', function () {
    $user = User::factory()->create();
    $rule = EdgeRule::factory()->for($user->currentTeam)->create();
    $tarpit = Tarpit::factory()->for($user->currentTeam)->create(['name' => 'Probers']);
    $tarpit->rules()->attach($rule);
    $this->actingAs($user);

    $columns = fn () => collect(app(RuleDestinations::class)->board($user->currentTeam, $user->currentTeam->edgeRules()->get()))
        ->mapWithKeys(fn (array $lane) => [$lane['column']->key => count($lane['cards'])]);

    expect($columns()['tarpit'])->toBe(1)->and($columns()['tag'])->toBe(0);

    $tarpit->update(['enabled' => false]);

    expect($columns()['tarpit'])->toBe(0)->and($columns()['tag'])->toBe(1);
});
