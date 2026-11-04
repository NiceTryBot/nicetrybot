<?php

use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;
use NiceTry\Rewrites\Models\ResponseRewrite;

test('a domain\'s page shows its rewrites and the team\'s, and adds, toggles and deletes its own', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $shop = Domain::factory()->for($team)->verified()->create(['hostname' => 'shop.example']);
    $blog = Domain::factory()->for($team)->verified()->create(['hostname' => 'blog.example']);
    $teamWide = ResponseRewrite::factory()->for($team)->create(['find' => 'Everywhere']);
    ResponseRewrite::factory()->for($team)->create(['domain_id' => $blog->id, 'find' => 'Only on blog']);
    $this->actingAs($user);

    $this->get(route('domains.show', $shop))
        ->assertOk()
        ->assertSeeHtml('data-test="domain-rewrites"')
        ->assertSee('Everywhere')
        ->assertDontSee('Only on blog');

    $panel = Livewire::test('rewrites::domain-panel', ['domain' => $shop])
        ->call('create')
        ->set('find', 'foo')
        ->set('replace', 'bar')
        ->set('inHeaders', true)
        ->call('save')
        ->assertHasNoErrors();

    $own = ResponseRewrite::query()->where('find', 'foo')->sole();
    expect($own->domain_id)->toBe($shop->id)
        ->and($own->in_headers)->toBeTrue();

    $panel->call('toggle', $own->id);
    expect($own->fresh()->enabled)->toBeFalse();

    $panel->call('delete', $own->id);
    Livewire::test('rewrites::domain-panel', ['domain' => $shop])->call('toggle', $teamWide->id)->assertNotFound();

    expect(ResponseRewrite::query()->whereKey($own->id)->exists())->toBeFalse()
        ->and($teamWide->fresh()->enabled)->toBeTrue();
});

test('members see a domain\'s rewrites but can\'t add one', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);
    $shop = Domain::factory()->for($team)->verified()->create();
    ResponseRewrite::factory()->for($team)->create(['domain_id' => $shop->id, 'find' => 'Old Co']);
    $this->actingAs($member);

    Livewire::test('rewrites::domain-panel', ['domain' => $shop])
        ->assertSee('Old Co')
        ->assertDontSeeHtml('data-test="domain-rewrite-new"')
        ->call('create')
        ->assertForbidden();
});
