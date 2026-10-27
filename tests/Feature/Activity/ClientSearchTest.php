<?php

use App\Data\RuleScript;
use App\Enums\RuleSubject;
use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

const CLIENT_SEARCH_CHROME_JA4 = 't13d1517h2_8daaf6152771_cb7bf5808d99';
const CLIENT_SEARCH_FIREFOX_JA4 = 't13d1717h2_5b57614c22b0_3cbfd9057e0d';

/**
 * Visits to a claims site from four clients, so grouping changes who "had a thank-you visit but never
 * visited the homepage":
 *
 *     198.51.100.1 on Chrome's TLS   /a/thank-you         by IP and fingerprint: yes
 *     198.51.100.1 on Firefox's TLS  /                    by IP: no (the IP saw the homepage)
 *     198.51.100.2 on Chrome's TLS   /                    by fingerprint: no (Chrome's TLS saw it)
 *     198.51.100.3 on Firefox's TLS  /c/thank-you         by IP and fingerprint, by IP: yes
 */
function thankYouVisits(User $user): Domain
{
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'settlement.example']);

    EdgeRequest::factory()->for($domain)->fromClient('Mozilla/5.0 Chrome/155.0.0.0', CLIENT_SEARCH_CHROME_JA4)->count(3)->create(['client_ip' => '198.51.100.1', 'path' => '/a/thank-you']);
    EdgeRequest::factory()->for($domain)->fromClient('Mozilla/5.0 Firefox/158.0', CLIENT_SEARCH_FIREFOX_JA4)->create(['client_ip' => '198.51.100.1', 'path' => '/']);
    EdgeRequest::factory()->for($domain)->fromClient('Mozilla/5.0 Chrome/155.0.0.0', CLIENT_SEARCH_CHROME_JA4)->create(['client_ip' => '198.51.100.2', 'path' => '/']);
    EdgeRequest::factory()->for($domain)->fromClient('Mozilla/5.0 Firefox/158.0', CLIENT_SEARCH_FIREFOX_JA4)->count(2)->create(['client_ip' => '198.51.100.3', 'path' => '/c/thank-you']);

    return $domain;
}

function thankYouWithoutHomepage(string $groupBy = ''): string
{
    return "host = 'settlement.example'"
        .($groupBy === '' ? '' : "\nGROUP BY {$groupBy}")
        ."\nHAVING COUNT(*) FILTER (WHERE path LIKE '%/thank-you') >= 1\n   AND COUNT(*) FILTER (WHERE path = '/') = 0";
}

test('HAVING keeps the requests of the clients that did one thing and never another', function (string $groupBy, array $shown, array $hidden) {
    $user = User::factory()->create();
    thankYouVisits($user);

    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')->set('search', thankYouWithoutHomepage($groupBy));

    foreach ($shown as $path) {
        $page->assertSee($path);
    }

    foreach ($hidden as $path) {
        $page->assertDontSee($path);
    }
})->with([
    'a client is its IP and fingerprint together' => ['', ['/a/thank-you', '/c/thank-you'], []],
    'grouped by IP' => ['ip', ['/c/thank-you'], ['/a/thank-you']],
    'grouped by fingerprint' => ['ja4', [], ['/a/thank-you', '/c/thank-you']],
]);

test('hiding GET / from the list still counts homepage visits for HAVING', function () {
    $user = User::factory()->create();
    thankYouVisits($user);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('search', thankYouWithoutHomepage('ip'))
        ->call('toggleHide', 'home')
        ->assertSee('/c/thank-you')
        ->assertDontSee('/a/thank-you');
});

test('the clients view lists each client once, busiest first, as the search groups them', function (string $groupBy, string $tab, int $count, array $busiestFirst) {
    $user = User::factory()->create();
    thankYouVisits($user);

    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')
        ->set('view', 'clients')
        ->set('search', "host = 'settlement.example'".($groupBy === '' ? '' : " GROUP BY {$groupBy}"))
        ->assertSeeText($tab)
        ->assertSeeInOrder($busiestFirst);

    expect(substr_count($page->html(), 'data-test="client-row"'))->toBe($count);
})->with([
    'by IP and fingerprint' => ['', 'Clients (4)', 4, ['198.51.100.1', '198.51.100.3', '198.51.100.1']],
    'by IP' => ['ip', 'IPs (3)', 3, ['198.51.100.1', '198.51.100.3', '198.51.100.2']],
    'by fingerprint' => ['ja4', 'Fingerprints (2)', 2, [CLIENT_SEARCH_CHROME_JA4, CLIENT_SEARCH_FIREFOX_JA4]],
]);

test('picking a client lists its requests, keeping what the search asked', function () {
    $user = User::factory()->create();
    thankYouVisits($user);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('view', 'clients')
        ->set('search', thankYouWithoutHomepage())
        ->call('showClient', '198.51.100.1', CLIENT_SEARCH_CHROME_JA4)
        ->assertSet('view', 'requests')
        ->assertSet('search', "host = 'settlement.example'\nAND ip = '198.51.100.1'\nAND ja4 = '".CLIENT_SEARCH_CHROME_JA4."'\nHAVING COUNT(*) FILTER (WHERE path LIKE '%/thank-you') >= 1\n   AND COUNT(*) FILTER (WHERE path = '/') = 0")
        ->assertSee('/a/thank-you')
        ->assertDontSee('/c/thank-you');
});

test('a search becomes a rule draft that tags the clients it finds, without the noise the list hides', function () {
    $user = User::factory()->create();
    thankYouVisits($user);

    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index')
        ->set('search', thankYouWithoutHomepage('ip'))
        ->call('toggleHide', 'assets')
        ->set('question', 'thank-you without home')
        ->assertSeeHtml('data-test="create-rule"');

    parse_str((string) parse_url($page->instance()->ruleDraftUrl, PHP_URL_QUERY), $query);

    expect($query['draft'])->toBe(implode("\n", [
        '-- From the Activity search “thank-you without home”.',
        "TAG ip AS 'thank-you-without-home' FOR 1 DAY",
        "WHERE host = 'settlement.example'",
        'WITHIN 24 HOURS',
        "HAVING COUNT(*) FILTER (WHERE path LIKE '%/thank-you') >= 1\n   AND COUNT(*) FILTER (WHERE path = '/') = 0",
    ]))
        ->and($query['name'])->toBe('thank-you without home')
        ->and(RuleScript::parse($query['draft'])->subjects)->toBe([RuleSubject::Ip]);
});

test('members who can\'t write rules aren\'t offered one', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $this->actingAs($member);

    $page = Livewire::test('pages::activity.index')
        ->set('search', "status = 404\nGROUP BY ip\nHAVING COUNT(*) > 10")
        ->assertDontSeeHtml('data-test="create-rule"');

    expect($page->instance()->ruleDraftUrl)->toBeNull();
});

test('both tabs count what they list, whichever one is open', function (string $view) {
    $user = User::factory()->create();
    thankYouVisits($user);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->set('view', $view)
        ->set('search', "host = 'settlement.example' GROUP BY ip")
        ->call('loadCounts')
        ->assertSeeText('Requests (7)')
        ->assertSeeText('IPs (3)');
})->with(['requests', 'clients']);
