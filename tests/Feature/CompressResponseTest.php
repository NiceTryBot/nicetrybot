<?php

use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('_test/large', fn () => response(str_repeat('<tr><td>a row</td></tr>', 2000)));
        Route::get('_test/small', fn () => response('<p>small</p>'));
        Route::get('_test/download', fn () => response()->streamDownload(fn () => print (str_repeat('a,b'.PHP_EOL, 5000)), 'rows.csv'));
    });
    Route::get('_test/outside-web', fn () => response(str_repeat('<tr><td>a row</td></tr>', 2000)));
});

test('a large page is gzipped for a browser that takes it, and marked for Vapor to send as base64', function () {
    $response = $this->get('_test/large', ['Accept-Encoding' => 'gzip, deflate, br']);

    $response->assertOk()
        ->assertHeader('Content-Encoding', 'gzip')
        ->assertHeader('X-Vapor-Base64-Encode', 'True');

    expect($response->headers->get('Vary'))->toContain('Accept-Encoding')
        ->and(gzdecode($response->getContent()))->toBe(str_repeat('<tr><td>a row</td></tr>', 2000));
});

test('a page goes uncompressed when it is small or the browser does not take gzip', function (string $uri, array $headers) {
    $this->get($uri, $headers)
        ->assertOk()
        ->assertHeaderMissing('Content-Encoding')
        ->assertHeaderMissing('X-Vapor-Base64-Encode');
})->with([
    'small page' => ['_test/small', ['Accept-Encoding' => 'gzip']],
    'no gzip' => ['_test/large', ['Accept-Encoding' => 'identity']],
    'outside the web routes' => ['_test/outside-web', ['Accept-Encoding' => 'gzip']],
]);

test('a streamed download is left alone', function () {
    $this->get('_test/download', ['Accept-Encoding' => 'gzip'])
        ->assertOk()
        ->assertHeaderMissing('Content-Encoding');
});

test('a gzipped Livewire page still carries the Livewire scripts', function () {
    $user = User::factory()->create();
    EdgeRequest::factory()->for(Domain::factory()->for($user->currentTeam))->count(30)->create();

    $response = $this->actingAs($user)->get(route('activity.index'), ['Accept-Encoding' => 'gzip']);

    $response->assertOk()->assertHeader('Content-Encoding', 'gzip');

    expect(gzdecode($response->getContent()))->toContain('data-update-uri')->toContain('</html>');
});
