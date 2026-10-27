<?php

namespace App\Console\Commands;

use App\Addons\Addon;
use App\Addons\Addons;
use App\Addons\InvalidLicenceKey;
use App\Addons\Licences;
use App\Addons\LicenceState;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('addons:licence {addon? : The add-on’s slug, as on the Add-ons page (captchas, fingerprints, …)} {key? : The licence key from your account on the marketplace} {--missing : List the paid add-ons without a good key instead, one slug per line}')]
#[Description('Enter a paid add-on’s licence key, as the Add-ons page does, or list the ones still needing one')]
class EnterAddonLicence extends Command
{
    public function handle(Addons $addons, Licences $licences): int
    {
        if ($this->option('missing')) {
            // For nicetrybot addons add, which asks for each of these keys after building them in.
            foreach ($addons->all() as $addon) {
                if ($addon->paid && $licences->state($addon) !== LicenceState::Licensed) {
                    $this->line($addon->slug);
                }
            }

            return self::SUCCESS;
        }

        if (! is_string($this->argument('addon')) || ! is_string($this->argument('key'))) {
            $this->error('Give the add-on and its key, like: php artisan addons:licence captchas ntb1… (or --missing to list the ones without one).');

            return self::FAILURE;
        }

        $addon = $addons->find($this->argument('addon'));

        if ($addon === null) {
            $this->error(sprintf('No add-on called %s is installed. Installed: %s.', $this->argument('addon'),
                implode(', ', array_map(fn (Addon $addon): string => $addon->slug, $addons->all())) ?: 'none'));

            return self::FAILURE;
        }

        if (! $addon->paid) {
            $this->info("{$addon->name} is free; it needs no licence key.");

            return self::SUCCESS;
        }

        try {
            $licence = $licences->enter($addon, $this->argument('key'));
        } catch (InvalidLicenceKey $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($licence->isRevoked()) {
            $this->error("The marketplace says that licence was revoked. {$addon->name} stays off until another key is entered.");

            return self::FAILURE;
        }

        $this->info(sprintf('%s is licensed to %s, with updates until %s.', $addon->name, $licence->licensed_to ?: 'you', $licence->updates_until?->toFormattedDateString()));

        if ($licence->checked_at === null) {
            $this->warn('The marketplace couldn’t be reached to confirm it; it’s asked again on the daily check.');
        }

        return self::SUCCESS;
    }
}
