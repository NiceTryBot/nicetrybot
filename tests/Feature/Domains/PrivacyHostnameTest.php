<?php

use App\Models\Domain;
use App\Support\PrivacyHostname;

test('new domains get their own made-up stand-in', function () {
    $first = Domain::factory()->create();
    $second = Domain::factory()->create();

    expect($first->privacy_hostname)->toEndWith('.'.PrivacyHostname::SUFFIX)
        ->and($second->privacy_hostname)->toEndWith('.'.PrivacyHostname::SUFFIX)
        ->and($first->privacy_hostname)->not->toBe($second->privacy_hostname);
});
