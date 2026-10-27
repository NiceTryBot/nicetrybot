<?php

use App\Models\Administrator;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config([
        'app.url' => 'https://panel.example.com',
        'edge.panel_host' => 'panel.example.com',
        'edge.hostname' => 'panel.example.com',
        'edge.addresses' => ['192.0.2.10'],
    ]);
});

test('it creates the first administrator, verified, with a personal team', function () {
    $this->artisan('nicetry:install', ['--email' => 'Ops@Example.com', '--password' => 'correct horse battery', '--no-interaction' => true])
        ->expectsOutput('Created the first administrator: ops@example.com.')
        ->doesntExpectOutputToContain('Their password is')
        ->assertSuccessful();

    $user = User::sole();

    expect($user)
        ->email->toBe('ops@example.com')
        ->name->toBe('Ops')
        ->email_verified_at->not->toBeNull()
        ->isAdministrator()->toBeTrue()
        ->and(Hash::check('correct horse battery', $user->password))->toBeTrue()
        ->and($user->currentTeam)->is_personal->toBeTrue()->name->toBe("Ops's Team")
        ->and($user->ownsTeam($user->currentTeam))->toBeTrue();
});

test('it shows the panel and the DNS records that point at the edge', function () {
    $this->artisan('nicetry:install', ['--email' => 'ops@example.com', '--no-interaction' => true])
        ->expectsOutputToContain('The panel: https://panel.example.com')
        ->expectsTable(['Type', 'Name', 'Value'], [
            ['A', 'panel.example.com', '192.0.2.10'],
            ['CNAME', 'each subdomain you protect, like shop.example.com', 'panel.example.com'],
            ['A', 'each apex domain you protect, like example.com', '192.0.2.10'],
        ])
        ->assertSuccessful();
});

test('an edge hostname of its own gets its own record', function () {
    config(['edge.hostname' => 'edge.example.com', 'edge.addresses' => ['192.0.2.10', '192.0.2.11']]);

    $this->artisan('nicetry:install', ['--email' => 'ops@example.com', '--no-interaction' => true])
        ->expectsTable(['Type', 'Name', 'Value'], [
            ['A', 'panel.example.com', '192.0.2.10'],
            ['A', 'panel.example.com', '192.0.2.11'],
            ['A', 'edge.example.com', '192.0.2.10'],
            ['A', 'edge.example.com', '192.0.2.11'],
            ['CNAME', 'each subdomain you protect, like shop.example.com', 'edge.example.com'],
            ['A', 'each apex domain you protect, like example.com', '192.0.2.10'],
            ['A', 'each apex domain you protect, like example.com', '192.0.2.11'],
        ])
        ->assertSuccessful();
});

test('it warns when there is no address to give', function () {
    config(['edge.addresses' => []]);

    $this->artisan('nicetry:install', ['--email' => 'ops@example.com', '--no-interaction' => true])
        ->expectsOutputToContain('NICETRY_EDGE_ADDRESSES is empty')
        ->assertSuccessful();
});

test('without a password, it makes a random one and shows it once', function () {
    expect(Artisan::call('nicetry:install', ['--email' => 'ops@example.com', '--no-interaction' => true]))->toBe(0);

    $output = Artisan::output();
    preg_match('/Their password is (\S+)/', $output, $matches);

    expect($output)->toContain('shown only this once')
        ->and($matches[1] ?? '')->toMatch('/^[A-Za-z0-9]{24}$/')
        ->and(Hash::check($matches[1], User::sole()->password))->toBeTrue();
});

test('it asks for the email and password when they are left out', function () {
    $this->artisan('nicetry:install')
        ->expectsQuestion('Email address for the first administrator', 'ops@example.com')
        ->expectsQuestion('Password for the first administrator', 'a long enough password')
        ->expectsOutput('Created the first administrator: ops@example.com.')
        ->assertSuccessful();

    expect(Hash::check('a long enough password', User::sole()->password))->toBeTrue();
});

test('an empty password at the prompt makes a random one', function () {
    $this->artisan('nicetry:install')
        ->expectsQuestion('Email address for the first administrator', 'ops@example.com')
        ->expectsQuestion('Password for the first administrator', '')
        ->expectsOutputToContain('Their password is')
        ->assertSuccessful();

    expect(User::sole()->isAdministrator())->toBeTrue();
});

test('it does nothing, and says so, once there is an administrator', function () {
    $admin = Administrator::factory()->create()->user;

    $this->artisan('nicetry:install', ['--email' => 'ops@example.com', '--password' => 'correct horse battery', '--no-interaction' => true])
        ->expectsOutput("NiceTryBot is already installed: {$admin->email} is an administrator. Nothing was changed.")
        ->expectsOutputToContain('The panel: https://panel.example.com')
        ->assertSuccessful();

    expect(User::count())->toBe(1)
        ->and(Administrator::count())->toBe(1);
});

test('it refuses what it can\'t use, and creates nothing', function (array $options, string $error) {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('nicetry:install', [...$options, '--no-interaction' => true])
        ->expectsOutput($error)
        ->assertFailed();

    expect(User::count())->toBe(1)
        ->and(Administrator::count())->toBe(0);
})->with([
    'no email when it can\'t ask' => [[], 'Give the first administrator’s email address with --email.'],
    'not an email address' => [['--email' => 'ops'], 'That isn’t an email address.'],
    'a password that\'s too short' => [['--email' => 'ops@example.com', '--password' => 'short'], 'Choose a password of at least 12 characters, or leave it out to have one made.'],
    'an email that already has an account' => [['--email' => 'taken@example.com'], 'taken@example.com already has an account. Make it an administrator with: php artisan admin:grant taken@example.com'],
]);
