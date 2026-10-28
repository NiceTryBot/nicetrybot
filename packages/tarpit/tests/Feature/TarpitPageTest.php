<?php

use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\EdgeRule;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;
use NiceTry\Tarpit\Enums\TarpitAfterWait;
use NiceTry\Tarpit\Enums\TarpitWhenFull;
use NiceTry\Tarpit\Models\Tarpit;

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

test('a rule\'s clients are sent to a tarpit from the rule, and the rule can start a new one', function () {
    $user = User::factory()->create();
    $rule = EdgeRule::factory()->for($user->currentTeam)->script("TAG ip AS 'prober' FOR 1 DAY WHERE category = 'probe'")->create();
    $tarpit = Tarpit::factory()->for($user->currentTeam)->create(['name' => 'Probers']);
    $this->actingAs($user);

    Livewire::test('pages::rules.index')
        ->call('edit', $rule->id)
        ->assertSeeHtml('data-test="rule-tarpit"');

    Livewire::test('tarpit::rule-panel', ['rule' => $rule])
        ->assertSeeHtml('data-test="rule-tarpit-only-tags"')
        ->set('tarpitId', $tarpit->id)
        ->call('send')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="rule-tarpit-connected"')
        ->assertSeeHtml('data-test="rule-tarpit-what-waits"')
        ->assertDontSeeHtml('data-test="rule-tarpit-only-tags"');

    expect($tarpit->rules()->pluck('edge_rules.id')->all())->toBe([$rule->id]);

    Livewire::test('tarpit::rule-panel', ['rule' => $rule])->call('release', $tarpit->id);
    expect($tarpit->rules()->count())->toBe(0);

    Livewire::withQueryParams(['rule' => $rule->id])
        ->test('tarpit::index')
        ->assertSet('ruleIds', [$rule->id])
        ->assertSet('forRule', null);

    Livewire::test('tarpit::rule-panel', ['rule' => EdgeRule::factory()->create()])->assertNotFound();
});
