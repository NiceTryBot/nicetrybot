<?php

use App\Actions\Edge\ImportEdgeLogFile;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;
use App\Support\PreviewHostname;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('a domain that serves www. is allowed a certificate for www. too', function () {
    Domain::factory()->verified()->servingWww()->create(['hostname' => 'example.com']);
    Domain::factory()->verified()->create(['hostname' => 'plain.com']);

    $this->get(route('edge.certificates.authorize', ['domain' => 'www.example.com']))->assertOk();
    $this->get(route('edge.certificates.authorize', ['domain' => 'www.plain.com']))->assertNotFound();
});

test('the edge hears www.site with site\'s route when site serves www.', function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
    Domain::factory()->verified()->servingWww()->create(['hostname' => 'example.com']);
    Domain::factory()->verified()->create(['hostname' => 'plain.com']);

    $routes = collect($this->withToken('edge-token')->getJson(route('edge.origins'))->assertOk()->json('origins'))->keyBy('host');

    expect($routes->keys()->reject(PreviewHostname::isPreview(...))->values()->all())->toBe(['example.com', 'plain.com', 'www.example.com'])
        ->and(collect($routes['www.example.com'])->except(['host', 'site_host', 'host_header'])->all())->toBe(collect($routes['example.com'])->except(['host', 'site_host', 'host_header'])->all())
        ->and($routes['www.example.com'])->site_host->toBe('www.example.com')->host_header->toBe('www.example.com');
});

test('www.site traffic is counted under site when site serves www.', function () {
    Storage::fake('edge-logs');
    $site = Domain::factory()->verified()->servingWww()->create(['hostname' => 'example.com']);
    Domain::factory()->verified()->create(['hostname' => 'plain.com']);

    $line = fn (string $host) => json_encode(['occurred_at' => '2026-10-17T23:00:00.000Z', 'host' => $host, 'client_ip' => '203.0.113.7', 'method' => 'GET', 'path' => '/', 'status' => 200, 'action' => 'passed']);
    Storage::disk('edge-logs')->put('raw/www.ndjson.gz', gzencode($line('www.example.com')."\n".$line('www.plain.com')));

    $file = app(ImportEdgeLogFile::class)->handle('raw/www.ndjson.gz');

    expect(EdgeRequest::sole()->domain_id)->toBe($site->id)
        ->and($file->rows_skipped)->toBe(1);
});

test('a domain added with www. on serves www., unless it is a www. hostname itself', function () {
    $this->actingAs(User::factory()->create());

    foreach (['example.com', 'www.other.com'] as $hostname) {
        Livewire::test('add-domain-modal')
            ->set('hostname', $hostname)
            ->set('originUrl', 'https://app.example.net')
            ->call('addDomain')
            ->assertHasNoErrors();
    }

    expect(Domain::where('hostname', 'example.com')->sole()->serves_www)->toBeTrue()
        ->and(Domain::where('hostname', 'www.other.com')->sole()->serves_www)->toBeFalse();
});
