<?php

use App\Enums\RequestAction;
use App\Enums\ViewSlot;
use App\Models\Domain;
use App\Models\Team;
use App\Support\SharedOrigins;
use App\Support\ViewSlots;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Domains & origins')] class extends Component {
    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * @return Collection<int, Domain>
     */
    #[Computed]
    public function domains(): Collection
    {
        $since = now()->subDay();

        return $this->team->domains()
            ->standalone()
            ->withCount([
                'edgeRequests as requests_count' => fn (Builder $query) => $query->where('occurred_at', '>=', $since),
                'edgeRequests as suspicious_count' => fn (Builder $query) => $query->where('occurred_at', '>=', $since)->where('action', '<>', RequestAction::Passed),
                'edgeRequests as not_found_count' => fn (Builder $query) => $query->where('occurred_at', '>=', $since)->where('status_code', 404),
            ])
            ->withExists('edgeRequests as has_traffic')
            ->orderBy('hostname')
            ->get();
    }

    /**
     * How many shared origins the team has (App\Support\SharedOrigins): an add-on lists them above the
     * domains, each origin's sites on its own page.
     */
    #[Computed]
    public function sharedOriginCount(): int
    {
        return app(SharedOrigins::class)->origins($this->team)->count();
    }
}; ?>

<div>
    <x-ntb.page-heading
        :overline="__('Your front doors')"
        :title="__('Domains & origins')"
        :description="__('Every domain NiceTryBot stands in front of, and the origin it forwards humans to.')"
    >
        @can('create', [Domain::class, $this->team])
            <x-slot:actions>
                {{-- Add-ons' buttons, from App\Support\ViewSlots. --}}
                {{ app(ViewSlots::class)->render(ViewSlot::DomainsActions, ['team' => $this->team]) }}

                <flux:modal.trigger name="add-domain">
                    <flux:button variant="primary" size="sm" icon="plus" data-test="open-add-domain">{{ __('Add domain') }}</flux:button>
                </flux:modal.trigger>
            </x-slot:actions>
        @endcan
    </x-ntb.page-heading>

    {{-- Add-ons' sections, like the team's shared origins, from App\Support\ViewSlots. --}}
    {{ app(ViewSlots::class)->render(ViewSlot::DomainsSections, ['team' => $this->team]) }}

    @if ($this->sharedOriginCount > 0 && $this->domains->isNotEmpty())
        <h2 class="mb-3 font-mono text-xs tracking-[0.14em] text-zinc-500 uppercase dark:text-zinc-400">{{ __('Domains') }}</h2>
    @endif

    @if ($this->domains->isEmpty() && $this->sharedOriginCount > 0)
        {{-- Shared origins only: their sites are listed on each origin's page. --}}
    @elseif ($this->domains->isEmpty())
        <x-ntb.panel>
            <x-ntb.empty-state :heading="__('No domains yet.')">
                {{ __('Add a domain and its origin. You\'ll get a TXT record to prove ownership and the DNS records to route traffic through the edge.') }}

                @can('create', [Domain::class, $this->team])
                    <x-slot:actions>
                        <flux:modal.trigger name="add-domain">
                            <flux:button variant="primary" icon="plus">{{ __('Add your first domain') }}</flux:button>
                        </flux:modal.trigger>
                    </x-slot:actions>
                @endcan
            </x-ntb.empty-state>
        </x-ntb.panel>
    @else
        <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
            @foreach ($this->domains as $domain)
                <a
                    href="{{ route('domains.show', $domain) }}"
                    class="group flex min-w-0 flex-col rounded-xl border border-line bg-white p-5 transition hover:-translate-y-0.5 hover:border-zinc-300 hover:shadow-sm dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700"
                    wire:key="domain-{{ $domain->id }}"
                    wire:navigate
                    data-test="domain-card"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-base font-semibold tracking-tight">{{ $domain->hostname }}</p>
                            <p class="mt-1 flex items-center gap-1.5 truncate font-mono text-xs text-zinc-500 dark:text-zinc-400">
                                <flux:icon.arrow-uturn-right variant="micro" class="size-3 shrink-0" />
                                {{ $domain->origin_url }}
                            </p>
                        </div>
                        <x-ntb.domain-status :status="$domain->status" />
                    </div>

                    @if ($domain->has_traffic)
                    <dl class="mt-5 grid grid-cols-3 gap-3 border-t border-line pt-4 dark:border-zinc-800">
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Requests') }}</dt>
                            <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums">{{ Number::abbreviate($domain->requests_count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Suspicious') }}</dt>
                            <dd @class([
                                'mt-0.5 font-mono text-lg font-semibold tabular-nums',
                                'text-coral-700 dark:text-coral-300' => $domain->suspicious_count > 0,
                            ])>{{ Number::abbreviate($domain->suspicious_count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('404s') }}</dt>
                            <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums">{{ Number::abbreviate($domain->not_found_count) }}</dd>
                        </div>
                    </dl>
                    @else
                    <div class="mt-5 border-t border-line pt-4 dark:border-zinc-800" data-test="domain-awaiting-traffic">
                        <p class="text-sm font-medium">{{ __('No traffic recorded yet') }}</p>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Numbers appear a minute or two after the first request reaches the edge.') }}</p>
                    </div>
                    @endif

                    <div class="mt-4 flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                        <span class="inline-flex items-center gap-1.5">
                            <flux:badge size="sm" :color="$domain->protection_mode->color()">{{ $domain->protection_mode->label() }}</flux:badge>
                            {{ __('last 24 hours') }}
                        </span>
                        <span class="inline-flex items-center gap-1 font-medium text-ink transition group-hover:gap-2 dark:text-white">
                            {{ $domain->verified_at ? __('Settings') : __('Finish setup') }}
                            <flux:icon.chevron-right variant="micro" class="size-3.5" />
                        </span>
                    </div>
                </a>
            @endforeach

            @can('create', [Domain::class, $this->team])
                <flux:modal.trigger name="add-domain">
                    <button type="button" class="flex min-h-48 flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-zinc-300 p-5 text-sm text-zinc-500 transition hover:border-coral-400 hover:text-coral-700 dark:border-zinc-700 dark:text-zinc-400 dark:hover:border-coral-500 dark:hover:text-coral-300">
                        <flux:icon.plus class="size-6" />
                        <span class="font-medium">{{ __('Add another domain') }}</span>
                    </button>
                </flux:modal.trigger>
            @endcan
        </div>
    @endif
</div>
