<?php

namespace App\Console\Commands;

use App\Actions\Networks\LoadNetworkCategories as LoadCategories;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('networks:load-categories')]
#[Description('Load what each autonomous system is for from the shipped data, keeping what administrators set by hand')]
class LoadNetworkCategories extends Command
{
    public function handle(LoadCategories $load): int
    {
        $this->components->info(number_format($load->handle()).' network categories loaded.');

        return self::SUCCESS;
    }
}
