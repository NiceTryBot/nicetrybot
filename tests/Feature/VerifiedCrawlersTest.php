<?php

use App\Enums\LabelKind;
use App\Enums\VerifiedCrawler;
use App\Models\CrawlerIpRange;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('refreshing replaces a crawler\'s ranges with the valid ones it publishes', function () {
    Http::preventStrayRequests();
    Http::fake(['https://openai.com/gptbot.json' => Http::response(['prefixes' => [
        ['ipv4Prefix' => '74.7.243.128/25'],
        ['ipv6Prefix' => '2a0b:4140::/32'],
        ['ipv4Prefix' => '74.7.243.128/25'],
        ['ipv4Prefix' => 'not-a-range'],
        ['ipv4Prefix' => '10.0.0.0/33'],
    ]])]);
    CrawlerIpRange::factory()->create(['cidr' => '192.0.2.0/24']);

    $this->artisan('crawlers:refresh')->assertSuccessful();

    expect(CrawlerIpRange::query()->where('crawler', VerifiedCrawler::OpenAiGptBot)->pluck('cidr')->sort()->values()->all())
        ->toBe(['2a0b:4140::/32', '74.7.243.128/25']);
});

test('refreshing keeps the ranges it has when the published list can\'t be read or is empty', function (array|int $response) {
    Http::preventStrayRequests();
    Http::fake(['https://openai.com/gptbot.json' => is_int($response) ? Http::response('', $response) : Http::response($response)]);
    CrawlerIpRange::factory()->create(['cidr' => '74.7.243.128/25']);

    $this->artisan('crawlers:refresh')->assertFailed();

    expect(CrawlerIpRange::query()->pluck('cidr')->all())->toBe(['74.7.243.128/25']);
})->with([
    'a server error' => [503],
    'an empty list' => [['prefixes' => []]],
]);

test('an IP inside a crawler\'s published ranges is named as that crawler', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    CrawlerIpRange::factory()->create(['cidr' => '74.7.243.128/25']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '74.7.243.240', 'path' => '/pricing']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '74.7.243.10', 'path' => '/outside']);
    $this->actingAs($user);

    $html = Livewire::test('pages::activity.index')
        ->assertSeeHtmlInOrder(['74.7.243.240', 'data-test="crawler-label"', 'OpenAI GPTBot'])
        ->html();

    expect(substr_count($html, 'data-test="crawler-label"'))->toBe(1);
});

test('a team\'s own name for a crawler\'s IP shows instead of the crawler', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    CrawlerIpRange::factory()->create(['cidr' => '74.7.243.128/25']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '74.7.243.240']);
    $user->currentTeam->clientLabels()->create(['kind' => LabelKind::Ip, 'value' => '74.7.243.240', 'name' => 'Their crawler']);
    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->assertSee('Their crawler')
        ->assertDontSeeHtml('data-test="crawler-label"');
});
