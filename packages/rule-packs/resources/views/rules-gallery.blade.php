{{-- Below the Rules page's template gallery: the way to the packs, with how many the team has. --}}
@php
    $packs = app(\NiceTry\RulePacks\RulePacks::class)->all();
    $installedVersions = \NiceTry\RulePacks\Models\RulePackInstall::query()
        ->where('team_id', $team->id)
        ->toBase()
        ->selectRaw('pack, min(version) as version')
        ->groupBy('pack')
        ->pluck('version', 'pack');
    $installedCount = collect($packs)->filter(fn ($pack) => $installedVersions->has($pack->key()))->count();
    $updateCount = collect($packs)->filter(fn ($pack) => $installedVersions->has($pack->key()) && (int) $installedVersions[$pack->key()] < $pack->version())->count();
@endphp

<div class="pt-2" data-test="rule-packs-gallery">
    <p class="mb-2 font-mono text-[0.65rem] tracking-[0.14em] text-zinc-500 uppercase dark:text-zinc-400">{{ __('Or install a pack') }}</p>
    <a href="{{ route('rules.packs') }}" wire:navigate class="flex items-start gap-3 rounded-lg border border-line px-3 py-2 transition hover:border-coral-400 dark:border-zinc-800 dark:hover:border-coral-500" data-test="rule-packs-link">
        <flux:icon.rectangle-stack variant="mini" class="mt-0.5 size-4 shrink-0 text-coral-500" />
        <span class="min-w-0 flex-1">
            <span class="flex flex-wrap items-center gap-1.5 text-sm font-medium">
                {{ __('Rule packs') }}
                @if ($updateCount > 0)
                    <flux:badge size="sm" color="amber">{{ trans_choice(':count update|:count updates', $updateCount) }}</flux:badge>
                @elseif ($installedCount > 0)
                    <flux:badge size="sm">{{ __(':count of :total installed', ['count' => $installedCount, 'total' => count($packs)]) }}</flux:badge>
                @endif
            </span>
            <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ __('Curated sets of rules and gym routines, installed in one go to watch before they act.') }}</span>
        </span>
    </a>
</div>
