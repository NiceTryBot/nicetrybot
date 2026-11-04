<?php

use App\Models\Domain;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use NiceTry\DynamicSubdomains\Actions\ClaimSubdomain;
use NiceTry\DynamicSubdomains\Models\DynamicSubdomain;
use NiceTry\DynamicSubdomains\Models\DynamicSubdomainParent;

new class extends Component
{
    #[Locked]
    public int $domainId;

    public bool $enabled = false;

    public function mount(Domain $domain): void
    {
        abort_unless($domain->team_id === Auth::user()->currentTeam->id, 404);

        $this->domainId = $domain->id;
        $this->enabled = DynamicSubdomainParent::query()->where('domain_id', $domain->id)->exists();
    }

    #[Computed]
    public function domain(): Domain
    {
        return Auth::user()->currentTeam->domains()->findOrFail($this->domainId);
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('update', $this->domain);
    }

    /**
     * The subdomains this domain added, newest first.
     *
     * @return Collection<int, DynamicSubdomain>
     */
    #[Computed]
    public function subdomains(): Collection
    {
        return DynamicSubdomain::query()
            ->where('parent_domain_id', $this->domainId)
            ->with('domain')
            ->latest('id')
            ->get();
    }

    /**
     * Turn dynamic subdomains on or off. Off leaves the subdomains already added as they are.
     */
    public function updatedEnabled(bool $enabled): void
    {
        Gate::authorize('update', $this->domain);

        if ($enabled) {
            DynamicSubdomainParent::query()->firstOrCreate(['domain_id' => $this->domainId]);
            Flux::toast(variant: 'success', text: __('On. A visit to a new name under :hostname adds it.', ['hostname' => $this->domain->hostname]));
        } else {
            DynamicSubdomainParent::query()->where('domain_id', $this->domainId)->delete();
            Flux::toast(text: __('Off. Subdomains already added stay.'));
        }
    }
}; ?>

<x-ntb.panel :heading="__('Dynamic subdomains')" :subheading="__('Add names under this domain as they\'re visited.')" data-test="domain-dynamic-subdomains">
    <flux:checkbox
        wire:model.live="enabled"
        :label="__('Add Dynamic Subdomains')"
        :description="__('A visit to foo.:hostname adds it as a domain of its own, live straight away, with this domain\'s settings and :origin as its origin. Up to :limit. Point *.:hostname at the edge too.', [
            'hostname' => $this->domain->hostname,
            'origin' => ClaimSubdomain::originUrlFor($this->domain, 'foo'),
            'limit' => (int) config('dynamic-subdomains.limit'),
        ])"
        :disabled="! $this->canManage"
        data-test="domain-dynamic-subdomains-toggle"
    />

    @if ($this->subdomains->isNotEmpty())
        <div class="mt-4 space-y-2">
            @foreach ($this->subdomains as $subdomain)
                <a wire:key="dynamic-subdomain-{{ $subdomain->id }}" href="{{ route('domains.show', $subdomain->domain) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg bg-canvas px-3 py-2 text-sm hover:bg-zinc-100 dark:bg-zinc-800/60 dark:hover:bg-zinc-800" data-test="dynamic-subdomain">
                    <span class="min-w-0 truncate font-mono text-xs">{{ $subdomain->domain->hostname }}</span>
                    <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ $subdomain->created_at?->diffForHumans() }}</span>
                </a>
            @endforeach
        </div>
    @endif
</x-ntb.panel>
