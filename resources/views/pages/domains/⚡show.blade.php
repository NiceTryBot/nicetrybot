<?php

use App\Actions\Analytics\SummarizeTraffic;
use App\Actions\Domains\CheckEdgeDns;
use App\Actions\Domains\GenerateSampleTraffic;
use App\Data\TrafficSummary;
use App\Enums\DomainStatus;
use App\Enums\HostHeaderMode;
use App\Enums\HstsPolicy;
use App\Enums\ProtectionMode;
use App\Enums\TrafficPeriod;
use App\Enums\ViewSlot;
use App\Jobs\VerifyDomainOwnershipJob;
use App\Models\Domain;
use App\Rules\HeaderNames;
use App\Rules\Hostname;
use App\Rules\ReachableOrigin;
use App\Support\HeaderName;
use App\Support\PreviewHostname;
use App\Support\SharedOrigin;
use App\Support\SharedOrigins;
use App\Support\ViewSlots;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Domain')] class extends Component {
    /**
     * The protection choice of a site of a shared origin that takes the origin's mode.
     */
    private const string FOLLOWS_ORIGIN = 'origin';

    public Domain $domain;

    public string $originUrl = '';

    public string $hostHeaderMode = '';

    public string $originHostHeader = '';

    public string $forwardedHostHeader = '';

    public string $serverHeader = '';

    public string $hstsPolicy = '';

    public bool $cacheAssets = false;

    public bool $servesWww = false;

    public int $originTimeoutSeconds = 30;

    public string $protectionMode = '';

    public ?string $edgeDnsSummary = null;

    public bool $isEdgeDnsPointed = false;

    public bool $isCheckingTxtRecord = false;

    public ?string $txtRecordProblem = null;

    public function mount(Domain $domain): void
    {
        abort_unless($domain->team_id === Auth::user()->currentTeam?->id, 404);

        Gate::authorize('view', $domain);

        $this->domain = $domain;
        $this->fillOriginSettings();
    }

    /**
     * Queue a lookup of the ownership TXT record; the page polls pollTxtRecord() until it's done.
     */
    public function verify(): void
    {
        Gate::authorize('update', $this->domain);

        $this->txtRecordProblem = null;
        $this->isCheckingTxtRecord = true;

        VerifyDomainOwnershipJob::start($this->domain);

        $this->pollTxtRecord();
    }

    /**
     * Pick up the queued TXT record lookup's outcome once it has one. A check that's gone a minute
     * without one (no queue worker, say) is given up on, so the page doesn't poll forever.
     */
    public function pollTxtRecord(): void
    {
        if (! $this->isCheckingTxtRecord) {
            return;
        }

        $check = VerifyDomainOwnershipJob::latest($this->domain);

        if ($check !== null && $check['state'] === 'checking' && now()->getTimestamp() - $check['at'] < 60) {
            return;
        }

        $this->isCheckingTxtRecord = false;

        if ($check === null || $check['state'] === 'checking') {
            $this->txtRecordProblem = __('The check didn\'t finish. Try again in a moment.');

            return;
        }

        $this->domain->refresh();

        if ($check['state'] === 'found') {
            Flux::toast(variant: 'success', text: __('TXT record found. :hostname is verified.', ['hostname' => $this->domain->hostname]));

            return;
        }

        $this->txtRecordProblem = __('No matching TXT record at :name yet. A new record can take a few minutes to show up, so check again shortly.', ['name' => $this->domain->verificationRecordName()]);
    }

    public function checkEdgeDns(CheckEdgeDns $checkEdgeDns): void
    {
        Gate::authorize('view', $this->domain);

        $check = $checkEdgeDns->handle($this->domain);

        $this->isEdgeDnsPointed = $check->isPointedAtEdge();
        $this->edgeDnsSummary = $check->summary();

        $this->domain->update(['edge_dns_pointed_at' => $this->isEdgeDnsPointed ? now() : null]);
    }

    public function saveOriginSettings(): void
    {
        Gate::authorize('update', $this->domain);

        abort_if($this->sharedOrigin !== null, 422, 'This site\'s settings come from its origin.');

        $this->forwardedHostHeader = HeaderName::normalizeList($this->forwardedHostHeader);

        $validated = $this->validate([
            'originUrl' => [
                'required',
                'string',
                'max:255',
                'url:http,https',
                new ReachableOrigin($this->domain->hostname),
            ],
            'hostHeaderMode' => ['required', Rule::enum(HostHeaderMode::class)],
            'originHostHeader' => ['nullable', 'string', new Hostname],
            'forwardedHostHeader' => ['nullable', 'string', new HeaderNames],
            'serverHeader' => ['nullable', 'string', 'max:100', 'regex:/^[\x20-\x7E]+$/'],
            'hstsPolicy' => ['required', Rule::enum(HstsPolicy::class)],
            'cacheAssets' => ['boolean'],
            'servesWww' => ['boolean'],
            'originTimeoutSeconds' => ['required', 'integer', 'between:5,120'],
        ], [
            'serverHeader.regex' => __('Use plain letters, numbers and punctuation, on one line.'),
        ]);

        $hostHeaderMode = HostHeaderMode::from($validated['hostHeaderMode']);

        $this->domain->update([
            'origin_url' => rtrim($validated['originUrl'], '/'),
            'host_header_mode' => $hostHeaderMode,
            'origin_host_header' => $hostHeaderMode === HostHeaderMode::Rewrite ? ($validated['originHostHeader'] ?: null) : null,
            'forwarded_host_header' => $validated['forwardedHostHeader'] ?: null,
            'server_header' => trim((string) $validated['serverHeader']) ?: null,
            'hsts_policy' => HstsPolicy::from($validated['hstsPolicy']),
            'cache_assets' => $validated['cacheAssets'],
            'serves_www' => $validated['servesWww'] && Domain::withoutWww($this->domain->hostname) === null,
            'origin_timeout_seconds' => $validated['originTimeoutSeconds'],
        ]);

        $this->fillOriginSettings();

        Flux::toast(variant: 'success', text: __('Origin settings saved.'));
    }

    /**
     * Save the protection mode. A site of a shared origin can set its own, which it keeps when the
     * origin's changes, or go back to the origin's (FOLLOWS_ORIGIN).
     */
    public function updatedProtectionMode(): void
    {
        Gate::authorize('update', $this->domain);

        $origin = $this->sharedOrigin;
        $followsOrigin = $origin !== null && $this->protectionMode === self::FOLLOWS_ORIGIN;

        if (! $followsOrigin) {
            $this->validate(['protectionMode' => ['required', Rule::enum(ProtectionMode::class)]]);
        }

        $this->domain->setProtectionMode($followsOrigin ? null : ProtectionMode::from($this->protectionMode));

        unset($this->keepsOwnProtectionMode);

        Flux::toast(text: $followsOrigin
            ? __('Protection follows :name again: :mode.', ['name' => $origin->name, 'mode' => $this->domain->protection_mode->label()])
            : __('Protection set to :mode.', ['mode' => $this->domain->protection_mode->label()]));
    }

    public function togglePause(): void
    {
        Gate::authorize('update', $this->domain);

        abort_if($this->domain->verified_at === null, 422);

        $this->domain->update([
            'status' => $this->domain->status === DomainStatus::Paused ? DomainStatus::Active : DomainStatus::Paused,
        ]);

        Flux::toast(text: $this->domain->status === DomainStatus::Paused
            ? __('Protection paused. Traffic still flows to your origin.')
            : __('Protection resumed.'));
    }

    public function rotateOriginSecret(): void
    {
        Gate::authorize('update', $this->domain);

        abort_if($this->sharedOrigin !== null, 422, 'This site\'s settings come from its origin.');

        $this->domain->update(['origin_secret' => Str::random(48)]);

        Flux::modal('rotate-secret')->close();

        Flux::toast(variant: 'warning', text: __('New origin secret issued. Update your origin before the old one stops working.'));
    }

    public function loadSampleTraffic(GenerateSampleTraffic $generateSampleTraffic): void
    {
        Gate::authorize('update', $this->domain);

        if ($this->domain->edgeRequests()->exists()) {
            return;
        }

        $created = $generateSampleTraffic->handle($this->domain, days: 7);

        unset($this->summary);

        Flux::toast(variant: 'success', text: __(':count sample requests loaded.', ['count' => Number::format($created)]));
    }

    public function deleteDomain(): void
    {
        Gate::authorize('delete', $this->domain);

        $hostname = $this->domain->hostname;

        $this->domain->delete();

        Flux::toast(variant: 'success', text: __(':hostname removed.', ['hostname' => $hostname]));

        $this->redirectRoute('domains.index', navigate: true);
    }

    #[Computed]
    public function summary(): TrafficSummary
    {
        return app(SummarizeTraffic::class)->handle($this->domain->team, TrafficPeriod::Day, $this->domain);
    }

    /**
     * The shared origin the domain is a site of, whose settings it takes (App\Support\SharedOrigins), or
     * null for a domain of its own.
     */
    #[Computed]
    public function sharedOrigin(): ?SharedOrigin
    {
        return app(SharedOrigins::class)->originOf($this->domain);
    }

    /**
     * Whether the domain, a site of a shared origin, sets its own protection mode instead of the origin's.
     */
    #[Computed]
    public function keepsOwnProtectionMode(): bool
    {
        return app(SharedOrigins::class)->keepsOwnProtectionMode($this->domain);
    }

    protected function fillOriginSettings(): void
    {
        $this->originUrl = $this->domain->origin_url;
        $this->hostHeaderMode = $this->domain->host_header_mode->value;
        $this->originHostHeader = (string) $this->domain->origin_host_header;
        $this->forwardedHostHeader = (string) $this->domain->forwarded_host_header;
        $this->serverHeader = (string) $this->domain->server_header;
        $this->hstsPolicy = $this->domain->hsts_policy->value;
        $this->cacheAssets = $this->domain->cache_assets;
        $this->servesWww = $this->domain->serves_www;
        $this->originTimeoutSeconds = $this->domain->origin_timeout_seconds;
        $this->protectionMode = $this->sharedOrigin !== null && ! $this->keepsOwnProtectionMode
            ? self::FOLLOWS_ORIGIN
            : $this->domain->protection_mode->value;
    }
}; ?>

<div>
    @php($canManage = auth()->user()->can('update', $domain))
    @php($isVerified = $domain->verified_at !== null)
    @php($isEdgeDnsLive = $domain->edge_dns_pointed_at !== null)
    @php($origin = $this->sharedOrigin)
    @php($hasPreview = $domain->preview_hostname !== null && PreviewHostname::enabled())

    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('domains.index')" wire:navigate>{{ __('Domains') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $domain->hostname }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <x-ntb.page-heading :overline="__('Domain origin')" :title="$domain->hostname">
        <x-slot:description>
            <span class="inline-flex flex-wrap items-center gap-2">
                <x-ntb.domain-status :status="$domain->status" />
                <span class="font-mono text-xs">→ {{ $domain->origin_url }}</span>
            </span>
        </x-slot:description>

        <x-slot:actions>
            <flux:button size="sm" icon="chart-bar" :href="route('activity.index', ['domain' => $domain->hostname])" wire:navigate>{{ __('Activity') }}</flux:button>

            @if ($canManage && $isVerified)
                <flux:button size="sm" :icon="$domain->status === DomainStatus::Paused ? 'play' : 'pause'" wire:click="togglePause" data-test="toggle-pause">
                    {{ $domain->status === DomainStatus::Paused ? __('Resume') : __('Pause') }}
                </flux:button>
            @endif
        </x-slot:actions>
    </x-ntb.page-heading>

    @unless ($isVerified)
        <flux:callout icon="sparkles" color="amber" class="mb-6">
            <flux:callout.heading>{{ __('Two quick steps and you\'re live.') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Add the TXT record to prove this domain is yours, then point DNS at the edge. Protection starts in observe mode, so nothing is blocked until you decide.') }}</flux:callout.text>
        </flux:callout>
    @endunless

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="flex min-w-0 flex-col gap-6 xl:col-span-2">
            <x-ntb.panel :heading="__('Go live')" :subheading="__('Prove it\'s yours, route it through the edge, and lock the back door.')">
                <ol class="space-y-6">
                    <li class="flex gap-4">
                        <span @class([
                            'flex size-8 shrink-0 items-center justify-center rounded-full font-mono text-xs font-bold',
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' => $isVerified,
                            'bg-ink text-white dark:bg-coral-500 dark:text-ink' => ! $isVerified,
                        ])>
                            @if ($isVerified)
                                <flux:icon.check variant="micro" class="size-4" />
                            @else
                                1
                            @endif
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-sm font-semibold">{{ __('Verify ownership') }}</h3>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                @if ($isVerified)
                                    {{ __('Verified :date. You can remove the TXT record whenever you like.', ['date' => $domain->verified_at->toFormattedDateString()]) }}
                                @else
                                    {{ __('Add this TXT record at your DNS provider, then check it here.') }}
                                @endif
                            </p>

                            @unless ($isVerified)
                                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                    <x-ntb.copy-field :label="__('TXT name')" :value="$domain->verificationRecordName()" />
                                    <x-ntb.copy-field :label="__('TXT value')" :value="$domain->verificationRecordValue()" />
                                </div>

                                @if ($canManage)
                                    <div class="mt-3 flex flex-wrap items-center gap-3" @if ($isCheckingTxtRecord) wire:poll.2s="pollTxtRecord" @endif>
                                        <flux:button size="sm" variant="primary" icon="arrow-path" wire:click="verify" :disabled="$isCheckingTxtRecord" data-test="verify-domain">{{ __('Check TXT record') }}</flux:button>
                                        @if ($isCheckingTxtRecord)
                                            <span class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400" data-test="txt-record-checking">
                                                <flux:icon.loading class="size-3.5" />
                                                {{ __('Looking up the TXT record…') }}
                                            </span>
                                        @elseif ($txtRecordProblem)
                                            <span class="text-xs text-amber-700 dark:text-amber-300" data-test="txt-record-problem">{{ $txtRecordProblem }}</span>
                                        @elseif (! config('nicetry.verify_dns'))
                                            <span class="text-xs text-zinc-400">{{ __('DNS checks are off on this installation, so every check passes.') }}</span>
                                        @endif
                                    </div>
                                @endif
                            @endunless
                        </div>
                    </li>

                    @if ($hasPreview)
                        <li class="flex gap-4" data-test="preview-step">
                            <span @class([
                                'flex size-8 shrink-0 items-center justify-center rounded-full font-mono text-xs font-bold',
                                'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' => $isEdgeDnsLive,
                                'bg-ink text-white dark:bg-coral-500 dark:text-ink' => $isVerified && ! $isEdgeDnsLive,
                                'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => ! $isVerified && ! $isEdgeDnsLive,
                            ])>
                                @if ($isEdgeDnsLive)
                                    <flux:icon.check variant="micro" class="size-4" />
                                @else
                                    2
                                @endif
                            </span>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-semibold">{{ __('Try it on the preview link') }}</h3>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    @if ($isVerified)
                                        {{ __('Your site through the edge, before you change any DNS. Your origin is asked for :hostname, so it serves exactly what visitors will get. The first visit can take a few seconds while its certificate is issued.', ['hostname' => $domain->hostname]) }}
                                    @else
                                        {{ __('Once ownership is verified, your site opens here through the edge, so you can try it before changing DNS.') }}
                                    @endif
                                </p>

                                <div class="mt-3 flex items-end gap-2">
                                    <x-ntb.copy-field class="flex-1" :label="__('Preview')" :value="'https://'.$domain->preview_hostname" data-test="preview-hostname" />

                                    @if ($isVerified)
                                        <flux:button size="sm" icon="arrow-top-right-on-square" :href="'https://'.$domain->preview_hostname" target="_blank" rel="noopener" data-test="open-preview">{{ __('Open') }}</flux:button>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endif

                    <li class="flex gap-4">
                        <span @class([
                            'flex size-8 shrink-0 items-center justify-center rounded-full font-mono text-xs font-bold',
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' => $isEdgeDnsLive,
                            'bg-ink text-white dark:bg-coral-500 dark:text-ink' => $isVerified && ! $isEdgeDnsLive,
                            'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => ! $isVerified && ! $isEdgeDnsLive,
                        ])>
                            @if ($isEdgeDnsLive)
                                <flux:icon.check variant="micro" class="size-4" />
                            @else
                                {{ $hasPreview ? 3 : 2 }}
                            @endif
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-sm font-semibold">{{ __('Point DNS at the edge') }}</h3>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                @if ($isEdgeDnsLive)
                                    {{ __('Pointed at the edge :date. Visitors now pass through NiceTryBot.', ['date' => $domain->edge_dns_pointed_at->toFormattedDateString()]) }}
                                @else
                                    {{ $domain->isApex()
                                        ? __('Apex domains can\'t use a CNAME, so add this A record. Delete any other A, AAAA, or ALIAS records for the domain first, or some visitors will skip the edge.')
                                        : __('Add this CNAME record in place of any existing records for the name. Lower the TTL first if you want a quick rollback.') }}
                                @endif
                            </p>

                            @unless ($isEdgeDnsLive)
                                <div class="mt-3 overflow-x-auto rounded-lg border border-line dark:border-zinc-800">
                                    <table class="w-full text-start text-xs">
                                        <thead class="bg-canvas font-mono text-[0.65rem] tracking-widest text-zinc-500 uppercase dark:bg-zinc-950 dark:text-zinc-400">
                                            <tr>
                                                <th class="px-3 py-2 text-start font-medium">{{ __('Type') }}</th>
                                                <th class="px-3 py-2 text-start font-medium">{{ __('Name') }}</th>
                                                <th class="px-3 py-2 text-start font-medium">{{ __('Value') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-line font-mono dark:divide-zinc-800">
                                            @if ($domain->isApex())
                                                @foreach (Domain::edgeAddresses() as $address)
                                                    <tr wire:key="a-record-{{ $loop->index }}">
                                                        <td class="px-3 py-2"><flux:badge size="sm">A</flux:badge></td>
                                                        <td class="px-3 py-2">@</td>
                                                        <td class="px-3 py-2">{{ $address }}</td>
                                                    </tr>
                                                @endforeach
                                            @else
                                                <tr>
                                                    <td class="px-3 py-2"><flux:badge size="sm">CNAME</flux:badge></td>
                                                    <td class="px-3 py-2">{{ $domain->hostname }}</td>
                                                    <td class="px-3 py-2">{{ Domain::edgeHostname() }}</td>
                                                </tr>
                                            @endif
                                            @if ($domain->servesWww())
                                                <tr data-test="www-cname">
                                                    <td class="px-3 py-2"><flux:badge size="sm">CNAME</flux:badge></td>
                                                    <td class="px-3 py-2">www.{{ $domain->hostname }}</td>
                                                    <td class="px-3 py-2">{{ Domain::edgeHostname() }}</td>
                                                </tr>
                                            @endif
                                        </tbody>
                                    </table>
                                </div>

                                <div class="mt-3 flex flex-wrap items-center gap-3">
                                    <flux:button size="sm" :variant="$isVerified ? 'primary' : 'filled'" icon="arrow-path" wire:click="checkEdgeDns" data-test="check-edge-dns">{{ __('Check DNS') }}</flux:button>

                                    @if ($edgeDnsSummary)
                                        <span @class([
                                            'inline-flex items-center gap-1.5 text-xs',
                                            'text-emerald-700 dark:text-emerald-300' => $isEdgeDnsPointed,
                                            'text-amber-700 dark:text-amber-300' => ! $isEdgeDnsPointed,
                                        ]) data-test="edge-dns-summary">
                                            @if ($isEdgeDnsPointed)
                                                <flux:icon.check-circle variant="micro" class="size-4 shrink-0" />
                                            @else
                                                <flux:icon.exclamation-triangle variant="micro" class="size-4 shrink-0" />
                                            @endif
                                            {{ $edgeDnsSummary }}
                                        </span>
                                    @endif
                                </div>
                            @endunless
                        </div>
                    </li>

                    <li class="flex gap-4">
                        <span @class([
                            'flex size-8 shrink-0 items-center justify-center rounded-full font-mono text-xs font-bold',
                            'bg-ink text-white dark:bg-coral-500 dark:text-ink' => $isVerified,
                            'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => ! $isVerified,
                        ])>{{ $hasPreview ? 4 : 3 }}</span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-sm font-semibold">{{ __('Lock the origin to the edge') }}</h3>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Every request from the edge carries this header. Have your origin reject requests without it, so scanners can\'t go around NiceTryBot.') }}</p>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <x-ntb.copy-field :label="__('Header')" :value="Domain::ORIGIN_SECRET_HEADER" />
                                <x-ntb.copy-field :label="__('Secret')" :value="$domain->origin_secret" secret />
                            </div>

                            @if ($canManage && ! $origin)
                                <flux:modal.trigger name="rotate-secret">
                                    <flux:button size="xs" variant="ghost" icon="key" class="mt-2">{{ __('Rotate secret') }}</flux:button>
                                </flux:modal.trigger>
                            @endif
                        </div>
                    </li>
                </ol>
            </x-ntb.panel>

            @if ($origin)
                <x-ntb.panel :heading="__('Origin settings')" :subheading="__('How the edge talks to your app.')">
                    <flux:callout icon="server-stack" color="zinc" data-test="managed-by-origin">
                        <flux:callout.heading>{{ __('Served by :name', ['name' => $origin->name]) }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __('This site shares its origin, headers, HSTS, asset caching and secret with every site of :name, and its protection mode unless it sets its own. Change them there.', ['name' => $origin->name]) }}
                        </flux:callout.text>
                        <x-slot name="actions">
                            <flux:button size="sm" :href="$origin->url" wire:navigate>{{ __('Open :name', ['name' => $origin->name]) }}</flux:button>
                        </x-slot>
                    </flux:callout>
                </x-ntb.panel>
            @else
                <x-ntb.panel :heading="__('Origin settings')" :subheading="__('How the edge talks to your app.')">
                    <form wire:submit="saveOriginSettings" class="space-y-5">
                        <fieldset @disabled(! $canManage) class="space-y-5">
                            <div class="grid gap-5 sm:grid-cols-[1fr_9rem]">
                                <flux:input wire:model="originUrl" :label="__('Origin URL')" icon="server-stack" spellcheck="false" data-test="origin-url" />
                                <flux:input wire:model="originTimeoutSeconds" :label="__('Timeout (seconds)')" type="number" min="5" max="120" />
                            </div>

                            <flux:radio.group wire:model.live="hostHeaderMode" :label="__('Host header')" variant="cards" class="max-sm:flex-col">
                                @foreach (HostHeaderMode::cases() as $mode)
                                    <flux:radio :value="$mode->value" :label="$mode->label()" :description="$mode->description()" />
                                @endforeach
                            </flux:radio.group>

                            @if ($hostHeaderMode === HostHeaderMode::Rewrite->value)
                                <flux:input wire:model="originHostHeader" :label="__('Host header sent to the origin')" :description="__('Leave blank to use the origin URL\'s host.')" spellcheck="false" />
                            @endif

                            <flux:input wire:model="forwardedHostHeader" :label="__('Forward the visitor\'s host in a header')" :description="__('Separate several with commas, like X-Domain, User-Custom-Domain. Up to :max.', ['max' => HeaderNames::MAX_HEADERS])" placeholder="User-Custom-Domain" spellcheck="false" data-test="forwarded-host-header" />

                            <div>
                                <flux:input wire:model="serverHeader" :label="__('Server header visitors see')" :description="__('What the edge answers as its server software, on every response. Blank keeps the default. Pretend to be something old and unpatched to make a honeypot more tempting.')" :placeholder="Domain::DEFAULT_SERVER_HEADER" list="server-header-suggestions" spellcheck="false" data-test="server-header" />
                                <datalist id="server-header-suggestions">
                                    @foreach (Domain::SERVER_HEADER_SUGGESTIONS as $suggestion)
                                        <option value="{{ $suggestion }}"></option>
                                    @endforeach
                                </datalist>
                            </div>

                            <flux:select wire:model.live="hstsPolicy" :label="__('HSTS')" :description="HstsPolicy::tryFrom($hstsPolicy)?->description()" data-test="hsts-policy">
                                @foreach (HstsPolicy::cases() as $policy)
                                    <flux:select.option :value="$policy->value">{{ $policy->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:checkbox wire:model="cacheAssets" :label="__('Let browsers cache /img, /css and /js')" :description="__('Images for a day, styles and scripts for a week. Leave it off if those files change without changing their names.')" data-test="cache-assets" />

                            @if (Domain::withoutWww($domain->hostname) === null)
                                <flux:checkbox wire:model="servesWww" :label="__('Serve www.:hostname too', ['hostname' => $domain->hostname])" :description="__('Same site, same settings, its traffic counted here. Point its DNS at the edge as well.')" data-test="serves-www" />
                            @endif
                        </fieldset>

                        @if ($canManage)
                            <div class="flex justify-end">
                                <flux:button type="submit" variant="primary" data-test="save-origin">{{ __('Save origin settings') }}</flux:button>
                            </div>
                        @endif
                    </form>
                </x-ntb.panel>
            @endif
        </div>

        <div class="flex min-w-0 flex-col gap-6">
            <x-ntb.panel :heading="__('Last 24 hours')">
                @if ($this->summary->isEmpty())
                    <div class="py-4 text-center">
                        <flux:icon.signal class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                        <p class="mt-3 text-sm font-medium">{{ __('No traffic yet') }}</p>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Requests appear here within a few seconds of DNS pointing at the edge.') }}</p>

                        @if ($canManage && ! $domain->edgeRequests()->exists())
                            <flux:button size="sm" class="mt-4" icon="sparkles" wire:click="loadSampleTraffic" data-test="load-sample-traffic">{{ __('Load sample traffic') }}</flux:button>
                        @endif
                    </div>
                @else
                    <dl class="mb-5 grid grid-cols-3 gap-3">
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Requests') }}</dt>
                            <dd class="font-mono text-lg font-semibold tabular-nums">{{ Number::abbreviate($this->summary->requests) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Suspicious') }}</dt>
                            <dd class="font-mono text-lg font-semibold text-coral-700 tabular-nums dark:text-coral-300">{{ $this->summary->suspiciousShare() }}%</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('404s') }}</dt>
                            <dd class="font-mono text-lg font-semibold tabular-nums">{{ Number::abbreviate($this->summary->notFound) }}</dd>
                        </div>
                    </dl>

                    <x-ntb.traffic-chart :buckets="$this->summary->buckets" height-class="h-32" />

                    <div class="mt-4 flex gap-4 text-xs">
                        <flux:link :href="route('activity.index', ['domain' => $domain->hostname])" wire:navigate>{{ __('Activity') }} →</flux:link>
                        <flux:link :href="route('not-found.index', ['domain' => $domain->hostname])" wire:navigate>{{ __('404s') }} →</flux:link>
                    </div>
                @endif
            </x-ntb.panel>

            <x-ntb.panel :heading="__('Protection mode')" :subheading="__('Every rule starts by watching. You decide when it acts.')">
                <flux:radio.group wire:model.live="protectionMode" variant="cards" class="flex-col" :disabled="! $canManage" data-test="protection-mode">
                    @if ($origin)
                        <flux:radio value="origin" :label="__('Same as :name', ['name' => $origin->name])" :description="__(':mode now. Changes when :name’s mode does.', ['mode' => $origin->protectionMode->label(), 'name' => $origin->name])" />
                    @endif
                    @foreach (ProtectionMode::cases() as $mode)
                        <flux:radio :value="$mode->value" :label="$mode->label()" :description="$mode->description()" />
                    @endforeach
                </flux:radio.group>

                <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                    @if ($origin)
                        {{ $this->keepsOwnProtectionMode
                            ? __('This site sets its own mode. The other sites of :name keep theirs.', ['name' => $origin->name])
                            : __('Set for every site on :name, unless a site picks its own.', ['name' => $origin->name]) }}
                    @endif
                    {{ __('If the edge can\'t reach NiceTryBot, it fails open and sends everyone to your origin.') }}
                </p>
            </x-ntb.panel>

            <x-ntb.panel :heading="__('How requests flow')">
                <div class="space-y-2 font-mono text-xs">
                    <div class="flex items-center gap-2 rounded-lg bg-canvas px-3 py-2 dark:bg-zinc-800/60">
                        <flux:icon.globe-americas variant="micro" class="size-4 text-zinc-400" />
                        <span class="truncate">{{ __('visitor') }} → {{ $domain->hostname }}</span>
                    </div>
                    <div class="ms-4 border-s-2 border-dashed border-zinc-200 py-1 ps-4 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">{{ Domain::edgeHostname() }} {{ __('tags the request') }}</div>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                            <p class="font-semibold">{{ __('Humans') }}</p>
                            <p class="mt-1 truncate opacity-80">Host: {{ $domain->originHostHeader() }}</p>
                            @foreach (HeaderName::split($domain->forwarded_host_header) as $forwardedHostHeader)
                                <p class="truncate opacity-80" wire:key="forwarded-host-{{ $loop->index }}">{{ $forwardedHostHeader }}: {{ $domain->hostname }}</p>
                            @endforeach
                        </div>
                        <div class="rounded-lg border border-coral-200 bg-coral-50 px-3 py-2 text-coral-800 dark:border-coral-500/30 dark:bg-coral-500/10 dark:text-coral-300">
                            <p class="font-semibold">{{ __('Bots') }}</p>
                            <p class="mt-1 opacity-80">
                                {{ match ($domain->protection_mode) {
                                    ProtectionMode::Enforce => __('→ the gym'),
                                    ProtectionMode::Observe => __('→ origin, tagged'),
                                    ProtectionMode::Off => __('→ origin'),
                                } }}
                            </p>
                        </div>
                    </div>
                </div>
            </x-ntb.panel>

            {{-- Add-ons' panels for the domain, from App\Support\ViewSlots. --}}
            {{ app(ViewSlots::class)->render(ViewSlot::DomainPanels, ['domain' => $domain]) }}

            @can('delete', $domain)
                <x-ntb.panel :heading="__('Remove domain')" :subheading="__('Stops protection and deletes this domain\'s request history. Point DNS back at your origin first.')">
                    <flux:modal.trigger name="delete-domain">
                        <flux:button size="sm" variant="danger" icon="trash" data-test="open-delete-domain">{{ __('Remove :hostname', ['hostname' => $domain->hostname]) }}</flux:button>
                    </flux:modal.trigger>
                </x-ntb.panel>
            @endcan
        </div>
    </div>

    <flux:modal name="rotate-secret" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Rotate the origin secret?') }}</flux:heading>
                <flux:subheading>{{ __('The edge starts sending the new secret right away. Requests will fail at your origin until it accepts the new value.') }}</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="rotateOriginSecret">{{ __('Rotate secret') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="delete-domain" class="max-w-lg">
        <form wire:submit="deleteDomain" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Remove :hostname?', ['hostname' => $domain->hostname]) }}</flux:heading>
                <flux:subheading>{{ __('Its request history is deleted too. This can\'t be undone.') }}</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="confirm-delete-domain">{{ __('Remove domain') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
