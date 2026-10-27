<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * An app folder for addons:install to work on: a composer.json that requires $required, the add-ons
 * that come with it in packages/, and a vendor/ installed with or without dev packages.
 *
 * @param  list<string>  $required
 */
function addonsApp(array $required = [], bool $dev = false): string
{
    $folder = sys_get_temp_dir().'/nicetry-addons-'.getmypid();

    File::deleteDirectory($folder);
    File::ensureDirectoryExists("$folder/packages/lockdown");
    File::ensureDirectoryExists("$folder/vendor/composer");

    File::put("$folder/packages/lockdown/composer.json", json_encode(['name' => 'nicetry/lockdown']));
    File::put("$folder/composer.json", json_encode(['require' => ['php' => '^8.5', 'nicetry/lockdown' => '^1.0', ...array_fill_keys($required, '^1.0')]]));
    File::put("$folder/vendor/composer/installed.json", json_encode(['dev' => $dev, 'packages' => [
        ['name' => 'nicetry/captchas', 'pretty_version' => '1.2.0'],
    ]]));

    return $folder;
}

beforeEach(function () {
    // Every Composer command run, without the `composer` in front; $this->failing names one that fails.
    $this->runs = [];
    $this->failing = null;

    Process::fake(function (PendingProcess $process) {
        $this->runs[] = array_slice((array) $process->command, 1);

        return Process::result(exitCode: ($process->command[1] ?? null) === $this->failing ? 2 : 0);
    });

    config([
        'addons.install.packages' => '',
        'addons.install.repository' => 'https://nicetrybot.com/composer',
        'addons.install.token' => 'ntbc_secret-token',
    ]);
});

afterEach(function () {
    File::deleteDirectory(sys_get_temp_dir().'/nicetry-addons-'.getmypid());
});

it('does nothing when no add-ons are listed', function () {
    $this->artisan('addons:install', ['--working-dir' => addonsApp()])
        ->expectsOutputToContain('No add-ons to install')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('adds the marketplace for nicetry/* only, keeps nicetry/* off Packagist, and requires the list', function () {
    config(['addons.install.packages' => 'nicetry/captchas  nicetry/threats:^1.2']);
    $folder = addonsApp();

    $this->artisan('addons:install', ['--working-dir' => $folder])
        ->expectsOutputToContain('Installed nicetry/captchas 1.2.0.')
        ->doesntExpectOutputToContain('ntbc_secret-token')
        ->assertSuccessful();

    expect($this->runs)->toBe([
        ['config', '--append', 'repositories.nicetrybot', '{"type":"composer","url":"https://nicetrybot.com/composer","only":["nicetry/*"]}', '--no-interaction'],
        ['config', '--append', 'repositories.packagist.org', '{"type":"composer","url":"https://repo.packagist.org","exclude":["nicetry/*"]}', '--no-interaction'],
        ['require', '--no-scripts', '--no-progress', '--prefer-dist', '--update-no-dev', 'nicetry/captchas', 'nicetry/threats:^1.2', '--no-interaction'],
        ['dump-autoload', '--optimize', '--no-dev', '--no-interaction'],
    ]);

    Process::assertRan(fn (PendingProcess $process) => $process->path === $folder
        && ($process->command[1] ?? null) === 'require'
        && $process->environment === ['COMPOSER_AUTH' => '{"bearer":{"nicetrybot.com":"ntbc_secret-token"}}']);

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'config' && isset($process->environment['COMPOSER_AUTH']));
});

it('keeps dev packages where they are installed', function () {
    config(['addons.install.packages' => 'nicetry/captchas']);

    $this->artisan('addons:install', ['--working-dir' => addonsApp(dev: true)])->assertSuccessful();

    expect($this->runs)->toContain(
        ['require', '--no-scripts', '--no-progress', '--prefer-dist', 'nicetry/captchas', '--no-interaction'],
        ['dump-autoload', '--optimize', '--no-interaction'],
    );
});

it('leaves the add-ons that come with NiceTryBot alone', function () {
    config(['addons.install.packages' => 'nicetry/lockdown']);

    $this->artisan('addons:install', ['--working-dir' => addonsApp()])
        ->expectsOutputToContain('nicetry/lockdown comes with NiceTryBot')
        ->expectsOutputToContain('No add-ons to install')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('removes add-ons taken off the list', function () {
    config(['addons.install.packages' => 'nicetry/captchas']);

    $this->artisan('addons:install', ['--working-dir' => addonsApp(['nicetry/captchas', 'nicetry/threats'])])
        ->expectsOutputToContain('Removed nicetry/threats.')
        ->assertSuccessful();

    expect($this->runs)->toContain(
        ['remove', '--no-scripts', '--no-progress', '--update-no-dev', 'nicetry/threats', '--no-interaction'],
        ['require', '--no-scripts', '--no-progress', '--prefer-dist', '--update-no-dev', 'nicetry/captchas', '--no-interaction'],
    );
});

it('removes every marketplace add-on when the list is emptied', function () {
    $this->artisan('addons:install', ['--working-dir' => addonsApp(['nicetry/threats'])])->assertSuccessful();

    expect(array_column($this->runs, 0))->toBe(['config', 'config', 'remove', 'dump-autoload']);
});

it('reads the token from a file, for a Docker build secret', function () {
    config(['addons.install.packages' => 'nicetry/captchas', 'addons.install.token' => null]);
    $secret = tempnam(sys_get_temp_dir(), 'token');
    file_put_contents($secret, "ntbc_from-a-file\n");

    $this->artisan('addons:install', ['--working-dir' => addonsApp(), '--token-file' => $secret])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => ($process->environment['COMPOSER_AUTH'] ?? null) === '{"bearer":{"nicetrybot.com":"ntbc_from-a-file"}}');

    unlink($secret);
});

it('needs a token to install anything', function () {
    config(['addons.install.packages' => 'nicetry/captchas', 'addons.install.token' => '']);

    $this->artisan('addons:install', ['--working-dir' => addonsApp()])
        ->expectsOutputToContain('NICETRY_COMPOSER_TOKEN is empty')
        ->assertFailed();

    Process::assertNothingRan();
});

it('refuses a list entry that isn\'t a package', function () {
    config(['addons.install.packages' => 'nicetry/captchas; rm -rf /']);

    $this->artisan('addons:install', ['--working-dir' => addonsApp()])
        ->expectsOutputToContain('isn\'t a Composer package')
        ->assertFailed();

    Process::assertNothingRan();
});

it('allows plain HTTP only for an http:// repository, and gives the token for its host and port', function () {
    config(['addons.install.packages' => 'nicetry/captchas', 'addons.install.repository' => 'http://composer.test:8099/composer']);

    $this->artisan('addons:install', ['--working-dir' => addonsApp()])
        ->expectsOutputToContain('isn\'t HTTPS')
        ->assertSuccessful();

    expect($this->runs)->toContain(['config', 'secure-http', 'false', '--no-interaction']);
    Process::assertRan(fn (PendingProcess $process) => ($process->environment['COMPOSER_AUTH'] ?? null) === '{"bearer":{"composer.test:8099":"ntbc_secret-token"}}');
});

it('stops at the first Composer command that fails', function () {
    config(['addons.install.packages' => 'nicetry/captchas']);
    $this->failing = 'require';

    $this->artisan('addons:install', ['--working-dir' => addonsApp()])
        ->expectsOutputToContain('composer require failed (exit 2)')
        ->assertFailed();

    Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[1] ?? null) === 'dump-autoload');
});

it('needs composer install first', function () {
    $this->artisan('addons:install', ['--working-dir' => sys_get_temp_dir().'/nicetry-addons-nowhere'])
        ->expectsOutputToContain('run composer install first')
        ->assertFailed();
});
