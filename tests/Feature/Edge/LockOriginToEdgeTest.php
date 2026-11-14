<?php

use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Route::get('_test/panel', fn (Request $request) => ['ip' => $request->ip(), 'root' => url('/')]);

    config(['nicetry.edge_only_hosts' => 'app.example.com, demo.example.com', 'services.nicetry_edge.token' => 'edge-token']);

    $this->panel = Domain::factory()->create(['hostname' => 'app.example.com', 'origin_secret' => 'panel-secret']);
});

afterEach(fn () => putenv('LAMBDA_TASK_ROOT'));

/**
 * Ask the test route on the origin's own hostname, as the edge (or anyone who found the origin) would.
 *
 * @param  array<string, string>  $headers
 */
function askOrigin(array $headers = []): TestResponse
{
    return test()->withHeaders($headers)->getJson('https://app-origin.example.com/_test/panel');
}

test('the panel answers the edge with the domain\'s origin secret, on the visitor\'s hostname and IP', function () {
    putenv('LAMBDA_TASK_ROOT=/var/task');

    askOrigin([
        'X-Forwarded-Host' => 'app.example.com',
        'X-NiceTry-Origin-Secret' => 'panel-secret',
        'X-Forwarded-For' => '203.0.113.7, 198.51.100.50',
        'X-Vapor-Source-Ip' => '198.51.100.50',
    ])->assertOk()->assertExactJson(['ip' => '203.0.113.7', 'root' => 'https://app.example.com']);
});

test('without the origin secret the panel is a 404, on any hostname', function (array $headers) {
    askOrigin($headers)->assertNotFound();
})->with([
    'the origin\'s own hostname' => [[]],
    'a forged panel Host' => [['X-Forwarded-Host' => 'app.example.com']],
    'a wrong secret' => [['X-Forwarded-Host' => 'app.example.com', 'X-NiceTry-Origin-Secret' => 'guess']],
]);

test('another domain\'s origin secret doesn\'t open the panel', function () {
    Domain::factory()->create(['hostname' => 'shop.example.com', 'origin_secret' => 'shop-secret']);

    askOrigin(['X-Forwarded-Host' => 'shop.example.com', 'X-NiceTry-Origin-Secret' => 'shop-secret'])->assertNotFound();
    askOrigin(['X-Forwarded-Host' => 'app.example.com', 'X-NiceTry-Origin-Secret' => 'shop-secret'])->assertNotFound();
});

test('a rotated secret works at once, and the old one stops', function () {
    $through = fn (string $secret) => askOrigin(['X-Forwarded-Host' => 'app.example.com', 'X-NiceTry-Origin-Secret' => $secret]);

    $through('panel-secret')->assertOk();

    $this->panel->update(['origin_secret' => 'rotated-secret']);

    $through('rotated-secret')->assertOk();
    $through('panel-secret')->assertNotFound();
});

test('the edge\'s own calls pass with its token', function () {
    askOrigin(['Authorization' => 'Bearer edge-token'])->assertOk();
});

test('with no hostnames set the panel answers directly, as a self-hosted one does', function () {
    config(['nicetry.edge_only_hosts' => '']);

    askOrigin()->assertOk()->assertJsonPath('root', 'https://app-origin.example.com');
});
