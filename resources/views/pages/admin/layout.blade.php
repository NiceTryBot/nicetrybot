<div class="relative mb-6 w-full">
    <flux:heading size="xl" level="1">{{ __('Admin') }}</flux:heading>
    <flux:subheading size="lg" class="mb-6">{{ __('Run this installation: its add-ons, who administers it and the tokens AI agents use, across every team') }}</flux:subheading>
    <flux:separator variant="subtle" />
</div>

@if ($newerRelease = \App\Support\Releases::newer())
    <div class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-500/40 dark:bg-amber-500/10" data-test="update-available">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <p class="font-medium text-ink dark:text-white">{{ __('NiceTryBot :version is out', ['version' => $newerRelease['version']]) }}</p>
            <flux:spacer />
            <flux:link :href="$newerRelease['url']" target="_blank" rel="noopener" class="text-xs">{{ __('What\'s new') }}</flux:link>
        </div>
        <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">{{ __('This panel runs :current. Update it on the server; the database is backed up first, and nicetrybot rollback undoes it.', ['current' => \App\Support\Releases::current()]) }}</p>
        <x-ntb.copy-field class="mt-3 max-w-sm" :label="__('On the server')" value="nicetrybot update" />
    </div>
@endif

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
