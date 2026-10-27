{{-- The cog at the top right: the team's sites and its settings for protecting them and their visitors, the core's and every add-on's, from App\Support\WorkspaceMenu. A dot shows on the cog while an item that asks for one has a badge, so an emergency switch that's on stays in sight. --}}
@php
    $items = collect(app(\App\Support\WorkspaceMenu::class)->items())->map(fn (\App\Support\WorkspaceMenuItem $item) => [
        'item' => $item,
        'badge' => $item->badge === null ? null : ($item->badge)(),
    ]);
    $alerting = $items->contains(fn (array $entry) => $entry['item']->alert && $entry['badge'] !== null);
@endphp

<flux:dropdown position="bottom" align="end" {{ $attributes }}>
    <button
        type="button"
        class="relative inline-flex size-8 items-center justify-center rounded-lg border border-line bg-white text-zinc-500 transition hover:text-ink dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:text-white"
        aria-label="{{ __('Workspace settings') }}"
        title="{{ __('Workspace settings') }}"
        data-test="workspace-menu"
    >
        <flux:icon.cog-6-tooth variant="micro" class="size-4" />
        @if ($alerting)
            <span class="absolute -end-0.5 -top-0.5 size-2 rounded-full bg-rose-500 ring-2 ring-white dark:ring-zinc-900" data-test="workspace-menu-alert"></span>
        @endif
    </button>

    <flux:menu class="min-w-48">
        @foreach ($items as ['item' => $item, 'badge' => $badge])
            <flux:menu.item :icon="$item->icon" :href="route($item->route)" wire:navigate :data-test="$item->test">
                {{ __($item->label) }}
                @if ($badge !== null)
                    <flux:badge size="sm" :color="$item->badgeColor" class="ms-auto">{{ $badge }}</flux:badge>
                @endif
            </flux:menu.item>
        @endforeach
    </flux:menu>
</flux:dropdown>
