<?php

use App\Models\Domain;

test('a verified domain is allowed a certificate', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);

    $this->get(route('edge.certificates.authorize', ['domain' => 'claims.example.com']))
        ->assertOk();
});

test('a paused domain is still allowed a certificate so it can renew', function () {
    Domain::factory()->paused()->create(['hostname' => 'claims.example.com']);

    $this->get(route('edge.certificates.authorize', ['domain' => 'claims.example.com']))
        ->assertOk();
});

test('the hostname is matched case-insensitively', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);

    $this->get(route('edge.certificates.authorize', ['domain' => 'Claims.Example.COM']))
        ->assertOk();
});

test('a domain awaiting verification is refused a certificate', function () {
    Domain::factory()->create(['hostname' => 'claims.example.com']);

    $this->get(route('edge.certificates.authorize', ['domain' => 'claims.example.com']))
        ->assertNotFound();
});

test('an unknown hostname is refused a certificate', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);

    $this->get(route('edge.certificates.authorize', ['domain' => 'other.example.com']))
        ->assertNotFound();
});

test('a request without a hostname is refused', function () {
    $this->get(route('edge.certificates.authorize'))
        ->assertNotFound();
});

test('answering does not start a session', function () {
    Domain::factory()->verified()->create(['hostname' => 'claims.example.com']);

    $this->get(route('edge.certificates.authorize', ['domain' => 'claims.example.com']))
        ->assertCookieMissing(config('session.cookie'));
});
