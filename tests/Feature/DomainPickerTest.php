<?php

use App\Models\Domain;
use App\Models\User;
use Livewire\Livewire;

test('the domain picker finds the team\'s domains as you type instead of listing them all', function () {
    $user = User::factory()->create();
    Domain::factory()->for($user->currentTeam)->create(['hostname' => 'claims.example.com']);
    Domain::factory()->for($user->currentTeam)->create(['hostname' => 'shop.example.com']);
    Domain::factory()->create(['hostname' => 'claims.rival.com']);

    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')
        ->assertSeeHtml('data-test="domain-picker"')
        ->assertDontSeeHtml('<option value="shop.example.com"');

    expect($page->instance()->searchDomains('CLAIMS'))->toBe(['claims.example.com'])
        ->and($page->instance()->searchDomains(''))->toBe(['claims.example.com', 'shop.example.com'])
        ->and($page->instance()->searchDomains('%'))->toBe([]);
});
