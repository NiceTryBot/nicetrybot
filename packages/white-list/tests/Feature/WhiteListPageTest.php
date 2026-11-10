<?php

use App\Models\AddonSwitch;
use App\Models\Administrator;
use App\Models\User;
use Livewire\Livewire;
use NiceTry\WhiteList\Models\WhitelistEntry;

function whiteListAdmin(): User
{
    return Administrator::factory()->create()->user;
}

test('only administrators reach the page', function () {
    $this->get(route('admin.white-list'))->assertRedirect();

    $this->actingAs(User::factory()->create())->get(route('admin.white-list'))->assertForbidden();

    $this->actingAs(whiteListAdmin())->get(route('admin.white-list'))
        ->assertOk()
        ->assertSee('White List')
        ->assertSee('Nothing is allowed yet.');
});

test('an administrator adds an entry, and its ranges are normalised while junk is dropped', function () {
    $this->actingAs(whiteListAdmin());

    Livewire::test('white-list::index')
        ->call('startAdd')
        ->set('name', 'Googlebot')
        ->set('category', 'bot/crawler')
        ->set('rangesText', "66.249.64.0/27\n203.0.113.9\nnot-an-ip")
        ->set('reason', 'Our search crawler')
        ->call('save')
        ->assertHasNoErrors();

    $entry = WhitelistEntry::sole();

    expect($entry->name)->toBe('Googlebot')
        ->and($entry->category)->toBe('bot/crawler')
        ->and($entry->ranges)->toBe(['66.249.64.0/27', '203.0.113.9'])
        ->and($entry->enabled)->toBeTrue()
        ->and($entry->reason)->toBe('Our search crawler');
});

test('a category that is not two words with a slash is refused', function () {
    $this->actingAs(whiteListAdmin());

    Livewire::test('white-list::index')
        ->call('startAdd')
        ->set('name', 'Bad')
        ->set('category', 'botcrawler')
        ->set('rangesText', '203.0.113.9')
        ->call('save')
        ->assertHasErrors(['category']);

    expect(WhitelistEntry::count())->toBe(0);
});

test('an entry with no real addresses is refused', function () {
    $this->actingAs(whiteListAdmin());

    Livewire::test('white-list::index')
        ->call('startAdd')
        ->set('name', 'Empty')
        ->set('category', 'bot/ai')
        ->set('rangesText', "nonsense\nalso nonsense")
        ->call('save')
        ->assertHasErrors(['rangesText']);

    expect(WhitelistEntry::count())->toBe(0);
});

test('an administrator edits, turns off, and removes an entry', function () {
    $this->actingAs(whiteListAdmin());
    $entry = WhitelistEntry::factory()->create(['name' => 'Old', 'enabled' => true]);

    Livewire::test('white-list::index')
        ->call('edit', $entry->id)
        ->set('name', 'New name')
        ->call('save')
        ->assertHasNoErrors();
    expect($entry->fresh()->name)->toBe('New name');

    Livewire::test('white-list::index')->call('toggle', $entry->id);
    expect($entry->fresh()->enabled)->toBeFalse();

    Livewire::test('white-list::index')->call('delete', $entry->id);
    expect(WhitelistEntry::find($entry->id))->toBeNull();
});

test('someone who stops being an administrator can\'t act on a page they had open', function () {
    $user = whiteListAdmin();
    $this->actingAs($user);
    $page = Livewire::test('white-list::index');

    Administrator::query()->where('user_id', $user->id)->delete();

    $page->set('name', 'Sneaky')->set('category', 'bot/ai')->set('rangesText', '203.0.113.9')->call('save')->assertForbidden();

    expect(WhitelistEntry::count())->toBe(0);
});

test('the page and its feed answer 404 while the add-on is off', function () {
    AddonSwitch::factory()->create(['slug' => 'white-list']);

    $this->actingAs(whiteListAdmin())->get(route('admin.white-list'))->assertNotFound();
});
