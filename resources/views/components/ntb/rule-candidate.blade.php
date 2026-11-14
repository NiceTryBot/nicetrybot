@props(['filter', 'candidate', 'canManage' => false])

{{-- A rule a Rules page filter stands for that the team doesn't have yet (App\Support\RuleFilterCandidate). --}}
<div wire:key="candidate-{{ $filter }}-{{ $candidate->key }}" class="rounded-lg border border-dashed border-line bg-white/60 p-3 dark:border-zinc-700 dark:bg-zinc-900/60" title="{{ $candidate->source }}" data-test="rule-candidate">
    <p class="text-sm font-medium break-words text-ink dark:text-white">{{ $candidate->name }}</p>
    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $candidate->blurb }}</p>
    @if ($candidate->destination)
        <p class="mt-2 flex items-start gap-1 text-xs font-medium" data-test="rule-candidate-destination">
            <flux:icon.fire variant="micro" class="mt-0.5 size-3 shrink-0" />
            <span class="min-w-0 break-words">{{ $candidate->destination }}</span>
        </p>
    @endif

    @if ($candidate->unavailable)
        <p class="mt-2 text-xs text-amber-700 dark:text-amber-300" data-test="rule-candidate-unavailable">{{ $candidate->unavailable }}</p>
    @elseif ($canManage)
        <flux:button size="xs" icon="plus" class="mt-2" wire:click="addFromFilter('{{ $filter }}', '{{ $candidate->key }}')" :wire:confirm="$candidate->confirm" data-test="rule-candidate-install">{{ __('Install rule') }}</flux:button>
    @endif
</div>
