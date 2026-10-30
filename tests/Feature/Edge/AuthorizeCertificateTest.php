<?php

use App\Models\Domain;

test('a verified domain is allowed a certificate', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);

    askForCertificate('claims.example.com')
        ->assertOk();
});

test('a paused domain is still allowed a certificate so it can renew', function () {
    Domain::factory()->paused()->create(['hostname' => 'claims.example.com']);

    askForCertificate('claims.example.com')
        ->assertOk();
});

test('the hostname is matched case-insensitively', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);

    askForCertificate('Claims.Example.COM')
        ->assertOk();
});

test('a domain awaiting verification is refused a certificate', function () {
    Domain::factory()->create(['hostname' => 'claims.example.com']);

    askForCertificate('claims.example.com')
        ->assertNotFound();
});

test('an unknown hostname is refused a certificate', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);

    askForCertificate('other.example.com')
        ->assertNotFound();
});

test('a request without a hostname is refused', function () {
    askForCertificate()
        ->assertNotFound();
});

test('answering does not start a session', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);

    askForCertificate('claims.example.com')
        ->assertCookieMissing(config('session.cookie'));
});

test('only the edge may ask: without its token, a verified domain gets the same 404 as an unknown one', function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);

    $this->get(route('edge.certificates.authorize', ['domain' => 'claims.example.com']))->assertNotFound();
    $this->get(route('edge.certificates.authorize', ['domain' => 'claims.example.com', 'token' => 'wrong']))->assertNotFound();
    $this->withToken('edge-token')->get(route('edge.certificates.authorize', ['domain' => 'claims.example.com']))->assertOk();

    config(['services.nicetry_edge.token' => '']);
    $this->get(route('edge.certificates.authorize', ['domain' => 'claims.example.com', 'token' => '']))->assertNotFound();
});
