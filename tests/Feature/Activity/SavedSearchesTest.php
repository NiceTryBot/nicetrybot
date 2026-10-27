<?php

use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\SavedSearch;
use App\Models\User;
use Livewire\Livewire;

test('a search is saved with its question and filters, and opening it brings them back', function () {
    $user = User::factory()->create();
    Domain::factory()->for($user->currentTeam)->create();
    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', "path LIKE '/sign/%/thank-you%'")
        ->set('question', 'show me all traffic for the public form thank-yous')
        ->set('period', '16h')
        ->set('view', 'clients')
        ->call('startSavingSearch')
        ->assertSet('savedSearchName', 'show me all traffic for the public form thank-yous')
        ->set('savedSearchName', 'Vesync Public Forms')
        ->call('saveSearch')
        ->assertHasNoErrors()
        ->assertSee('Vesync Public Forms');

    $saved = SavedSearch::sole();
    expect($saved->team_id)->toBe($user->currentTeam->id)
        ->and($saved->user_id)->toBe($user->id)
        ->and($saved->parameters)->toBe([
            'q' => "path LIKE '/sign/%/thank-you%'",
            'ask' => 'show me all traffic for the public form thank-yous',
            'period' => '16h',
            'view' => 'clients',
            'hide' => '',
        ]);

    $this->get($saved->url())->assertOk();

    Livewire::withQueryParams($saved->parameters)->test('pages::activity.index')
        ->assertSet('search', "path LIKE '/sign/%/thank-you%'")
        ->assertSet('period', '16h')
        ->assertSet('view', 'clients')
        ->assertSet('currentSavedSearch.id', $saved->id);
});

test('saving under a name the team already uses replaces that search', function () {
    $user = User::factory()->create();
    SavedSearch::factory()->for($user->currentTeam)->create(['user_id' => $user->id, 'name' => 'Vesync Public Forms']);
    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', 'status = 404')
        ->set('savedSearchName', 'vesync public forms')
        ->call('saveSearch');

    expect(SavedSearch::sole())->name->toBe('vesync public forms')
        ->parameters->toMatchArray(['q' => 'status = 404']);
});

test('a saved search needs a name', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::activity.index')
        ->set('savedSearchName', '  ')
        ->call('saveSearch')
        ->assertHasErrors(['savedSearchName' => 'required']);

    expect(SavedSearch::count())->toBe(0);
});

test('the team\'s saved searches are listed by name, without other teams\'', function () {
    $user = User::factory()->create();
    SavedSearch::factory()->for($user->currentTeam)->create(['name' => 'Zebra probes']);
    SavedSearch::factory()->for($user->currentTeam)->create(['name' => 'Vesync Public Forms']);
    SavedSearch::factory()->create(['name' => 'Another team\'s search']);
    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->assertSeeInOrder(['Vesync Public Forms', 'Zebra probes'])
        ->assertDontSee('Another team\'s search');
});

test('only whoever saved a search, or a rules manager, can remove it', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);
    $ownersSearch = SavedSearch::factory()->for($team)->create(['user_id' => $owner->id]);
    $membersSearch = SavedSearch::factory()->for($team)->create(['user_id' => $member->id, 'name' => 'Mine']);
    $elsewhere = SavedSearch::factory()->create();

    $this->actingAs($member);

    Livewire::test('pages::activity.index')->call('deleteSavedSearch', $ownersSearch->id)->assertForbidden();
    Livewire::test('pages::activity.index')->call('deleteSavedSearch', $elsewhere->id)->assertNotFound();
    Livewire::test('pages::activity.index')->call('deleteSavedSearch', $membersSearch->id)->assertOk();

    $this->actingAs($owner);
    Livewire::test('pages::activity.index')->call('deleteSavedSearch', $ownersSearch->id)->assertOk();

    expect(SavedSearch::pluck('id')->all())->toBe([$elsewhere->id]);
});
