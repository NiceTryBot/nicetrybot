<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-line bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <livewire:team-switcher />

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Your workspace')" class="grid">
                    {{-- The core's pages and every add-on's, from App\Support\Nav. --}}
                    @foreach (app(\App\Support\Nav::class)->items() as $item)
                        @php($badge = $item->badge === null ? null : ($item->badge)())
                        <flux:sidebar.item :icon="$item->icon" :href="route($item->route)" :current="request()->routeIs(...$item->currentPatterns())" :badge="$badge" :badge:color="$item->badgeColor" wire:navigate wire:key="nav-{{ $item->route }}" :data-test="$item->test">
                            {{ __($item->label) }}@if ($item->ai)<x-ntb.ai-sparkle />@endif
                        </flux:sidebar.item>
                    @endforeach
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('Coming soon')" class="grid">
                    @foreach ([
                        ['icon' => 'code-bracket', 'label' => __('Integration')],
                    ] as $upcoming)
                        <flux:sidebar.item :icon="$upcoming['icon']" badge="{{ __('Soon') }}" aria-disabled="true" class="pointer-events-none opacity-55" wire:key="upcoming-{{ $loop->index }}">
                            {{ $upcoming['label'] }}
                        </flux:sidebar.item>
                    @endforeach
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <div class="hidden rounded-lg bg-canvas p-3 lg:block dark:bg-zinc-800/60">
                <img src="{{ asset('images/bigfoot.png') }}" alt="{{ __('A cheerful Bigfoot chasing the NiceTryBot robot') }}" class="mb-2 w-full rounded-md mix-blend-multiply dark:mix-blend-lighten dark:invert dark:hue-rotate-180" />
                <p class="text-sm font-semibold">{{ __('Keep the bots busy.') }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Your humans have things to do.') }}</p>
            </div>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                        @can('administer')
                            <flux:menu.item :href="route('admin.addons')" icon="shield-check" wire:navigate data-test="admin-link">
                                {{ __('Admin') }}
                            </flux:menu.item>
                        @endcan
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        {{-- Add-ons' scripts, data blocks and modals, from App\Support\ViewSlots. --}}
        {{ app(\App\Support\ViewSlots::class)->render(\App\Enums\ViewSlot::BodyEnd) }}

        <livewire:create-team-modal />

        <livewire:add-domain-modal />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
