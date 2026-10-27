<x-layouts::auth
    :title="__('Create an account')"
    art="gym"
    :art-alt="__('Robots sweat on a treadmill, in a hamster wheel, under a barbell and on a skipping rope while Bigfoot times them')"
    :line="__('People walk straight in. Bots get a workout.')"
    :note="__('Point your sites at NiceTryBot and watch who knocks, who passes and who is still on the treadmill.')"
>
    <div class="flex flex-col gap-6">
        <x-auth-header :eyebrow="__('Create an account')" :title="__('Make your account')" :description="__('It takes about a minute. Your sites come next.')" />

        @if ($teamInvitation)
            <x-team-invitation-alert :invitation="$teamInvitation" :action="__('Register')" />
        @elseif (config('nicetry.registration') === 'invitations')
            <flux:callout icon="envelope" data-test="invitations-only">
                <flux:callout.text>{{ __('Accounts here are by invitation. Use the email address a team admin invited.') }}</flux:callout.text>
            </flux:callout>
        @else
            <ul class="grid gap-2 rounded-2xl border border-line bg-canvas p-4 text-sm dark:border-zinc-800 dark:bg-zinc-950">
                @foreach ([
                    __('See every bot that knocks on your sites.'),
                    __('Write rules that ban, slow down or test them.'),
                    __('Send the doubtful ones to the gym instead of blocking them.'),
                ] as $perk)
                    <li class="flex gap-2">
                        <flux:icon.check variant="mini" class="mt-0.5 size-4 shrink-0 text-coral-600 dark:text-coral-400" />
                        <span>{{ $perk }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <!-- Session Status -->
        <x-auth-session-status :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-5">
            @csrf
            {{-- Hints are plain paragraphs: Flux's description:trailing escapes apostrophes twice. --}}
            <!-- Name -->
            <div class="grid gap-2">
                <flux:input
                    name="name"
                    :label="__('Name')"
                    :value="old('name')"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                    :placeholder="__('Your name')"
                    aria-describedby="name-hint"
                />
                <p id="name-hint" class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('So your team knows who you are.') }}</p>
            </div>

            <!-- Email Address -->
            <div class="grid gap-2">
                <flux:input
                    name="email"
                    :label="__('Email address')"
                    :value="old('email')"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="you@example.com"
                    aria-describedby="email-hint"
                />
                <p id="email-hint" class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('You\'ll use it to log in.') }}</p>
            </div>

            <!-- Password -->
            <div class="grid gap-2">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="new-password"
                    :placeholder="__('Password')"
                    passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                    viewable
                    aria-describedby="password-hint"
                />
                <p id="password-hint" class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Pick one you don\'t use anywhere else. A password manager helps.') }}</p>
            </div>

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Type it again')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:button type="submit" variant="primary" class="mt-1 w-full" data-test="register-user-button">
                {{ __('Create account') }}
            </flux:button>
        </form>

        <p class="border-t border-line pt-6 text-sm text-zinc-600 dark:border-zinc-800 dark:text-zinc-400">
            {{ __('Already have an account?') }}
            <flux:link
                :href="$teamInvitation ? route('login', ['invitation' => $teamInvitation['code']]) : route('login')"
                data-test="team-invitation-login-link"
                wire:navigate
            >
                {{ __('Log in') }}
            </flux:link>
        </p>
    </div>
</x-layouts::auth>
