<?php

namespace App\Actions\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;

class VerifyDomainOwnership
{
    public function __construct(private LookUpDnsRecords $lookUpDnsRecords)
    {
        //
    }

    /**
     * Look for the ownership TXT record in public DNS and, when it's there, mark the domain verified and
     * start protecting it. An installation with DNS verification off (config nicetry.verify_dns), like one
     * whose test domains don't resolve publicly, takes every check on trust.
     *
     * Returns whether the domain is verified.
     */
    public function handle(Domain $domain): bool
    {
        if ($domain->verified_at !== null) {
            return true;
        }

        if (config('nicetry.verify_dns') && ! $this->hasVerificationRecord($domain)) {
            return false;
        }

        $domain->update([
            'status' => DomainStatus::Active,
            'verified_at' => now(),
        ]);

        return true;
    }

    /**
     * Determine if the domain's TXT record name holds its expected value, ignoring case and stray quotes.
     */
    private function hasVerificationRecord(Domain $domain): bool
    {
        $expected = strtolower($domain->verificationRecordValue());

        return collect($this->lookUpDnsRecords->txtRecords($domain->verificationRecordName()))
            ->contains(fn (string $record) => strtolower(trim($record, " \t\"")) === $expected);
    }
}
