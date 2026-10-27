<?php

namespace App\Console\Commands;

use App\Actions\Clients\RecordClientProfiles;
use App\Enums\TlsFingerprintType;
use App\Models\ClientHint;
use App\Models\TlsFingerprint;
use App\Models\UserAgent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('edge:profile-clients')]
#[Description('Classify every recorded user agent, JA4 fingerprint and set of client hints into the client profile tables (run after deploying them, or after changing a classifier)')]
class ProfileClients extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RecordClientProfiles $recordClientProfiles): int
    {
        $userAgents = $fingerprints = $clientHints = 0;

        UserAgent::query()->toBase()->orderBy('id')->chunkById(1000, function ($rows) use ($recordClientProfiles, &$userAgents) {
            $recordClientProfiles->userAgents($rows->pluck('id', 'value')->all(), refresh: true);
            $userAgents += $rows->count();
        });

        TlsFingerprint::query()->toBase()->where('type', TlsFingerprintType::Ja4)->orderBy('id')->chunkById(1000, function ($rows) use ($recordClientProfiles, &$fingerprints) {
            $recordClientProfiles->ja4Fingerprints($rows->pluck('id', 'value')->all(), refresh: true);
            $fingerprints += $rows->count();
        });

        ClientHint::query()->toBase()->orderBy('id')->chunkById(1000, function ($rows) use ($recordClientProfiles, &$clientHints) {
            $recordClientProfiles->clientHints($rows->pluck('id', 'value')->all(), refresh: true);
            $clientHints += $rows->count();
        });

        $this->info(sprintf('Profiled %d user agent(s), %d JA4 fingerprint(s) and %d set(s) of client hints.', $userAgents, $fingerprints, $clientHints));

        return self::SUCCESS;
    }
}
