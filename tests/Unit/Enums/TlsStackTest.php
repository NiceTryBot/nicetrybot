<?php

use App\Enums\TlsStack;

test('a JA4 fingerprint gives away the TLS stack behind it', function (?string $ja4, TlsStack $stack) {
    expect(TlsStack::fromJa4($ja4))->toBe($stack);
})->with([
    'Chrome' => ['t13d1517h2_8daaf6152771_cb7bf5808d99', TlsStack::Chromium],
    'Chrome resuming a session' => ['t13d1518h2_8daaf6152771_e2d80978ab2e', TlsStack::Chromium],
    'current Firefox, which offers the same ciphers' => ['t13d1517h2_8daaf6152771_3cbfd9057e0d', TlsStack::Chromium],
    'Safari' => ['t13d2013h2_a09f3c656075_7f0f34a4126d', TlsStack::Apple],
    'older Firefox' => ['t13d1715h2_5b57614c22b0_3d5424432f57', TlsStack::Firefox],
    'a Go client' => ['t13d131100_f57a46bbacb6_e7c285222651', TlsStack::Other],
    'HTTP/3' => ['q13d0314h3_55b375c5d22e_79cc91d6b50c', TlsStack::Quic],
    'no fingerprint' => [null, TlsStack::Unknown],
]);
