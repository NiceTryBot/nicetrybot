<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use JsonException;

/**
 * Makes the installed marketplace add-ons match NICETRY_ADDONS, with Composer: adds the marketplace's
 * Composer repository (for nicetry/* only, and keeps nicetry/* off Packagist, so a package someone
 * else publishes under that name is never installed), requires the listed add-ons at the newest
 * versions the buyer's token may have, and removes ones taken off the list. Add-ons that come with
 * NiceTryBot (packages/) are left alone. The Docker image runs it as it builds; a server without
 * Docker runs it after each update. The token reaches Composer only, through COMPOSER_AUTH.
 */
#[Signature('addons:install
    {--token-file= : Read the Composer token from this file instead of NICETRY_COMPOSER_TOKEN (a Docker build secret)}
    {--composer=composer : The Composer command}
    {--working-dir= : The app’s folder, if not this one}')]
#[Description('Install the marketplace add-ons in NICETRY_ADDONS with Composer, and remove ones taken off the list')]
class InstallAddons extends Command
{
    /** A Composer package name, optionally with a version constraint: nicetry/captchas or nicetry/captchas:^1.2. */
    private const string PACKAGE = '/^([a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*)(?::([\w.*^~|<>=-]+))?$/';

    /** The vendor the marketplace's add-ons are published under. */
    private const string VENDOR = 'nicetry/';

    private string $directory;

    public function handle(): int
    {
        $this->directory = rtrim((string) ($this->option('working-dir') ?: base_path()), '/');

        if (! is_file("{$this->directory}/vendor/composer/installed.json")) {
            $this->error("There's no vendor/ in {$this->directory}: run composer install first.");

            return self::FAILURE;
        }

        $wanted = $this->wanted();

        if ($wanted === null) {
            return self::FAILURE;
        }

        $bundled = $this->bundled();

        foreach (array_intersect(array_keys($wanted), $bundled) as $name) {
            $this->line("{$name} comes with NiceTryBot; there's nothing to install.");
            unset($wanted[$name]);
        }

        $remove = array_values(array_diff($this->installedFromMarketplace($bundled), array_keys($wanted)));

        if ($wanted === [] && $remove === []) {
            $this->info('No add-ons to install: NICETRY_ADDONS is empty.');

            return self::SUCCESS;
        }

        $repository = (string) config('addons.install.repository');
        $origin = $this->origin($repository);

        if ($origin === null) {
            $this->error("NICETRY_COMPOSER_URL isn't an http(s) URL: {$repository}");

            return self::FAILURE;
        }

        $token = $this->token();

        if ($wanted !== [] && $token === '') {
            $this->error(sprintf('NICETRY_COMPOSER_TOKEN is empty. Make a Composer token on your account at %s/account, then put it in .env.', config('addons.marketplace.url')));

            return self::FAILURE;
        }

        // Without dev packages unless they're installed: a checkout someone develops in keeps them.
        $noDev = $this->installedWithoutDev() ? ['--update-no-dev'] : [];
        $auth = $token === '' ? [] : ['COMPOSER_AUTH' => $this->json(['bearer' => [$origin => $token]])];

        $steps = [
            [['config', '--append', 'repositories.nicetrybot', $this->json(['type' => 'composer', 'url' => $repository, 'only' => [self::VENDOR.'*']])], []],
            [['config', '--append', 'repositories.packagist.org', $this->json(['type' => 'composer', 'url' => 'https://repo.packagist.org', 'exclude' => [self::VENDOR.'*']])], []],
        ];

        if (str_starts_with($repository, 'http://')) {
            $this->warn("{$repository} isn't HTTPS: Composer is told to allow plain HTTP. Use that only for testing.");
            $steps[] = [['config', 'secure-http', 'false'], []];
        }

        if ($remove !== []) {
            $steps[] = [['remove', '--no-scripts', '--no-progress', ...$noDev, ...$remove], $auth];
        }

        if ($wanted !== []) {
            $packages = array_map(fn (string $name, ?string $constraint): string => $constraint === null ? $name : "{$name}:{$constraint}", array_keys($wanted), $wanted);
            $steps[] = [['require', '--no-scripts', '--no-progress', '--prefer-dist', ...$noDev, ...$packages], $auth];
        }

        // The autoloader, and package:discover (post-autoload-dump), which registers the add-ons.
        $steps[] = [['dump-autoload', '--optimize', ...($noDev === [] ? [] : ['--no-dev'])], []];

        foreach ($steps as [$arguments, $environment]) {
            if (! $this->composer($arguments, $environment)) {
                return self::FAILURE;
            }
        }

        foreach ($remove as $name) {
            $this->line("Removed {$name}.");
        }

        $versions = $this->installedVersions();

        foreach (array_keys($wanted) as $name) {
            $this->info(isset($versions[$name]) ? "Installed {$name} {$versions[$name]}." : "Installed {$name}.");
        }

        $this->newLine();
        $this->line('Next: php artisan migrate --force, then npm ci && npm run build. (The Docker image does both.)');

        return self::SUCCESS;
    }

    /**
     * NICETRY_ADDONS as package => version constraint (null: the newest the token may have), or null
     * when an entry isn't a package name.
     *
     * @return array<string, string|null>|null
     */
    private function wanted(): ?array
    {
        $wanted = [];
        $list = strtolower(trim((string) config('addons.install.packages')));

        if ($list === 'all') {
            return $this->everythingTheTokenMayHave();
        }

        $entries = preg_split('/[\s,]+/', $list, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($entries as $entry) {
            if (! preg_match(self::PACKAGE, $entry, $match)) {
                $this->error("NICETRY_ADDONS has \"{$entry}\", which isn't a Composer package like nicetry/captchas.");

                return null;
            }

            $wanted[$match[1]] = ($match[5] ?? '') === '' ? null : $match[5];
        }

        return $wanted;
    }

    /**
     * NICETRY_ADDONS=all: every marketplace add-on the token may install, as the repository's
     * packages.json lists them (what Composer itself reads), less the ones that come with NiceTryBot.
     * A purchase made later comes in on the next run; a refunded one goes.
     *
     * @return array<string, null>|null
     */
    private function everythingTheTokenMayHave(): ?array
    {
        $repository = rtrim((string) config('addons.install.repository'), '/');
        $token = $this->token();

        if ($token === '') {
            $this->error('NICETRY_ADDONS=all needs NICETRY_COMPOSER_TOKEN: it decides which add-ons are yours.');

            return null;
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(30)->get("{$repository}/packages.json");
        } catch (ConnectionException $e) {
            $this->error("Couldn't reach {$repository} to see which add-ons are yours: {$e->getMessage()}");

            return null;
        }

        if (! $response->ok()) {
            $this->error("{$repository} answered {$response->status()} when asked which add-ons are yours. Check NICETRY_COMPOSER_TOKEN.");

            return null;
        }

        $bundled = $this->bundled();
        $names = array_values(array_filter(
            array_map(fn ($name) => strtolower((string) $name), (array) $response->json('available-packages', [])),
            fn (string $name) => str_starts_with($name, self::VENDOR) && preg_match(self::PACKAGE, $name) && ! in_array($name, $bundled, true),
        ));

        $this->line('NICETRY_ADDONS=all: '.($names === [] ? 'no add-ons of yours to install yet.' : implode(', ', $names).'.'));

        return array_fill_keys($names, null);
    }

    /**
     * The add-ons that come with NiceTryBot, from packages/.
     *
     * @return list<string>
     */
    private function bundled(): array
    {
        $names = [];

        foreach (glob("{$this->directory}/packages/*/composer.json") ?: [] as $file) {
            $name = $this->readJson($file)['name'] ?? null;

            if (is_string($name)) {
                $names[] = strtolower($name);
            }
        }

        return $names;
    }

    /**
     * The nicetry/* packages composer.json requires that don't come with NiceTryBot: the add-ons an
     * earlier run installed from the marketplace.
     *
     * @param  list<string>  $bundled
     * @return list<string>
     */
    private function installedFromMarketplace(array $bundled): array
    {
        $required = array_map(strtolower(...), array_keys((array) ($this->readJson("{$this->directory}/composer.json")['require'] ?? [])));

        return array_values(array_filter($required, fn (string $name): bool => str_starts_with($name, self::VENDOR) && ! in_array($name, $bundled, true)));
    }

    private function installedWithoutDev(): bool
    {
        return ($this->readJson("{$this->directory}/vendor/composer/installed.json")['dev'] ?? true) === false;
    }

    /**
     * @return array<string, string> each installed package's version, by name
     */
    private function installedVersions(): array
    {
        $versions = [];

        foreach ((array) ($this->readJson("{$this->directory}/vendor/composer/installed.json")['packages'] ?? []) as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $versions[strtolower($package['name'])] = (string) ($package['pretty_version'] ?? $package['version'] ?? '');
            }
        }

        return $versions;
    }

    private function token(): string
    {
        $file = $this->option('token-file');

        if (is_string($file) && $file !== '') {
            return is_readable($file) ? trim((string) file_get_contents($file)) : '';
        }

        return trim((string) config('addons.install.token'));
    }

    /**
     * The host Composer matches the token to: the repository's host, with its port when it has one.
     */
    private function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || ! isset($parts['host'])) {
            return null;
        }

        return strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Run Composer in the app's folder, showing what it says (it never prints the token).
     *
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    private function composer(array $arguments, array $environment): bool
    {
        $result = Process::path($this->directory)
            ->env($environment)
            ->timeout(900)
            ->run([(string) $this->option('composer'), ...$arguments, '--no-interaction'], function (string $type, string $output): void {
                $this->output->write($output);
            });

        if ($result->failed()) {
            $this->error("composer {$arguments[0]} failed (exit {$result->exitCode()}).");

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $file): array
    {
        try {
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
