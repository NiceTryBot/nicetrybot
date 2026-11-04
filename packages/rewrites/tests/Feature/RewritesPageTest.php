<?php

use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;
use NiceTry\Rewrites\Enums\RewriteContent;
use NiceTry\Rewrites\Models\ResponseRewrite;

test('an admin sets up a team-wide rewrite, then narrows it to one domain and switches it off', function () {
    $user = User::factory()->create();
    $shop = Domain::factory()->for($user->currentTeam)->verified()->create(['hostname' => 'shop.example']);
    $this->actingAs($user);

    $this->get(route('rewrites.index'))->assertOk()->assertSee('No rewrites yet.');

    Livewire::test('rewrites::index')
        ->call('create')
        ->set('find', 'foo')
        ->set('replace', 'bar')
        ->call('save')
        ->assertHasNoErrors();

    $rewrite = ResponseRewrite::sole();

    expect($rewrite->team_id)->toBe($user->currentTeam->id)
        ->and($rewrite->domain_id)->toBeNull()
        ->and($rewrite->content)->toBe(RewriteContent::Html)
        ->and($rewrite->in_headers)->toBeFalse()
        ->and($rewrite->enabled)->toBeTrue();

    Livewire::test('rewrites::index')
        ->assertSee('Every domain')
        ->call('edit', $rewrite->id)
        ->assertSet('find', 'foo')
        ->set('domainId', $shop->id)
        ->set('ignoreCase', true)
        ->set('content', RewriteContent::Text->value)
        ->set('inHeaders', true)
        ->call('save')
        ->assertHasNoErrors()
        ->call('toggle', $rewrite->id);

    $rewrite->refresh();

    expect($rewrite->domain_id)->toBe($shop->id)
        ->and($rewrite->ignore_case)->toBeTrue()
        ->and($rewrite->content)->toBe(RewriteContent::Text)
        ->and($rewrite->in_headers)->toBeTrue()
        ->and($rewrite->enabled)->toBeFalse();
});

test('a rewrite can remove text, but needs something to find, a change, and one of the team\'s own domains', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('rewrites::index')
        ->call('create')
        ->set('find', '')
        ->call('save')
        ->assertHasErrors(['find' => 'required'])
        ->set('find', 'foo')
        ->set('replace', 'foo')
        ->call('save')
        ->assertHasErrors(['replace' => 'different'])
        ->set('replace', 'bar')
        ->set('domainId', Domain::factory()->create()->id)
        ->call('save')
        ->assertHasErrors('domainId')
        ->set('domainId', '')
        ->set('replace', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(ResponseRewrite::sole()->replace)->toBe('');
});

test('members see the rewrites but can\'t change them, and no one reaches another team\'s', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);
    $rewrite = ResponseRewrite::factory()->for($team)->create(['find' => 'Old Co']);
    $theirs = ResponseRewrite::factory()->create();

    $this->actingAs($member)->get(route('rewrites.index'))->assertOk()->assertSee('Old Co')->assertDontSeeHtml('data-test="new-rewrite"');

    Livewire::test('rewrites::index')->call('create')->assertForbidden();
    Livewire::test('rewrites::index')->call('toggle', $rewrite->id)->assertForbidden();
    Livewire::test('rewrites::index')->call('edit', $theirs->id)->assertNotFound();

    expect($rewrite->fresh()->enabled)->toBeTrue();
});
