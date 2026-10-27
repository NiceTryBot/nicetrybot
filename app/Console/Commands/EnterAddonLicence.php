<?php

namespace App\Console\Commands;

use App\Addons\Addon;
use App\Addons\Addons;
use App\Addons\InvalidLicenceKey;
use App\Addons\Licences;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('addons:licence {addon : The add-on’s slug, as on the Add-ons page (captchas, fingerprints, …)} {key : The licence key from your account on the marketplace}')]
#[Description('Enter a paid add-on’s licence key, as the Add-ons page does')]
class EnterAddonLicence extends Command
{
    public function handle(Addons $addons, Licences $licences): int
    {
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
