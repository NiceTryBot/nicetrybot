<?php

namespace App\Concerns;

use Livewire\Attributes\Renderless;

/**
 * Lets a Livewire page's domain picker (x-ntb.domain-picker) look up the team's domains as you type,
 * instead of the page listing every domain: an origin can bring thousands. Pages using it have a
 * `team` (computed) property.
 */
trait SearchesDomains
{
    /**
     * The team's hostnames containing the text, alphabetically, up to a screenful.
     *
     * @return list<string>
     */
    #[Renderless]
    public function searchDomains(string $query = ''): array
    {
        $query = mb_strtolower(trim($query));

        return $this->team->domains()
            ->when($query !== '', fn ($domains) => $domains->whereRaw('lower(hostname) like ?', ['%'.addcslashes($query, '%_\\').'%']))
            ->orderBy('hostname')
            ->limit(25)
            ->pluck('hostname')
            ->all();
    }
}
