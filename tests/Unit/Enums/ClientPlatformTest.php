<?php

use App\Enums\ClientPlatform;

test('a user agent claims the platform it names', function (string $userAgent, ClientPlatform $platform) {
    expect(ClientPlatform::fromUserAgent($userAgent))->toBe($platform);
})->with([
    'iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/152.0.7977.53 Mobile/15E148 Safari/604.1', ClientPlatform::Ios],
    'iPad' => ['Mozilla/5.0 (iPad; CPU OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.6.1 Mobile/15E148 Safari/604.1', ClientPlatform::Ios],
    'Android' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Mobile Safari/537.36', ClientPlatform::Android],
    'ChromeOS' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', ClientPlatform::ChromeOs],
    'Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', ClientPlatform::Windows],
    'Mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.6.1 Safari/605.1.15', ClientPlatform::Mac],
    'Linux' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', ClientPlatform::Linux],
    'a script' => ['python-requests/2.32.3', ClientPlatform::Unknown],
]);

test('client hints claim the platform after the bar', function (string $clientHints, ClientPlatform $platform) {
    expect(ClientPlatform::fromClientHints($clientHints))->toBe($platform);
})->with([
    'iOS' => ['"Chromium";v="152", "Not?A_Brand";v="24", "Google Chrome";v="152" | "iOS"', ClientPlatform::Ios],
    'Android' => ['"Chromium";v="152", "Not?A_Brand";v="24", "Google Chrome";v="152" | "Android"', ClientPlatform::Android],
    'macOS' => ['"Google Chrome";v="155", "Chromium";v="155", "Not(A:Brand";v="24" | "macOS"', ClientPlatform::Mac],
    'Chrome OS' => ['"Google Chrome";v="151", "Chromium";v="151" | "Chrome OS"', ClientPlatform::ChromeOs],
    'no platform sent' => ['"Chromium";v="142", "HeadlessChrome";v="142"', ClientPlatform::Unknown],
]);
