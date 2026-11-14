<?php

use App\Addons\AddonSwitchboard;
use App\Models\AddonSwitch;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use NiceTry\Tarpit\Models\Tarpit;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
    Cache::flush();
});

test('installing the Tarpit traps from the board makes their tarpits, and the edge holds error answers and probes itself', function () {
    $user = User::factory()->create();
    Domain::factory()->for($user->currentTeam)->verified()->enforcing()->create(['hostname' => 'shop.example']);
    $this->actingAs($user);

    Livewire::withQueryParams(['filters' => ['pack' => 'tarpit']])
        ->test('pages::rules.index')
        ->assertSeeHtmlInOrder(['data-test="board-column-tarpit"', 'Error answers wait', 'Probe paths wait'])
        ->call('addFromFilter', 'pack', 'error-answers')
        ->call('addFromFilter', 'pack', 'probe-paths');

    expect($user->currentTeam->edgeRules()->pluck('enabled')->unique()->all())->toBe([true])
        ->and(Tarpit::query()->orderBy('name')->pluck('name')->all())->toBe(['Error answers', 'Probe paths']);

    $tarpits = collect($this->withToken('edge-token')->getJson(route('edge.tarpits'))->json('tarpits'))
        ->keyBy(fn (array $tarpit) => Tarpit::find($tarpit['id'])->name);

    // Decided by the answer, or by the one request: the edge acts as it happens, for anyone.
    expect($tarpits['Error answers']['statuses'])->toBe([401, 403, 404, 405, 429, 500])
        ->and($tarpits['Error answers']['rules'])->toBe([])
        ->and($tarpits['Probe paths']['statuses'])->toBe([])
        ->and($tarpits['Probe paths']['rules'])->toHaveCount(1);

    AddonSwitch::factory()->create(['slug' => Tarpit::PRO_ADDON]);
    app(AddonSwitchboard::class)->forget();
    Cache::flush();

    $free = collect($this->withToken('edge-token')->getJson(route('edge.tarpits'))->json('tarpits'))
        ->keyBy(fn (array $tarpit) => Tarpit::find($tarpit['id'])->name);

    expect($free['Error answers']['statuses'])->toBe([404]);
});
