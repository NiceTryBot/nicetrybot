<?php

use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;
use Livewire\Livewire;

const IPS_VIEW_CHROME_JA4 = 't13d1517h2_8daaf6152771_cb7bf5808d99';
const IPS_VIEW_FIREFOX_JA4 = 't13d1717h2_5b57614c22b0_3cbfd9057e0d';

/**
 * One IP on two TLS fingerprints, and a quieter IP on one.
 */
function ipsViewTraffic(User $user): void
{
    $domain = Domain::factory()->for($user->currentTeam)->create();

    EdgeRequest::factory()->for($domain)->fromClient('Mozilla/5.0 Chrome/155.0.0.0', IPS_VIEW_CHROME_JA4)->count(3)->create(['client_ip' => '198.51.100.1']);
    EdgeRequest::factory()->for($domain)->fromClient('Mozilla/5.0 Firefox/158.0', IPS_VIEW_FIREFOX_JA4)->count(2)->create(['client_ip' => '198.51.100.1']);
    EdgeRequest::factory()->for($domain)->fromClient('Mozilla/5.0 Firefox/158.0', IPS_VIEW_FIREFOX_JA4)->create(['client_ip' => '198.51.100.2']);
}

test('the IPs tab has one row per IP, busiest first, however many fingerprints it used', function () {
    $user = User::factory()->create();
    ipsViewTraffic($user);
    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')
        ->set('view', 'ips')
        ->assertSeeHtml('data-test="clients-table"')
        ->assertSeeText('IPs (2)')
        ->assertSeeText('Clients (3)')
        ->assertSeeText('2 TLS fingerprints')
        ->assertSeeInOrder(['198.51.100.1', '198.51.100.2'])
        ->assertSeeHtml('data-client-kind="ip"');

    expect(substr_count($page->html(), 'data-test="client-row"'))->toBe(2)
        ->and($page->instance()->clients->total())->toBe(2)
        ->and((int) $page->instance()->clients->first()->requests)->toBe(5);
});

test('the Clients tab still counts each fingerprint apart', function () {
    $user = User::factory()->create();
    ipsViewTraffic($user);
    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')
        ->set('view', 'clients')
        ->assertSeeText('Clients (3)')
        ->assertSeeText('IPs (2)');

    expect(substr_count($page->html(), 'data-test="client-row"'))->toBe(3);
});

test('the tabs read Requests, Clients, Paths, IPs', function () {
    $user = User::factory()->create();
    ipsViewTraffic($user);
    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->assertSeeInOrder(['data-test="view-requests"', 'data-test="view-clients"', 'data-test="view-paths"', 'data-test="view-ips"'], escape: false);
});

test('picking an IP lists all its requests, on any fingerprint', function () {
    $user = User::factory()->create();
    ipsViewTraffic($user);
    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')
        ->set('view', 'ips')
        ->call('showClient', '198.51.100.1', '')
        ->assertSet('view', 'requests')
        ->assertSet('search', "ip = '198.51.100.1'");

    expect($page->instance()->requests->count())->toBe(5);
});

test('a search that groups by IP alone has one IPs tab, whichever view is open', function (string $view) {
    $user = User::factory()->create();
    ipsViewTraffic($user);
    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')
        ->set('view', $view)
        ->set('search', "ip != '203.0.113.1' GROUP BY ip")
        ->assertSeeText('IPs (2)')
        ->assertDontSeeHtml('data-test="view-ips"')
        ->assertSeeHtml('data-test="clients-table"');

    expect(substr_count($page->html(), 'data-test="client-row"'))->toBe(2);
})->with(['clients', 'ips']);

test('the IPs tab follows the search', function () {
    $user = User::factory()->create();
    ipsViewTraffic($user);
    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('view', 'ips')
        ->set('search', "ip = '198.51.100.2'")
        ->assertSeeText('IPs (1)')
        ->assertSee('198.51.100.2')
        ->assertDontSee('198.51.100.1');
});

test('the IPs tab has its place in the address', function () {
    $user = User::factory()->create();
    ipsViewTraffic($user);
    $this->actingAs($user);

    Livewire::withQueryParams(['view' => 'ips'])->test('pages::activity.index')
        ->assertSet('view', 'ips')
        ->assertSeeHtml('data-test="clients-table"');
});
