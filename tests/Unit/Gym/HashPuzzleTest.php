<?php

use App\Gym\HashPuzzle;

test('the first nonce for the shared test vector matches the browser solver', function () {
    // The browser core (gym.partials.runtime) and LN's PowSign both find nonce 45306 for this challenge at 18 bits.
    $challenge = 'deadbeefdeadbeefdeadbeefdeadbeef';

    expect(HashPuzzle::verify($challenge, '45306', 18))->toBeTrue()
        ->and(collect(range(0, 45305))->contains(fn (int $nonce) => HashPuzzle::verify($challenge, (string) $nonce, 18)))->toBeFalse();
});

test('leading zero bits are counted across bytes', function (string $digestHex, int $bits) {
    expect(HashPuzzle::leadingZeroBits(hex2bin($digestHex)))->toBe($bits);
})->with([
    ['ff00', 0],
    ['0800', 4],
    ['0001', 15],
    ['0000', 16],
]);

test('a nonce that is not alphanumeric never verifies', function () {
    expect(HashPuzzle::verify('deadbeefdeadbeefdeadbeefdeadbeef', '45306; drop', 0))->toBeFalse();
});
