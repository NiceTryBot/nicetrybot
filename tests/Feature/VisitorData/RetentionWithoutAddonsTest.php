<?php

use App\Models\User;
use App\Models\VisitorDataSetting;
use App\Support\CoreVisitorData;
use App\Support\VisitorDataHolders;
use Illuminate\Container\Container;
use Livewire\Livewire;

test('a team\'s retention saves with only the core\'s periods, as on an installation without add-ons', function () {
    $container = new Container;
    $container->tag([CoreVisitorData::class], VisitorDataHolders::TAG);
    app()->instance(VisitorDataHolders::class, new VisitorDataHolders($container));
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::visitor-data.index')
        ->assertSee('Request log')
        ->assertDontSee('JavaScript fingerprints taken in the gym.')
        ->set('days.request_days', 45)
        ->call('saveRetention')
        ->assertHasNoErrors();

    expect(VisitorDataSetting::sole()->request_days)->toBe(45);
});
