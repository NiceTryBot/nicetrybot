@props([
    'placeholder' => __('All domains'),
    'allowAll' => true,
])

{{-- Picks one of the team's domains by hostname, looked up as you type (the page uses App\Concerns\SearchesDomains)
     rather than listing every domain in the page. Bind it like an input: wire:model="domain" or wire:model.live="domain". --}}
<div
    x-data="{
        value: '',
        open: false,
        query: '',
        results: [],
        loading: false,
        active: -1,
        async search() {
            this.loading = true;
            const query = this.query;
            const results = await $wire.searchDomains(query);

            if (query === this.query) {
                this.results = results;
                this.active = -1;
                this.loading = false;
            }
        },
        toggle() {
            this.open = ! this.open;

            if (this.open) {
                this.search();
                this.$nextTick(() => this.$refs.query.focus());
            }
        },
        pick(hostname) {
            this.value = hostname;
            this.open = false;
            this.query = '';
        },
        move(step) {
            this.active = Math.max(-1, Math.min(this.results.length - 1, this.active + step));
        },
        enter() {
            if (this.active >= 0) {
                this.pick(this.results[this.active]);
            } else if (this.results.length > 0 && this.query !== '') {
                this.pick(this.results[0]);
            }
        },
    }"
    x-modelable="value"
    x-on:click.outside="open = false"
    x-on:keydown.escape.stop="open = false"
    {{ $attributes->class('relative') }}
    data-test="domain-picker"
>
    <button
        type="button"
        x-on:click="toggle()"
        x-bind:aria-expanded="open"
        aria-haspopup="listbox"
        class="flex h-8 w-full items-center justify-between gap-2 rounded-lg border border-zinc-200 border-b-zinc-300/80 bg-white px-3 text-start text-sm text-zinc-700 shadow-xs dark:border-white/10 dark:bg-white/10 dark:text-zinc-300"
    >
        <span class="truncate" x-text="value || @js($placeholder)">{{ $placeholder }}</span>
        <flux:icon.chevron-up-down variant="micro" class="size-4 shrink-0 text-zinc-400" />
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.origin.top
        class="absolute left-0 z-40 mt-1 w-[min(20rem,calc(100vw-2rem))] overflow-hidden rounded-lg border border-line bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
    >
        <div class="border-b border-line p-2 dark:border-zinc-800">
            <input
                x-ref="query"
                x-model="query"
                x-on:input.debounce.200ms="search()"
                x-on:keydown.down.prevent="move(1)"
                x-on:keydown.up.prevent="move(-1)"
                x-on:keydown.enter.prevent="enter()"
                type="search"
                autocomplete="off"
                spellcheck="false"
                placeholder="{{ __('Find a domain') }}"
                aria-label="{{ __('Find a domain') }}"
                class="w-full rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-sm outline-none focus:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-800"
                data-test="domain-picker-query"
            />
        </div>
        <ul class="max-h-72 overflow-y-auto py-1 text-sm" role="listbox">
            @if ($allowAll)
                <li x-show="query === ''">
                    <button type="button" x-on:click="pick('')" class="block w-full px-3 py-1.5 text-start text-zinc-500 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:bg-zinc-800">{{ $placeholder }}</button>
                </li>
            @endif
            <template x-for="(hostname, index) in results" :key="hostname">
                <li>
                    <button
                        type="button"
                        x-on:click="pick(hostname)"
                        x-on:mouseenter="active = index"
                        x-text="hostname"
                        x-bind:class="{ 'bg-zinc-100 dark:bg-zinc-800': active === index, 'font-medium text-ink dark:text-white': hostname === value }"
                        class="block w-full truncate px-3 py-1.5 text-start text-zinc-700 dark:text-zinc-300"
                        data-test="domain-picker-option"
                    ></button>
                </li>
            </template>
            <li x-show="loading && results.length === 0" class="px-3 py-1.5 text-zinc-400">{{ __('Looking…') }}</li>
            <li x-show="! loading && results.length === 0" class="px-3 py-1.5 text-zinc-400">{{ __('No domain matches.') }}</li>
        </ul>
    </div>
</div>
