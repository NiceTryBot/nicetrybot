<?php

use App\Addons\AddonSwitchboard;
use App\Models\AddonSwitch;
use App\Models\Domain;
use App\Models\Team;
use Illuminate\Support\Facades\Cache;
use NiceTry\Rewrites\Enums\RewriteContent;
use NiceTry\Rewrites\Models\ResponseRewrite;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
    Cache::flush();
});

test('the edge hears each enabled rewrite with the hostnames of the active domains it covers', function () {
    $team = Team::factory()->create();
    $shop = Domain::factory()->for($team)->verified()->create(['hostname' => 'shop.example']);
    Domain::factory()->for($team)->verified()->enforcing()->create(['hostname' => 'blog.example']);
    Domain::factory()->for($team)->create(['hostname' => 'pending.example']);
    Domain::factory()->verified()->create(['hostname' => 'someone-else.example']);

    $teamWide = ResponseRewrite::factory()->for($team)->create(['find' => 'foo', 'replace' => 'bar']);
    $onShop = ResponseRewrite::factory()->for($team)->create([
        'domain_id' => $shop->id,
        'find' => 'Old Co',
        'replace' => '',
        'ignore_case' => true,
        'content' => RewriteContent::Text,
        'in_headers' => true,
    ]);
    ResponseRewrite::factory()->for($team)->disabled()->create();

    $this->getJson(route('edge.rewrites'))->assertUnauthorized();

    $response = $this->withToken('edge-token')->getJson(route('edge.rewrites'))->assertOk();

    expect($response->json('rewrites'))->toBe([
        ['id' => $teamWide->id, 'hosts' => ['blog-example.preview.example', 'blog.example', 'shop-example.preview.example', 'shop.example'], 'find' => 'foo', 'replace' => 'bar', 'ignore_case' => false, 'in' => 'html', 'headers' => false],
        ['id' => $onShop->id, 'hosts' => ['shop-example.preview.example', 'shop.example'], 'find' => 'Old Co', 'replace' => '', 'ignore_case' => true, 'in' => 'text', 'headers' => true],
    ]);
});

test('a change reaches the edge on its next poll', function () {
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->create(['hostname' => 'shop.example']);
    $rewrite = ResponseRewrite::factory()->for($team)->create(['find' => 'foo', 'replace' => 'bar']);

    $this->withToken('edge-token')->getJson(route('edge.rewrites'))->assertJsonPath('rewrites.0.replace', 'bar');

    $rewrite->update(['replace' => 'baz']);

    $this->withToken('edge-token')->getJson(route('edge.rewrites'))->assertJsonPath('rewrites.0.replace', 'baz');
});

test('the edge hears an empty list when the add-on is off, so responses pass untouched', function () {
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->create(['hostname' => 'shop.example']);
    ResponseRewrite::factory()->for($team)->create();

    AddonSwitch::factory()->create(['slug' => 'rewrites']);
    app(AddonSwitchboard::class)->forget();

    $this->withToken('edge-token')->getJson(route('edge.rewrites'))->assertOk()->assertExactJson(['rewrites' => []]);
});
