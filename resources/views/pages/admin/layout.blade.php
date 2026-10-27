<div class="relative mb-6 w-full">
    <flux:heading size="xl" level="1">{{ __('Admin') }}</flux:heading>
    <flux:subheading size="lg" class="mb-6">{{ __('Run this installation: its add-ons, who administers it and the tokens AI agents use, across every team') }}</flux:subheading>
    <flux:separator variant="subtle" />
</div>

<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Admin') }}">
            <flux:navlist.item :href="route('admin.addons')" wire:navigate>{{ __('Add-ons') }}</flux:navlist.item>
            <flux:navlist.item :href="route('admin.administrators')" wire:navigate>{{ __('Administrators') }}</flux:navlist.item>
            <flux:navlist.item :href="route('admin.tokens')" wire:navigate>{{ __('API tokens') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-2xl">
            {{ $slot }}
        </div>
    </div>
</div>
