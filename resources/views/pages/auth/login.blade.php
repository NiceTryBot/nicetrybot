<x-layouts::auth
    :title="__('Log in')"
    art="auth-door"
    :art-alt="__('Bigfoot holds a door open for you and points a robot in a fake moustache towards the treadmill')"
    :line="__('The door is open for you. The bots get the treadmill.')"
    :note="__('Log in to see who is knocking on your sites, tune your rules and check on the gym.')"
>
    <div class="flex flex-col gap-6">
        <x-auth-header :eyebrow="__('Log in')" :title="__('Welcome back')" :description="__('Log in with the email address and password you used to make your account.')" />

        <!-- Session Status -->
        <x-auth-session-status :status="session('status')" />

        @if ($teamInvitation)
            <x-team-invitation-alert :invitation="$teamInvitation" :action="__('Log in')" />
        @endif

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="you@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Your password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 end-0 text-sm" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot it?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox
                name="remember"
                :label="__('Remember me')"
                :description="__('Stay logged in on this browser. Leave it off on a shared computer.')"
                :checked="old('remember')"
            />

            <flux:button variant="primary" type="submit" class="mt-1 w-full" data-test="login-button">
                {{ __('Log in') }}
            </flux:button>
        </form>

        {{-- Sign-up is offered when it's open, or on an invitations-only panel when an invitation brought them here. --}}
        @if (Route::has('register') && (config('nicetry.registration') === 'open' || $teamInvitation))
            <p class="border-t border-line pt-6 text-sm text-zinc-600 dark:border-zinc-800 dark:text-zinc-400">
                {{ __('New here?') }}
                <flux:link
                    :href="$teamInvitation ? route('register', ['invitation' => $teamInvitation['code']]) : route('register')"
                    data-test="register-link"
                    wire:navigate
                >
                    {{ __('Create an account') }}
                </flux:link>
            </p>
        @endif
    </div>
</x-layouts::auth>
