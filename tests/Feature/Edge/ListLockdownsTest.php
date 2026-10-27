<?php

use App\Support\LockdownFeed;
use App\Support\LockdownFeedContributor;
use Illuminate\Container\Container;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
});

test('the edge must send the shared token', function (?string $token) {
    $request = $token === null ? $this : $this->withToken($token);

    $request->getJson(route('edge.lockdowns'))->assertUnauthorized();
})->with(['no token' => [null], 'wrong token' => ['not-the-token']]);

test('with nothing contributing, the edge hears that nothing is locked, and an unchanged answer costs it a 304', function () {
    app()->instance(LockdownFeed::class, new LockdownFeed(new Container));

    $response = $this->withToken('edge-token')->getJson(route('edge.lockdowns'))->assertOk();

    expect($response->getContent())->toBe('{"lockdowns":[]}')
        ->and($response->headers->get('ETag'))->toBe('"9af6c96dc616b1d5672e64e893caf1ab"');

    $this->withToken('edge-token')
        ->withHeader('If-None-Match', '"9af6c96dc616b1d5672e64e893caf1ab"')
        ->getJson(route('edge.lockdowns'))
        ->assertStatus(304);
});

test('contributors\' lockdowns are joined in the order they were tagged, each told which edge asked', function () {
    $container = new Container;
    $container->instance('first', new class implements LockdownFeedContributor
    {
        public function lockdowns(string $edge): array
        {
            return [3 => ['team' => 1, 'hosts' => ['a.example'], 'allow' => [], 'secret' => 's', 'html' => $edge, 'retry_after' => 60]];
        }
    });
    $container->instance('second', new class implements LockdownFeedContributor
    {
        public function lockdowns(string $edge): array
        {
            return [['team' => 2, 'hosts' => ['b.example'], 'allow' => ['203.0.113.4'], 'secret' => 't', 'html' => $edge, 'retry_after' => 60]];
        }
    });
    $container->tag(['first', 'second'], LockdownFeed::TAG);
    app()->instance(LockdownFeed::class, new LockdownFeed($container));

    $this->withToken('edge-token')
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->getJson(route('edge.lockdowns'))
        ->assertOk()
        ->assertExactJson(['lockdowns' => [
            ['team' => 1, 'hosts' => ['a.example'], 'allow' => [], 'secret' => 's', 'html' => '198.51.100.9', 'retry_after' => 60],
            ['team' => 2, 'hosts' => ['b.example'], 'allow' => ['203.0.113.4'], 'secret' => 't', 'html' => '198.51.100.9', 'retry_after' => 60],
        ]]);

    expect((new LockdownFeed($container))->lockdowns('edge'))->toHaveCount(2)->toBeList();
});
