<?php

use App\Enums\ApiAbility;
use App\Models\Administrator;
use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('only administrators can open the API tokens page', function () {
    $this->get(route('admin.tokens'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get(route('admin.tokens'))->assertForbidden();

    $this->actingAs(Administrator::factory()->create()->user)
        ->get(route('admin.tokens'))
        ->assertOk()
        ->assertSee(route('mcp'));
});

test('an administrator makes a token for one of their teams, shown once and stored only as a hash', function () {
    $admin = Administrator::factory()->create()->user;
    $this->actingAs($admin);

    $component = Livewire::test('pages::admin.tokens')
        ->set('name', 'Claude Code')
        ->set('teamId', $admin->current_team_id)
        ->set('abilities', [ApiAbility::ManageDomains->value])
        ->set('lifetimeHours', 4)
        ->call('create')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="new-token"');

    $plainText = $component->get('plainTextToken');
    $token = ApiToken::sole();

    expect(ApiToken::looksValid($plainText))->toBeTrue()
        ->and($token->token_hash)->toBe(hash('sha256', $plainText))
        ->and($token->abilities)->toBe([ApiAbility::Read->value, ApiAbility::ManageDomains->value])
        ->and($token->team_id)->toBe($admin->current_team_id)
        ->and($token->expires_at->between(now()->addHours(4)->subMinute(), now()->addHours(4)->addMinute()))->toBeTrue();

    $component->call('dismissToken')->assertSet('plainTextToken', null)->assertDontSee($plainText);
});

test('a new token starts with the least: read only, for an hour', function () {
    $this->actingAs(Administrator::factory()->create()->user);

    Livewire::test('pages::admin.tokens')
        ->assertSet('abilities', [])
        ->assertSet('lifetimeHours', 1)
        ->set('name', 'Quick look')
        ->call('create');

    expect(ApiToken::sole())
        ->abilities->toBe([ApiAbility::Read->value])
        ->expires_at->lessThanOrEqualTo(now()->addHour())->toBeTrue();
});

test('a token can\'t be for a team the administrator isn\'t on', function () {
    $this->actingAs(Administrator::factory()->create()->user);

    Livewire::test('pages::admin.tokens')
        ->set('name', 'Sneaky')
        ->set('teamId', Team::factory()->create()->id)
        ->call('create')
        ->assertHasErrors('teamId');

    expect(ApiToken::count())->toBe(0);
});

test('an administrator revokes their own tokens but not anyone else\'s', function () {
    $admin = Administrator::factory()->create()->user;
    [$mine] = ApiToken::issue($admin, $admin->currentTeam, 'Mine', []);
    [$theirs] = apiToken();
    $this->actingAs($admin);

    Livewire::test('pages::admin.tokens')
        ->assertSee('Mine')
        ->assertDontSee($theirs->name === 'Mine' ? '' : $theirs->name)
        ->call('revoke', $mine->id);

    expect(ApiToken::find($mine->id))->toBeNull();

    Livewire::test('pages::admin.tokens')->call('revoke', $theirs->id)->assertNotFound();

    expect(ApiToken::find($theirs->id))->not->toBeNull();
});
