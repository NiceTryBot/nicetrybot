<?php

use App\Data\Ja4Fingerprint;

test('a browser fingerprint is decoded into its readable parts', function () {
    $fingerprint = Ja4Fingerprint::tryFrom('t13d1518h2_8daaf6152771_e2d80978ab2e');

    expect($fingerprint->prefix())->toBe('t13d1518h2')
        ->and($fingerprint->sniIsDomain)->toBeTrue()
        ->and($fingerprint->cipherCount)->toBe(15)
        ->and($fingerprint->extensionCount)->toBe(18)
        ->and($fingerprint->offersAlpn())->toBeTrue()
        ->and($fingerprint->cipherHash)->toBe('8daaf6152771')
        ->and($fingerprint->extensionHash)->toBe('e2d80978ab2e');
});

test('a client that offers no application protocol is recognised', function () {
    expect(Ja4Fingerprint::tryFrom('t13d131100_f57a46bbacb6_ab7e3b40a677')->offersAlpn())->toBeFalse();
});

test('fingerprints are normalised to lowercase without surrounding whitespace', function () {
    expect(Ja4Fingerprint::tryFrom('  T13D1518H2_8DAAF6152771_E2D80978AB2E ')->value)
        ->toBe('t13d1518h2_8daaf6152771_e2d80978ab2e');
});

test('values that are not JA4 fingerprints are rejected', function (?string $value) {
    expect(Ja4Fingerprint::tryFrom($value))->toBeNull();
})->with([
    'empty' => [''],
    'null' => [null],
    'a JA3 hash' => ['375c6162a492dfbf2795909110ce8424'],
    'an IP address' => ['203.0.113.7'],
    'a truncated JA4' => ['t13d1518h2_8daaf6152771'],
]);
