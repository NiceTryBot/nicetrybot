<?php

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;

function registerAs(string $email): TestResponse
{
    return test()->post(route('register.store'), [
        'name' => 'New Person',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
}

describe('invitations-only registration', function () {
    beforeEach(fn () => config(['nicetry.registration' => 'invitations']));

    test('an email nobody invited cannot make an account', function () {
        registerAs('stranger@example.com')->assertSessionHasErrors(['email' => 'Accounts here are by invitation. Ask a team admin to invite this email address.']);

        expect(User::count())->toBe(0);
        $this->assertGuest();
    });

    test('an invited email can, whatever its letter case', function () {
        TeamInvitation::factory()->create(['email' => 'Invited@Example.com']);

        registerAs('invited@example.com')->assertSessionHasNoErrors();

        expect(User::where('email', 'invited@example.com')->exists())->toBeTrue();
        $this->assertAuthenticated();
    });

    test('an expired or accepted invitation does not count', function () {
        TeamInvitation::factory()->expired()->create(['email' => 'late@example.com']);
        TeamInvitation::factory()->accepted()->create(['email' => 'used@example.com']);

        registerAs('late@example.com')->assertSessionHasErrors('email');
        registerAs('used@example.com')->assertSessionHasErrors('email');
    });

    test('the login page offers sign-up only to someone an invitation brought', function () {
        $this->get(route('login'))->assertOk()->assertDontSee('data-test="register-link"', false);

        $invitation = TeamInvitation::factory()->create(['email' => 'invited@example.com']);

        $this->get(route('login', ['invitation' => $invitation->code]))->assertOk()->assertSee('data-test="register-link"', false);
    });

    test('the sign-up page says accounts are by invitation', function () {
        $this->get(route('register'))->assertOk()->assertSee('Accounts here are by invitation.');
    });

    test('the welcome page has no Get started button', function () {
        $this->get(route('home'))->assertOk()->assertDontSee('Get started');
    });
});

test('open registration is the default and unchanged', function () {
    expect(config('nicetry.registration'))->toBe('open');

    $this->get(route('login'))->assertSee('data-test="register-link"', false);

    registerAs('anyone@example.com')->assertSessionHasNoErrors();
});

test('closed registration leaves Fortify without its sign-up feature', function () {
    $previous = $_SERVER['NICETRY_REGISTRATION'] ?? null;

    try {
        $_SERVER['NICETRY_REGISTRATION'] = 'closed';
        expect((require config_path('fortify.php'))['features'])->not->toContain(Features::registration());

        $_SERVER['NICETRY_REGISTRATION'] = 'invitations';
        expect((require config_path('fortify.php'))['features'])->toContain(Features::registration());
    } finally {
        if ($previous === null) {
            unset($_SERVER['NICETRY_REGISTRATION']);
        } else {
            $_SERVER['NICETRY_REGISTRATION'] = $previous;
        }
    }
});

test('the self-host stack defaults to invitations', function () {
    $compose = file_get_contents(base_path('deploy/compose.yml'));
    $env = file_get_contents(base_path('deploy/.env.example'));

    expect($compose)->toContain('NICETRY_REGISTRATION: ${NICETRY_REGISTRATION:-invitations}')
        ->and($env)->toContain("NICETRY_REGISTRATION=invitations\n");
});
