<?php

use App\Enums\ClientBrowser;

test('a user agent claims the browser and major version it names', function (string $userAgent, ClientBrowser $browser, ?int $majorVersion) {
    $claimed = ClientBrowser::fromUserAgent($userAgent);

    expect($claimed)->toBe($browser)
        ->and($claimed->majorVersionIn($userAgent))->toBe($majorVersion);
})->with([
    'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', ClientBrowser::Chromium, 155],
    'Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36 Edg/155.0.0.0', ClientBrowser::Chromium, 155],
    'Chrome on iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/152.0.7977.53 Mobile/15E148 Safari/604.1', ClientBrowser::Chromium, 152],
    'Safari on iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.6.1 Mobile/15E148 Safari/604.1', ClientBrowser::Safari, 26],
    'Firefox' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:157.0) Gecko/20100101 Firefox/157.0', ClientBrowser::Firefox, 157],
    'Samsung Internet, whose Chrome version lags by design' => ['Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/28.0 Chrome/130.0.0.0 Mobile Safari/537.36', ClientBrowser::Chromium, null],
    'Googlebot' => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', ClientBrowser::Bot, null],
    'headless Chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/142.0.0.0 Safari/537.36', ClientBrowser::Bot, null],
    'an HTTP library' => ['python-requests/2.32.3', ClientBrowser::Bot, null],
    'a user agent claiming Chrome and Firefox at once' => ['Mozilla/5.0 (X11; Ubuntu; Linux i686; rv:24.0) Gecko/20100101 Firefox/24.0 Chrome/80.0.3987.132 Safari/537.36', ClientBrowser::Unknown, null],
]);
