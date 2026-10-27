<?php

use App\Enums\AiProvider;
use App\Models\AiCredential;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('guests are sent to log in', function () {
    $this->get(route('ai.edit'))->assertRedirect(route('login'));
});

test('a connected key shows by its hint, never in full', function () {
    $user = User::factory()->create();
    AiCredential::factory()->for($user)->create([
        'secret' => 'sk-ant-api03-SECRETPARTabcdefghijklmnopqrstuvwxyz-x7Qa',
        'hint' => 'sk-ant-…x7Qa',
    ]);

    $this->actingAs($user)
        ->get(route('ai.edit'))
        ->assertSee('sk-ant-…x7Qa')
        ->assertSee('Connected')
        ->assertDontSee('SECRETPART');
});

test('a key Anthropic accepts is saved encrypted, with its hint', function () {
    $user = User::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/models*' => Http::response(['data' => [['id' => 'claude-opus-5', 'type' => 'model']]])]);

    $this->actingAs($user);

    Livewire::test('pages::settings.ai')
        ->set('apiKey', '  sk-ant-api03-abcdefghijklmnopqrstuvwxyz0123-x7Qa  ')
        ->call('saveAnthropicKey')
        ->assertHasNoErrors()
        ->assertSet('apiKey', '');

    $credential = $user->aiCredential(AiProvider::Anthropic);
    expect($credential)
        ->secret->toBe('sk-ant-api03-abcdefghijklmnopqrstuvwxyz0123-x7Qa')
        ->hint->toBe('sk-ant-…x7Qa');
    expect(DB::table('ai_credentials')->value('secret'))->not->toContain('abcdefghijklmnopqrstuvwxyz');
    Http::assertSent(fn (Request $request) => $request->hasHeader('x-api-key', 'sk-ant-api03-abcdefghijklmnopqrstuvwxyz0123-x7Qa'));
});

test('saving a new key replaces the old one', function () {
    $user = User::factory()->create();
    AiCredential::factory()->for($user)->create(['last_used_at' => now()]);
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/models*' => Http::response(['data' => []])]);

    $this->actingAs($user);

    Livewire::test('pages::settings.ai')
        ->set('apiKey', 'sk-ant-api03-replacementkeyabcdefghijkl-NEW1')
        ->call('saveAnthropicKey')
        ->assertHasNoErrors();

    expect($user->aiCredentials()->sole())
        ->hint->toBe('sk-ant-…NEW1')
        ->last_used_at->toBeNull();
});

test('a key for a whole organization asks for its workspace, then saves with it', function () {
    $user = User::factory()->create();
    Http::preventStrayRequests();
    Http::fakeSequence('api.anthropic.com/v1/models*')
        ->push(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'This API key is not scoped to a workspace, so this request must include the anthropic-workspace-id header with the ID of the workspace to use. Add the header, or use an API key that is scoped to a workspace.']], 400)
        ->push(['data' => []]);

    $this->actingAs($user);

    $page = Livewire::test('pages::settings.ai')
        ->set('apiKey', 'sk-ant-api03-organizationwidekeyabcdefgh-0rg1')
        ->call('saveAnthropicKey')
        ->assertHasErrors(['workspaceId' => 'This key belongs to your whole Anthropic organization, so Anthropic needs to know which workspace to bill. Paste that workspace’s ID, or create a key inside a workspace instead.'])
        ->assertSee('Workspace ID');

    $this->assertDatabaseCount('ai_credentials', 0);

    $page->set('workspaceId', 'wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ')
        ->call('saveAnthropicKey')
        ->assertHasNoErrors();

    expect($user->aiCredential(AiProvider::Anthropic)->workspace_id)->toBe('wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ');
    Http::assertSent(fn (Request $request) => $request->hasHeader('anthropic-workspace-id', 'wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ'));
});

test('a workspace ID with anything but letters, numbers, dashes and underscores is turned away', function () {
    $user = User::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/*' => Http::response(['data' => []])]);

    $this->actingAs($user);

    Livewire::test('pages::settings.ai')
        ->set('apiKey', 'sk-ant-api03-organizationwidekeyabcdefgh-0rg1')
        ->set('workspaceId', 'wrkspc 01; drop')
        ->call('saveAnthropicKey')
        ->assertHasErrors(['workspaceId' => 'A workspace ID is letters, numbers, dashes and underscores, like wrkspc_01…']);

    Http::assertNothingSent();
});

test('a key Anthropic rejects is not saved', function () {
    $user = User::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/models*' => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401)]);

    $this->actingAs($user);

    Livewire::test('pages::settings.ai')
        ->set('apiKey', 'sk-ant-api03-revokedkeyabcdefghijklmnop-0000')
        ->call('saveAnthropicKey')
        ->assertHasErrors(['apiKey' => 'Anthropic didn’t accept that key. Check that it’s active and copied whole.']);

    $this->assertDatabaseCount('ai_credentials', 0);
});

test('something that isn\'t an Anthropic key is turned away without asking Anthropic', function () {
    $user = User::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/*' => Http::response(['data' => []])]);

    $this->actingAs($user);

    Livewire::test('pages::settings.ai')
        ->set('apiKey', 'sk-proj-not-an-anthropic-key-at-all')
        ->call('saveAnthropicKey')
        ->assertHasErrors(['apiKey' => 'That doesn’t look like an Anthropic API key. They start with sk-ant-.']);

    Http::assertNothingSent();
    $this->assertDatabaseCount('ai_credentials', 0);
});

test('a key can\'t be saved while Anthropic is out of reach', function () {
    $user = User::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/models*' => Http::failedConnection()]);

    $this->actingAs($user);

    Livewire::test('pages::settings.ai')
        ->set('apiKey', 'sk-ant-api03-abcdefghijklmnopqrstuvwxyz0123-x7Qa')
        ->call('saveAnthropicKey')
        ->assertHasErrors(['apiKey' => 'Couldn’t reach Anthropic. Check your connection and try again.']);

    $this->assertDatabaseCount('ai_credentials', 0);
});

test('removing the key forgets it', function () {
    $user = User::factory()->create();
    $credential = AiCredential::factory()->for($user)->create();
    $someoneElses = AiCredential::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.ai')->call('removeAnthropicKey');

    $this->assertModelMissing($credential);
    $this->assertModelExists($someoneElses);
});
