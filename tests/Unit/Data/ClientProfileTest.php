<?php

use App\Data\BrowserReleases;
use App\Data\ClientProfile;
use App\Enums\ClientTrait;

$chromeOnIphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/152.0.7977.53 Mobile/15E148 Safari/604.1';
$chromeOnWindows = fn (int $version) => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{$version}.0.0.0 Safari/537.36";
$chromeOnAndroid = fn (int $version) => "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{$version}.0.0.0 Mobile Safari/537.36";
$firefoxOnWindows = fn (int $version) => "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:{$version}.0) Gecko/20100101 Firefox/{$version}.0";
$firefoxOnAndroid = fn (int $version) => "Mozilla/5.0 (Android 14; Mobile; rv:{$version}.0) Gecko/{$version}.0 Firefox/{$version}.0";
$chromeTls = 't13d1517h2_8daaf6152771_cb7bf5808d99';
$firefoxTls = 't13d1717h2_5b57614c22b0_3cbfd9057e0d';
$appleTls = 't13d2013h2_a09f3c656075_7f0f34a4126d';
$iosHints = '"Chromium";v="152", "Not?A_Brand";v="24", "Google Chrome";v="152" | "iOS"';

test('a client shows the traits its parts give away', function (string $userAgent, ?string $ja4, ?string $clientHints, ?int $asn, array $traits) {
    expect(ClientProfile::of($userAgent, $ja4, $clientHints, $asn)->traits(new BrowserReleases(chrome: 155, firefox: 158, firefoxEsr: [153, 115])))->toBe($traits);
})->with([
    'an iPhone on Chrome\'s TLS' => [$chromeOnIphone, $chromeTls, null, 7922, [ClientTrait::IosOnChromeTls]],
    'an iPhone on Chrome\'s TLS whose client hints claim iOS' => [$chromeOnIphone, $chromeTls, $iosHints, 7922, [ClientTrait::IosOnChromeTls, ClientTrait::IosClientHints]],
    'a real iPhone' => [$chromeOnIphone, $appleTls, null, 7922, []],
    'an iPhone behind Zscaler' => [$chromeOnIphone, $chromeTls, null, 22616, []],
    'Chrome on a Go TLS stack' => [$chromeOnWindows(155), 't13d131100_f57a46bbacb6_e7c285222651', null, 14061, [ClientTrait::ChromeOnOtherTls]],
    'Chrome on Apple\'s TLS' => [$chromeOnWindows(155), $appleTls, null, 7922, [ClientTrait::ChromeOnOtherTls]],
    'Chrome behind iboss' => [$chromeOnWindows(155), 't13d311200_e8f1e7e78f70_23269edf5b19', null, 396253, []],
    'Chrome over HTTP/3' => [$chromeOnWindows(155), 'q13d0314h3_55b375c5d22e_79cc91d6b50c', null, 7922, []],
    'Android Chrome 3 versions behind' => [$chromeOnAndroid(152), $chromeTls, null, 7922, [ClientTrait::Outdated]],
    'Windows Chrome 3 versions behind' => [$chromeOnWindows(152), $chromeTls, null, 7922, []],
    'Windows Chrome 9 versions behind' => [$chromeOnWindows(146), $chromeTls, null, 6167, [ClientTrait::Outdated]],
    'Windows Firefox 2 versions behind' => [$firefoxOnWindows(156), $firefoxTls, null, 7922, []],
    'Windows Firefox 8 versions behind' => [$firefoxOnWindows(150), $firefoxTls, null, 7922, [ClientTrait::Outdated]],
    'Android Firefox 3 versions behind' => [$firefoxOnAndroid(155), $firefoxTls, null, 7922, [ClientTrait::Outdated]],
    'Firefox on the current ESR' => [$firefoxOnWindows(153), $firefoxTls, null, 7922, []],
    'Tor Browser on the older ESR' => ['Mozilla/5.0 (Windows NT 10.0; rv:115.0) Gecko/20100101 Firefox/115.0', $firefoxTls, null, 60729, []],
    'Firefox on an ESR Mozilla no longer supports' => [$firefoxOnWindows(128), $firefoxTls, null, 7922, [ClientTrait::Outdated]],
    'Googlebot on Google\'s own TLS' => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 't13d1812h1_85036bcba153_7f4a2cbb9e1d', null, 15169, []],
]);

test('no client is outdated while the current version of its browser is unknown', function () use ($chromeOnWindows, $chromeTls, $firefoxOnWindows, $firefoxTls) {
    expect(ClientProfile::of($chromeOnWindows(146), $chromeTls, null, 7922)->traits(new BrowserReleases(firefox: 158)))->toBe([])
        ->and(ClientProfile::of($firefoxOnWindows(150), $firefoxTls, null, 7922)->traits(new BrowserReleases(chrome: 155)))->toBe([]);
});
