<?php

use App\Models\Domain;
use App\Models\User;
use Livewire\Livewire;
use NiceTry\PrivacyMode\Models\PrivacyWord;

test('guests are sent to log in', function () {
    $this->get(route('privacy.edit'))->assertRedirect(route('login'));
});

test('the settings menu links to Privacy, after the core\'s AI settings', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('privacy.edit'))
        ->assertOk()
        ->assertSeeInOrder([route('ai.edit'), route('privacy.edit')]);
});

test('a word is saved lowercased with its stand-in and handed to privacy mode at once', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('privacy-mode::settings')
        ->set('word', '  GloBex ')
        ->set('replacement', 'acme')
        ->call('addWord')
        ->assertHasNoErrors()
        ->assertSet('word', '')
        ->assertDispatched('privacy-words-changed', words: ['globex' => 'acme']);

    expect(PrivacyWord::query()->sole())
        ->word->toBe('globex')
        ->replacement->toBe('acme');
});

test('a word without a stand-in shows a dot per letter', function () {
    expect(PrivacyWord::factory()->make(['word' => 'initech'])->standIn())->toBe('•••••••');
});

test('a word already hidden is turned away, in any case', function () {
    PrivacyWord::factory()->create(['word' => 'umbrella']);
    $this->actingAs(User::factory()->create());

    Livewire::test('privacy-mode::settings')
        ->set('word', 'Umbrella')
        ->call('addWord')
        ->assertHasErrors(['word' => 'unique']);
});

test('removing a word stops hiding it', function () {
    $word = PrivacyWord::factory()->create(['word' => 'case']);
    $this->actingAs(User::factory()->create());

    Livewire::test('privacy-mode::settings')
        ->call('removeWord', $word->id)
        ->assertDispatched('privacy-words-changed', words: []);

    expect(PrivacyWord::query()->exists())->toBeFalse();
});

test('every team\'s pages hand privacy mode the same words', function () {
    PrivacyWord::map();
    PrivacyWord::factory()->create(['word' => 'sign', 'replacement' => 'door']);
    $user = User::factory()->create();
    Domain::factory()->for($user->currentTeam)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<script type="text/plain" data-privacy-words>'.base64_encode(json_encode(['sign' => 'door'])).'</script>', false);
});
