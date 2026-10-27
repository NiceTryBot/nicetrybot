<?php

use App\Enums\ViewSlot;
use App\Models\Domain;
use App\Models\User;
use App\Support\ViewSlots;

test('the core\'s pages are whole with nothing in the slots', function () {
    $this->app->instance(ViewSlots::class, new ViewSlots);

    expect(app(ViewSlots::class)->render(ViewSlot::Head)->toHtml())->toBe('');

    $this->get(route('login'))->assertOk();

    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeHtml('data-test="workspace-menu"');

    $this->get(route('domains.show', $domain))
        ->assertOk()
        ->assertSeeHtml('data-test="open-delete-domain"');

    $this->get(route('domains.index'))
        ->assertOk()
        ->assertSeeHtml('data-test="open-add-domain"')
        ->assertSeeHtml('data-test="domain-card"');

    $this->get(route('rules.index'))
        ->assertOk()
        ->assertSeeHtml('data-test="rule-template"');
});
