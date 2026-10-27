<?php

namespace App\Console\Commands;

use App\Actions\Clients\FindCurrentFirefoxReleases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

#[Signature('clients:refresh-firefox')]
#[Description('Download the Firefox versions Mozilla ships (current and ESR) that "outdated" Firefox is measured against')]
class RefreshFirefoxReleases extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(FindCurrentFirefoxReleases $findCurrentFirefoxReleases): int
    {
        try {
            $releases = $findCurrentFirefoxReleases->refresh();
        } catch (ConnectionException|RequestException $exception) {
            $this->error('Couldn\'t download Mozilla\'s release list; kept the one we have. '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($releases['current'] === null) {
            $this->error('Mozilla\'s release list had no current Firefox version; kept the one we have.');

            return self::FAILURE;
        }

        $this->info(sprintf('Firefox %d is current; ESR %s still supported.', $releases['current'], implode(', ', $releases['esr']) ?: 'none'));

        return self::SUCCESS;
    }
}
