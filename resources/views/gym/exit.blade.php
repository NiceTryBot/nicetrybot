@php
    use App\Enums\GymExit;
    use App\Gym\HashPuzzle;
    use Illuminate\Support\Number;

    /** @var \App\Models\GymSession $session */
    /** @var \App\Gym\GymPlan $plan */
    $banner = $session->is_preview ? __('Preview. This walk through the gym doesn’t count as a visit.') : null;
@endphp

{{-- Only a visitor turned away sees the contact: a not-found exit stays a plain 404 to a scanner. --}}
<x-layouts::gym :title="match ($exit) { GymExit::NotFound => __('Not found'), GymExit::Block => __('Forbidden'), default => __('All done') }" :banner="$banner" :help="$exit === GymExit::Block ? $session->visitorHelp() : null">
    <div class="w-full max-w-md" data-test="gym-exit" data-exit="{{ $exit->value }}">
        @if ($exit === GymExit::Continue)
            <div class="rounded-2xl border border-line bg-white p-8 text-center shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mx-auto mb-5 flex size-14 items-center justify-center rounded-full bg-emerald-500/10">
                    <svg class="size-7 text-emerald-600 dark:text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </div>
                <h1 class="text-xl font-semibold tracking-tight">{{ __('You made it through') }}</h1>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('A real visitor lands on their page now, with a pass to skip the gym for :time.', ['time' => \Carbon\CarbonInterval::seconds($plan->passSeconds)->cascade()->forHumans()]) }}
                </p>
            </div>
        @else
            <div class="py-6 text-center">
                <p class="font-mono text-6xl font-bold tracking-tight text-zinc-300 dark:text-zinc-700">{{ $exit->status() }}</p>
                <h1 class="mt-3 text-lg font-semibold tracking-tight">
                    {{ $exit === GymExit::NotFound ? __('This page could not be found.') : __('There was a problem with your request.') }}
                </h1>
            </div>
        @endif

        @if ($session->is_preview)
            <div class="mt-4 rounded-xl border border-dashed border-line p-4 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400" data-test="gym-preview-summary">
                <p class="font-medium text-ink dark:text-zinc-100">{{ __('How it went') }}</p>
                <dl class="mt-2 grid grid-cols-3 gap-2">
                    <div><dt>{{ __('Obstacles') }}</dt><dd class="font-mono text-sm text-ink dark:text-zinc-100">{{ $session->clearedCount() }}</dd></div>
                    <div><dt>{{ __('Time') }}</dt><dd class="font-mono text-sm text-ink dark:text-zinc-100">{{ HashPuzzle::describeSeconds($session->secondsInGym()) }}</dd></div>
                    <div><dt>{{ __('Hashes') }}</dt><dd class="font-mono text-sm text-ink dark:text-zinc-100">{{ Number::abbreviate($session->hashes, maxPrecision: 1) }}</dd></div>
                </dl>
                @php
                    $flags = collect($session->progress)->flatMap(fn (array $state) => $state['flags'] ?? []);
                @endphp
                @if ($flags->isNotEmpty())
                    <p class="mt-3 font-medium text-amber-700 dark:text-amber-300">{{ __('Flagged as automated') }}</p>
                    <ul class="mt-1 list-inside list-disc">
                        @foreach ($flags as $flag)
                            <li>{{ $flag }}</li>
                        @endforeach
                    </ul>
                @endif
                <p class="mt-3">{{ __('You can close this tab.') }}</p>
            </div>
        @endif
    </div>
</x-layouts::gym>
