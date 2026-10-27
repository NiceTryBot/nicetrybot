<?php

namespace App\Console\Commands;

use App\Addons\DomainLimit;
use App\Addons\Licences;
use App\Models\AddonLicence;
use App\Models\DomainPack;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('addons:check-licences')]
#[Description('Ask the marketplace whether each paid add-on’s licence key and each More domains pack is still good (a revoked key turns its add-on off, or its 100 domains)')]
class CheckAddonLicences extends Command
{
    public function handle(Licences $licences, DomainLimit $domains): int
    {
        $held = AddonLicence::query()->whereNotNull('licence_id')->orderBy('slug')->get();
        $packs = DomainPack::query()->orderBy('id')->get();

        if ($held->isEmpty() && $packs->isEmpty()) {
            $this->info('No licence keys entered.');

            return self::SUCCESS;
        }

        foreach ($held as $licence) {
            $this->line($licences->check($licence)
                ? sprintf('%s: %s', $licence->slug, $licence->status)
                : sprintf('%s: the marketplace didn’t answer; the last answer stands (%s)', $licence->slug, $licence->status));
        }

        foreach ($packs as $pack) {
            $this->line($domains->check($pack)
                ? sprintf('More domains pack %s: %s', $pack->licence_id, $pack->status)
                : sprintf('More domains pack %s: the marketplace didn’t answer; the last answer stands (%s)', $pack->licence_id, $pack->status));
        }

        return self::SUCCESS;
    }
}
