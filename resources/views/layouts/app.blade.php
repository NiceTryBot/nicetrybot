<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="relative">
        {{-- Add-ons' notices across the top of the page, from App\Support\ViewSlots. --}}
        {{ app(\App\Support\ViewSlots::class)->render(\App\Enums\ViewSlot::AppBanner) }}

        <div class="absolute end-4 top-4 z-10 flex items-center gap-2 lg:end-6 lg:top-5">
            {{-- Add-ons' buttons go before the cog, from App\Support\ViewSlots. --}}
            {{ app(\App\Support\ViewSlots::class)->render(\App\Enums\ViewSlot::TopbarActions) }}
            <x-ntb.workspace-menu />
        </div>

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
