<?php

use App\Enums\ApiAbility;
use App\Models\AddonSwitch;
use App\Models\User;
use Livewire\Livewire;

/*
| Transfer without the Origins add-on: absent, as in the public release, or switched off. These run in
| both, so the free add-on is checked where the paid one isn't.
*/

beforeEach(function () {
    // Off where it's installed; where it isn't, there's nothing to switch and it's already absent.
    AddonSwitch::factory()->create(['slug' => 'origins']);
});

test('the Transfer page says it needs Origins, and offers nothing to pull or upload', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('transfer::import')
        ->assertOk()
        ->assertSeeHtml('data-test="transfer-needs-origins"')
        ->assertDontSeeHtml('data-test="transfer-local-origins"');
});

test('the pull API answers 404 even to a token that may change domains', function () {
    $token = apiToken([ApiAbility::ManageDomains])[1];

    $this->withToken($token)->getJson('/transfer/v1/origins')->assertNotFound();
    $this->withToken($token)->getJson('/transfer/v1/origins/1')->assertNotFound();
});

test('a transfer file can\'t be downloaded', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('transfer.file', 1))->assertNotFound();
});
