<?php

use App\Mcp\Tools\Traffic\DescribeTrafficFields;
use App\Mcp\Tools\Traffic\QueryTraffic;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Team;
use Illuminate\Testing\Fluent\AssertableJson;

/**
 * A Firefox user agent, with Firefox's TLS, and the iOS client hints only a spoofing client sends with it.
 */
const SPOOFING_FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:158.0) Gecko/20100101 Firefox/158.0';
const FIREFOX_TLS = 't13d1717h2_5b57614c22b0_3cbfd9057e0d';
const IOS_HINTS = '"Chromium";v="152", "Not?A_Brand";v="24", "Google Chrome";v="152" | "iOS"';

test('a read-only token is offered both traffic tools', function () {
    [$token] = apiToken();

    mcpAs($token)->tools()->assertRegistered([DescribeTrafficFields::class, QueryTraffic::class]);
});

test('describing the fields lists the team\'s hostnames, never another team\'s, with the grammar and examples', function () {
    [$token] = apiToken();
    Domain::factory()->for($token->team)->create(['hostname' => 'www.auctionhouse.example']);
    Domain::factory()->for(Team::factory())->create(['hostname' => 'someone-else.example']);

    mcpAs($token)->tool(DescribeTrafficFields::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('hostnames', ['www.auctionhouse.example'])
            ->where('hostname_total', 1)
            ->where('examples.0.where', "client = 'spoofed' AND category = 'probe/creds'")
            ->has('grammar')
            ->has('fields')
            ->has('periods', 15)
            ->etc())
        ->assertSee('client: \'spoofed\'')
        ->assertDontSee('someone-else.example');
});

test('counting a search totals only the team\'s matching requests in the period, and links to the same search', function () {
    [$token] = apiToken();
    $auctions = Domain::factory()->for($token->team)->create(['hostname' => 'auctions.auctionhouse.example']);
    $live = Domain::factory()->for($token->team)->create(['hostname' => 'live.auctionhouse.example']);
    $other = Domain::factory()->for($token->team)->create(['hostname' => 'shop.example']);
    EdgeRequest::factory()->for($auctions)->count(2)->create(['client_ip' => '203.0.113.1']);
    EdgeRequest::factory()->for($live)->create(['client_ip' => '203.0.113.2']);
    EdgeRequest::factory()->for($live)->create(['client_ip' => '203.0.113.3', 'occurred_at' => now()->subDays(3)]);
    EdgeRequest::factory()->for($other)->create(['client_ip' => '203.0.113.4']);
    EdgeRequest::factory()->for(Domain::factory()->for(Team::factory())->create(['hostname' => 'theirs.auctionhouse.example']))->count(5)->create();

    mcpAs($token)->tool(QueryTraffic::class, ['where' => "host LIKE '%auctionhouse%'"])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('totals', ['requests' => 3, 'unique_ips' => 2])
            ->where('period', '24h')
            ->where('activity_url', route('activity.index', ['current_team' => $token->team->slug, 'q' => "host LIKE '%auctionhouse%'"]))
            ->missing('rows')
            ->etc());
});

test('grouping by a field counts each value busiest first, with a search that narrows to it', function () {
    [$token] = apiToken();
    $auctions = Domain::factory()->for($token->team)->create(['hostname' => 'auctions.auctionhouse.example']);
    $live = Domain::factory()->for($token->team)->create(['hostname' => 'live.auctionhouse.example']);
    EdgeRequest::factory()->for($live)->count(3)->create(['client_ip' => '203.0.113.1']);
    EdgeRequest::factory()->for($live)->create(['client_ip' => '203.0.113.2']);
    EdgeRequest::factory()->for($auctions)->create(['client_ip' => '203.0.113.1']);
    EdgeRequest::factory()->for(Domain::factory()->for(Team::factory())->create(['hostname' => 'theirs.auctionhouse.example']))->count(9)->create();

    mcpAs($token)->tool(QueryTraffic::class, ['where' => "host LIKE '%auctionhouse%'", 'group_by' => 'host', 'period' => '48h'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('rows', [
                ['value' => 'live.auctionhouse.example', 'label' => 'live.auctionhouse.example', 'requests' => 4, 'unique_ips' => 2, 'search' => "host LIKE '%auctionhouse%'\nAND host = 'live.auctionhouse.example'"],
                ['value' => 'auctions.auctionhouse.example', 'label' => 'auctions.auctionhouse.example', 'requests' => 1, 'unique_ips' => 1, 'search' => "host LIKE '%auctionhouse%'\nAND host = 'auctions.auctionhouse.example'"],
            ])
            ->etc())
        ->assertDontSee('theirs.auctionhouse.example');
});

test('a grouping by category names each category as a search writes it', function () {
    [$token] = apiToken();
    $domain = Domain::factory()->for($token->team)->create();
    EdgeRequest::factory()->for($domain)->count(2)->create(['path' => '/.env']);
    EdgeRequest::factory()->for($domain)->create(['path' => '/about-us']);

    mcpAs($token)->tool(QueryTraffic::class, ['group_by' => 'category'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('rows.0.value', 'probe/creds')
            ->where('rows.0.requests', 2)
            ->where('rows.1.value', null)
            ->where('rows.1.search', 'category IS NULL')
            ->etc());
});

test('spoofed clients looking for a .env file are counted apart from honest ones', function () {
    [$token] = apiToken();
    $domain = Domain::factory()->for($token->team)->create();
    EdgeRequest::factory()->for($domain)->fromClient(SPOOFING_FIREFOX, FIREFOX_TLS, IOS_HINTS)->count(2)->create(['client_ip' => '203.0.113.1', 'path' => '/.env']);
    EdgeRequest::factory()->for($domain)->fromClient(SPOOFING_FIREFOX, FIREFOX_TLS, IOS_HINTS)->create(['client_ip' => '203.0.113.2', 'path' => '/']);
    EdgeRequest::factory()->for($domain)->fromClient(SPOOFING_FIREFOX, FIREFOX_TLS)->create(['client_ip' => '203.0.113.3', 'path' => '/.env']);
    EdgeRequest::factory()->for(Domain::factory()->for(Team::factory()))->fromClient(SPOOFING_FIREFOX, FIREFOX_TLS, IOS_HINTS)->create(['path' => '/.env']);

    mcpAs($token)->tool(QueryTraffic::class, ['where' => "client = 'spoofed' AND category = 'probe/creds'"])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('totals', ['requests' => 2, 'unique_ips' => 1])->etc());

    mcpAs($token)->tool(QueryTraffic::class, ['where' => "category = 'probe/creds'", 'group_by' => 'client'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('rows.0', ['value' => 'spoofed', 'label' => 'spoofed', 'requests' => 2, 'unique_ips' => 1, 'search' => "category = 'probe/creds'\nAND client = 'spoofed'"])
            ->where('rows.1.value', null)
            ->where('rows.1.unique_ips', 1)
            ->etc());
});

test('grouping by time counts every request in the period into buckets of the interval', function () {
    [$token] = apiToken();
    $domain = Domain::factory()->for($token->team)->create();
    EdgeRequest::factory()->for($domain)->count(3)->create(['occurred_at' => now()->subHours(2)]);
    EdgeRequest::factory()->for($domain)->create(['occurred_at' => now()->subDays(2)]);

    mcpAs($token)->tool(QueryTraffic::class, ['group_by' => 'time', 'period' => '7d', 'interval' => '1d'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('interval', '1d')
            ->where('totals.requests', 4)
            ->has('buckets', 7)
            ->where('buckets', fn ($buckets) => collect($buckets)->sum('requests') === 4)
            ->where('activity_url', route('activity.index', ['current_team' => $token->team->slug, 'period' => '7d', 'by' => '1d']))
            ->etc());
});

test('an interval the period can\'t be counted by comes back as an error naming the ones it can', function () {
    [$token] = apiToken();

    mcpAs($token)->tool(QueryTraffic::class, ['group_by' => 'time', 'period' => '30d', 'interval' => '1s'])
        ->assertHasErrors(['A 30d period can be counted by 6h, 1d, 1w, not 1s.']);
});

test('a where that doesn\'t parse comes back as an error saying where', function () {
    [$token] = apiToken();

    mcpAs($token)->tool(QueryTraffic::class, ['where' => "status = 404 AND (path LIKE '/wp-%'"])
        ->assertHasErrors(["The where doesn't parse:"])
        ->assertSee('(line 1, column');
});

test('a chart comes back as a PNG beside the counts', function (array $arguments) {
    [$token] = apiToken();
    $domain = Domain::factory()->for($token->team)->create();
    EdgeRequest::factory()->for($domain)->count(3)->create();

    mcpAs($token)->tool(QueryTraffic::class, [...$arguments, 'chart' => true])
        ->assertOk()
        ->assertSee('iVBORw0KGgo')
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('totals.requests', 3)->etc());
})->with([
    'bars' => [['group_by' => 'host']],
    'columns' => [['group_by' => 'time']],
    'totals' => [[]],
])->skip(! extension_loaded('gd'), 'GD is not installed.');
