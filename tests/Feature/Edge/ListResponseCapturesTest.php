<?php

use App\Models\Domain;
use App\Models\Team;
use App\Support\CaptureFeed;
use App\Support\CaptureFeedContributor;
use App\Support\CaptureFeedHosts;
use Illuminate\Container\Container;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
});

test('the edge must send the shared token', function (?string $token) {
    $request = $token === null ? $this : $this->withToken($token);

    $request->getJson(route('edge.captures.index'))->assertUnauthorized();
})->with(['no token' => [null], 'wrong token' => ['not-the-token']]);

test('nothing is served when no token is configured', function () {
    config(['services.nicetry_edge.token' => null]);

    $this->withToken('')->getJson(route('edge.captures.index'))->assertUnauthorized();
});

test('an unchanged list costs the edge a 304', function () {
    $etag = $this->withToken('edge-token')->getJson(route('edge.captures.index'))->assertOk()->headers->get('ETag');

    expect($etag)->not->toBeEmpty();

    $this->withToken('edge-token')
        ->withHeader('If-None-Match', $etag)
        ->getJson(route('edge.captures.index'))
        ->assertStatus(304);
});

test('with nothing contributing, the edge still gets both lists, empty', function () {
    $container = new Container;
    app()->instance(CaptureFeed::class, new CaptureFeed($container));

    expect($this->withToken('edge-token')->getJson(route('edge.captures.index'))->assertOk()->getContent())
        ->toBe('{"captures":[],"tags":[]}');
});

test('contributors\' lists are joined in the order they were tagged, and the edge\'s keys come out sorted', function () {
    $team = Team::factory()->create();
    Domain::factory()->for($team)->verified()->create(['hostname' => 'sign.example']);
    $container = new Container;
    $container->instance('first', new class($team->id) implements CaptureFeedContributor
    {
        public function __construct(private int $teamId) {}

        public function contribute(CaptureFeedHosts $hosts): array
        {
            return ['tags' => [['id' => 1, 'hosts' => $hosts->for($this->teamId)]], 'banners' => [['id' => 7]]];
        }
    });
    $container->instance('second', new class implements CaptureFeedContributor
    {
        public function contribute(CaptureFeedHosts $hosts): array
        {
            return ['tags' => [['id' => 2, 'hosts' => []]]];
        }
    });
    $container->tag(['first', 'second'], CaptureFeed::TAG);

    expect((new CaptureFeed($container))->payload())->toBe([
        'banners' => [['id' => 7]],
        'captures' => [],
        'tags' => [['id' => 1, 'hosts' => ['sign-example.preview.example', 'sign.example']], ['id' => 2, 'hosts' => []]],
    ]);
});
