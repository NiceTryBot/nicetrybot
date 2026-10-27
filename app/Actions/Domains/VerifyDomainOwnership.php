<?php

namespace App\Actions\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;

class VerifyDomainOwnership
{
    /**
     * Mark the domain as verified and start protecting it.
     *
     * Stub: the real check resolves the TXT record at the domain's verification
     * record name and compares it to the expected value. This preview accepts every
     * check so the rest of the onboarding flow can be explored.
     */
    public function handle(Domain $domain): Domain
    {
        if ($domain->verified_at !== null) {
            return $domain;
        }

        $domain->update([
            'status' => DomainStatus::Active,
            'verified_at' => now(),
        ]);

        return $domain;
    }
}
