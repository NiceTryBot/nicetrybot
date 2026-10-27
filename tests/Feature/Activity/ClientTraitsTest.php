<?php

use App\Actions\Clients\FindCurrentChromeVersion;
use App\Actions\Clients\FindCurrentFirefoxReleases;
use App\Data\BrowserReleases;
use App\Data\ClientProfile;
use App\Enums\ClientTrait;
use App\Enums\HttpMethod;
use App\Enums\NetworkKind;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

$chromeOnIphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/152.0.7977.53 Mobile/15E148 Safari/604.1';
$chromeOnWindows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36';
$chromeTls = 't13d1517h2_8daaf6152771_cb7bf5808d99';
$appleTls = 't13d2013h2_a09f3c656075_7f0f34a4126d';
$goTls = 't13d131100_f57a46bbacb6_e7c285222651';
$iosHints = '"Chromium";v="152", "Not?A_Brand";v="24", "Google Chrome";v="152" | "iOS"';

test('a spoofed client is flagged where it appears in the activity log', function () use ($chromeOnIphone, $chromeTls) {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->fromClient($chromeOnIphone, $chromeTls)->create(['path' => '/sign/claim']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')->call('loadCounts')
        ->assertSeeHtml('data-test="client-trait-spoofed"')
        ->assertSee("Its user agent says iOS, but it connected with Chrome's TLS.")
        ->assertSeeText('1 spoofed');
});

test('the spoofed filter keeps the requests of a spoofed client', function (string $userAgent, string $ja4, ?string $clientHints) {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->fromClient($userAgent, $ja4, $clientHints)->create(['path' => '/sign/claim']);
    EdgeRequest::factory()->for($domain)->genuineChrome()->create(['path' => '/a-genuine-visit']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('client', 'spoofed')
        ->assertSee('/sign/claim')
        ->assertDontSee('/a-genuine-visit');
})->with([
    'an iPhone on Chrome\'s TLS' => [$chromeOnIphone, $chromeTls, null],
    'client hints claiming iOS' => [$chromeOnIphone, $appleTls, $iosHints],
    'Chrome on a Go TLS stack' => [$chromeOnWindows, $goTls, null],
    'Chrome sending no client hints' => [$chromeOnWindows, $chromeTls, null],
    'client hints naming another platform' => [$chromeOnWindows, $chromeTls, '"Google Chrome";v="155", "Chromium";v="155" | "macOS"'],
]);

test('the spoofed filter leaves out a genuine client', function (string $userAgent, string $ja4, int $asn, ?string $clientHints = null) {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->fromClient($userAgent, $ja4, $clientHints)->fromNetwork($asn, 'Some network', NetworkKind::Unknown)->create(['path' => '/sign/claim']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('client', 'spoofed')
        ->assertDontSee('/sign/claim');
})->with([
    'a real iPhone' => [$chromeOnIphone, $appleTls, 7922],
    'an iPhone behind Zscaler' => [$chromeOnIphone, $chromeTls, 22616],
    'Chrome over HTTP/3' => [$chromeOnWindows, 'q13d0314h3_55b375c5d22e_79cc91d6b50c', 7922, '"Google Chrome";v="155", "Chromium";v="155" | "Windows"'],
]);

test('the outdated filter keeps Chrome that is further behind than people on its platform get', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->genuineChrome(155)->count(FindCurrentChromeVersion::MIN_CLIENTS)
        ->sequence(fn (Sequence $sequence) => ['client_ip' => '198.51.100.'.($sequence->index + 1), 'path' => '/current-'.$sequence->index])
        ->create();
    EdgeRequest::factory()->for($domain)
        ->fromClient('Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Mobile Safari/537.36', 't13d1517h2_8daaf6152771_cb7bf5808d99')
        ->create(['path' => '/an-old-phone']);
    EdgeRequest::factory()->for($domain)->genuineChrome(152)->create(['path' => '/a-desktop-left-running']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('client', 'outdated')
        ->assertSee('/an-old-phone')
        ->assertDontSee('/a-desktop-left-running')
        ->assertDontSee('/current-0');
});

test('the outdated filter keeps an old Firefox, but not one on an ESR Mozilla still supports', function () {
    $firefox = fn (int $version) => "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:{$version}.0) Gecko/20100101 Firefox/{$version}.0";
    $firefoxTls = 't13d1717h2_5b57614c22b0_3cbfd9057e0d';
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->fromClient($firefox(150), $firefoxTls)->create(['path' => '/an-old-firefox']);
    EdgeRequest::factory()->for($domain)->fromClient($firefox(153), $firefoxTls)->create(['path' => '/an-esr-firefox']);
    EdgeRequest::factory()->for($domain)->fromClient($firefox(158), $firefoxTls)->create(['path' => '/a-current-firefox']);
    Http::preventStrayRequests();
    Http::fake([FindCurrentFirefoxReleases::URL => Http::response(['LATEST_FIREFOX_VERSION' => '158.0', 'FIREFOX_ESR' => '153.5.0esr', 'FIREFOX_ESR115' => '115.43.0esr', 'FIREFOX_ESR_NEXT' => ''])]);
    app(FindCurrentFirefoxReleases::class)->refresh();

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('client', 'outdated')
        ->assertSee('/an-old-firefox')
        ->assertSee('Firefox 150 on Windows, 8 versions behind the current 158.')
        ->assertDontSee('/an-esr-firefox')
        ->assertDontSee('/a-current-firefox');
});

test('inspecting a request from a spoofed client explains what gave it away', function () use ($chromeOnWindows, $goTls) {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $request = EdgeRequest::factory()->for($domain)->fromClient($chromeOnWindows, $goTls)->create();

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->call('inspect', $request->id)
        ->assertSeeHtml('data-test="client-traits-detail"')
        ->assertSee("This client isn't the browser it claims to be")
        ->assertSee('spoofed/chrome-tls');
});

test('the chart splits the requests that passed into spoofed, outdated and clean, each counted once', function () use ($chromeOnIphone, $chromeTls) {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    EdgeRequest::factory()->for($domain)->genuineChrome(155)->count(FindCurrentChromeVersion::MIN_CLIENTS)
        ->sequence(fn (Sequence $sequence) => ['client_ip' => '198.51.100.'.($sequence->index + 1)])
        ->create();
    EdgeRequest::factory()->for($domain)->genuineChrome(140)->create(['client_ip' => '203.0.113.1']);
    EdgeRequest::factory()->for($domain)->fromClient($chromeOnIphone, $chromeTls)->create(['client_ip' => '203.0.113.2']);

    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')->call('loadCounts')
        ->assertSeeInOrder(['Clean', 'Outdated', 'Spoofed', 'Suspicious'])
        ->assertSeeHtml('wire:click="$set(\'client\', \'spoofed\')"');
    $buckets = collect($page->instance()->chartBuckets);

    expect($buckets->sum('humans'))->toBe(FindCurrentChromeVersion::MIN_CLIENTS + 2)
        ->and($buckets->sum('spoofed'))->toBe(1)
        ->and($buckets->sum('outdated'))->toBe(1)
        ->and($page->instance()->spoofedCount)->toBe(1);
});

test('missing or contradicting client hints give a client away, alike in SQL and in PHP', function () use ($chromeOnWindows, $chromeOnIphone, $chromeTls, $appleTls) {
    $domain = Domain::factory()->create();
    $releases = new BrowserReleases(155, 158, [153]);
    $windowsHints = '"Google Chrome";v="155", "Chromium";v="155" | "Windows"';
    $request = fn (string $userAgent, string $ja4, ?string $hints = null, array $attributes = []) => EdgeRequest::factory()->for($domain)->fromClient($userAgent, $ja4, $hints)->create($attributes);
    $traits = [ClientTrait::ChromeWithoutHints, ClientTrait::HintsPlatform];

    $cases = [
        'Chrome with no hints' => [$request($chromeOnWindows, $chromeTls), ClientTrait::ChromeWithoutHints],
        'hints naming another platform' => [$request($chromeOnWindows, $chromeTls, '"Chromium";v="155" | "macOS"'), ClientTrait::HintsPlatform],
        'an iPhone sending hints' => [$request($chromeOnIphone, $appleTls, '"Chromium";v="152" | "macOS"'), ClientTrait::HintsPlatform],
        'a genuine Chrome' => [$request($chromeOnWindows, $chromeTls, $windowsHints), null],
        'a CORS preflight' => [$request($chromeOnWindows, $chromeTls, null, ['method' => HttpMethod::Options]), null],
        'before the edge logged hints' => [$request($chromeOnWindows, $chromeTls, null, ['occurred_at' => '2026-10-01 12:00:00']), null],
        'Android asking for the desktop site' => [$request('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', $chromeTls, '"Chromium";v="155" | "Android"'), null],
        'Chrome older than hints' => [$request('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0.0.0 Safari/537.36', $chromeTls), null],
    ];

    foreach ($cases as $case => [$edgeRequest, $expected]) {
        $shown = array_values(array_filter(ClientProfile::forRequest($edgeRequest->fresh())->traits($releases), fn (ClientTrait $trait) => in_array($trait, $traits, true)));
        $found = ClientTrait::constrainAny(EdgeRequest::query()->whereKey($edgeRequest->id), $traits, $releases)->exists();

        expect($shown)->toBe($expected === null ? [] : [$expected], $case)
            ->and($found)->toBe($expected !== null, $case);
    }
});
