<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            {{-- The core's settings and every add-on's, from App\Support\SettingsNav. --}}
            @foreach (app(\App\Support\SettingsNav::class)->items() as $item)
                <flux:navlist.item :href="route($item->route)" :current="$item->isCurrent()" wire:navigate wire:key="settings-nav-{{ $item->route }}">{{ __($item->label) }}</flux:navlist.item>
            @endforeach
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
