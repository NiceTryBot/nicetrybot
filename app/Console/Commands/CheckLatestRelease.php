<?php

namespace App\Console\Commands;

use App\Support\Releases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

#[Signature('nicetry:check-release')]
#[Description('Ask where releases are published for the newest NiceTryBot release, so a self-hosted panel can tell its administrators to update')]
class CheckLatestRelease extends Command
{
    /**
     * A release tag, like v1.3.0 or 1.3.0-beta.1.
     */
    private const string VERSION_PATTERN = '/^v?(\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?)$/';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (config('addons.install.via') !== 'docker') {
            $this->info('Not the release image; it\'s updated where its code lives.');

            return self::SUCCESS;
        }

        if (Releases::current() === null) {
            $this->info('This image doesn\'t know its release (NICETRY_RELEASE), so there\'s nothing to compare.');

            return self::SUCCESS;
        }

        if (! config('nicetry.update_check.enabled')) {
            $this->info('The update check is off (NICETRY_UPDATE_CHECK).');

            return self::SUCCESS;
        }

        $releases = (string) config('nicetry.update_check.releases');
        $version = $this->latestVersion($releases);

        if ($version === null) {
            $this->warn('Couldn\'t tell the newest release from '.$releases.'/latest; the last answer stands.');

            return self::SUCCESS;
        }

        Releases::remember($version, $releases.'/tag/v'.$version);

        $this->info(Releases::newer() === null
            ? sprintf('NiceTryBot %s is the newest release; this panel runs %s.', $version, Releases::current())
            : sprintf('NiceTryBot %s is out; this panel runs %s.', $version, Releases::current()));

        return self::SUCCESS;
    }

    /**
     * The version {releases}/latest redirects to (it ends in /tag/v1.3.0), without the v.
     */
    private function latestVersion(string $releases): ?string
    {
        try {
            $response = Http::withoutRedirecting()->timeout(10)->get($releases.'/latest');
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->redirect()) {
            return null;
        }

        $path = (string) parse_url($response->header('Location'), PHP_URL_PATH);
        $tag = rawurldecode(basename($path));

        if (! str_contains($path, '/tag/') || preg_match(self::VERSION_PATTERN, $tag, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
