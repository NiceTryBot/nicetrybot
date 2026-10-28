<?php

use App\Actions\Clients\MeasureIpNeighborhoods;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;
use Livewire\Livewire;

/**
 * Give a domain some requests from one IP within the last day.
 */
function requestsFrom(Domain $domain, string $ip, int $count = 1, ?DateTimeInterface $at = null): void
{
    EdgeRequest::factory()->count($count)->for($domain)->create(['client_ip' => $ip, 'occurred_at' => $at ?? now()->subHour()]);
}

test('an IP\'s neighborhood counts its own requests and those of its /24, /16 and /8, in the team\'s recent traffic', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();

    requestsFrom($domain, '198.18.126.138', 3);
    requestsFrom($domain, '198.18.126.7', 2);
    requestsFrom($domain, '198.18.9.1');
    requestsFrom($domain, '198.19.3.4');
    requestsFrom($domain, '10.4.164.126');
    // Too old to count, or another team's.
    requestsFrom($domain, '198.18.126.138', at: now()->subDays(2));
    requestsFrom(Domain::factory()->create(), '198.18.126.138', 5);

    $neighborhoods = app(MeasureIpNeighborhoods::class)->handle($user->currentTeam, ['198.18.126.138', '10.4.164.126', '2001:db8::1']);

    expect($neighborhoods)->toHaveKeys(['198.18.126.138', '10.4.164.126'])->not->toHaveKey('2001:db8::1')
        ->and($neighborhoods['198.18.126.138']->toArray())->toBe(['ip' => '198.18.126.138', 'count8' => 7, 'count16' => 6, 'count24' => 5, 'count32' => 3, 'distinctHosts24' => 2])
        ->and($neighborhoods['10.4.164.126']->toArray())->toBe(['ip' => '10.4.164.126', 'count8' => 1, 'count16' => 1, 'count24' => 1, 'count32' => 1, 'distinctHosts24' => 1]);
});

test('a /24 shared by many quiet hosts is noisy, and a host making most of its /24\'s requests is lone', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();

    foreach (range(1, 10) as $host) {
        requestsFrom($domain, "198.51.100.{$host}", 2);
    }
    requestsFrom($domain, '192.0.2.10', 7);
    requestsFrom($domain, '192.0.2.11', 3);

    $neighborhoods = app(MeasureIpNeighborhoods::class)->handle($user->currentTeam, ['198.51.100.1', '192.0.2.10', '192.0.2.11']);

    expect($neighborhoods['198.51.100.1']->badge()['label'])->toBe('Noisy /24 · 10 hosts')
        ->and($neighborhoods['192.0.2.10']->badge()['label'])->toBe('Lone host')
        ->and($neighborhoods['192.0.2.11']->badge())->toBeNull();
});

test('right-clicking a client can enrich its IP with a prefix ladder, and stop', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    requestsFrom($domain, '198.18.126.138', 3);
    requestsFrom($domain, '198.18.126.7');

    $this->actingAs($user);

    $component = Livewire::test('pages::activity.index')
        ->assertSeeHtml('data-test="enrich-client"')
        ->assertDontSeeHtml('data-test="prefix-ladder"')
        ->call('enrichClient', '198.18.126.138')
        ->assertSeeHtml('data-test="prefix-ladder"')
        ->assertSeeHtml('data-privacy-mask="x"')
        ->assertSeeHtml('aria-label="/24 · 4 hits · 2 IPs"')
        ->assertSeeHtml('aria-label="/32 · 3 hits"');

    // Remembered for the next visit, in either view.
    Livewire::withQueryParams(['view' => 'clients'])->test('pages::activity.index')->assertSeeHtml('data-test="clients-table"')->assertSeeHtml('data-test="prefix-ladder"');

    $component->call('enrichClient', '198.18.126.138')->assertDontSeeHtml('data-test="prefix-ladder"');
});

test('only an IPv4 address can be enriched', function (string $value) {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::activity.index')->call('enrichClient', $value)->assertStatus(422);
})->with(['an IPv6 address' => '2001:db8::1', 'not an IP' => "1' OR 1=1"]);
