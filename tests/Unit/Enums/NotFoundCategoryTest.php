<?php

use App\Enums\NotFoundCategory;

test('404 paths are classified by what most likely requested them', function (string $path, NotFoundCategory $category) {
    expect(NotFoundCategory::classify($path))->toBe($category);
})->with([
    'dotfile' => ['/.env', NotFoundCategory::Probe],
    'nested dotfile' => ['/dashboard/.env', NotFoundCategory::Probe],
    'git config' => ['/.git/config', NotFoundCategory::Probe],
    'wordpress installer' => ['/wp-admin/install.php', NotFoundCategory::Probe],
    'ignition exploit' => ['/_ignition/execute-solution', NotFoundCategory::Probe],
    'php script' => ['/shell.php?cmd=id', NotFoundCategory::Probe],
    'apple touch icon' => ['/apple-touch-icon-precomposed.png', NotFoundCategory::BrowserAsset],
    'favicon' => ['/favicon.ico', NotFoundCategory::BrowserAsset],
    'well-known' => ['/.well-known/security.txt', NotFoundCategory::BrowserAsset],
    'old link' => ['/old-notice', NotFoundCategory::MissingPage],
    'login page' => ['/login', NotFoundCategory::MissingPage],
]);
