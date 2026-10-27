<?php

namespace App\Actions\Domains;

use App\Models\Domain;
use App\Support\DomainRecords;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MergeDomains
{
    public function __construct(private DomainRecords $domainRecords)
    {
        //
    }

    /**
     * Fold one domain into another of the same team, as a www.site domain folds into site: move its
     * history (the rows of every DomainRecords model, the core's and add-ons') onto the other, then remove
     * it.
     */
    public function handle(Domain $from, Domain $into): void
    {
        if ($from->team_id !== $into->team_id || $from->is($into)) {
            throw new InvalidArgumentException('A domain only merges into another domain of the same team.');
        }

        DB::transaction(function () use ($from, $into) {
            foreach ($this->domainRecords->models() as $model) {
                $model::query()->where('domain_id', $from->id)->update(['domain_id' => $into->id]);
            }

            $from->delete();
        });
    }
}
