<?php

use App\Models\User;

test('responses say how long they took and how much of it was the database', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertHeader('Server-Timing')
        ->tap(fn ($response) => expect($response->headers->get('Server-Timing'))->toMatch('/^db;dur=[\d.]+;desc="[1-9]\d* queries", app;dur=[\d.]+$/'));
});
