<?php

use App\Enums\NetworkCategory as Category;
use App\Enums\NetworkKind;
use App\Models\Administrator;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Network;
use App\Models\NetworkCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function networkAdministrator(): User
{
    return Administrator::factory()->create()->user;
}

test('a request shows its network\'s category, on its row and in its details', function () {
    $user = User::factory()->create();
    NetworkCategory::factory()->create(['asn' => 64496, 'category' => Category::Education]);
    $request = EdgeRequest::factory()->for(Domain::factory()->for($user->currentTeam))->fromNetwork(64496, 'Example University', NetworkKind::Unknown)->create(['client_ip' => '203.0.113.1']);
    $this->actingAs($user);

    $page = Livewire::test('pages::activity.index');

    expect(substr_count($page->html(), 'data-test="network-category-badge"'))->toBe(1);

    $page->assertSeeInOrder(['Example University', 'Education']);

    $page->call('inspect', $request->id);

    expect(substr_count($page->html(), 'data-test="network-category-badge"'))->toBe(2);
});

test('a client row shows its network\'s category', function () {
    $user = User::factory()->create();
    NetworkCategory::factory()->create(['asn' => 64496, 'category' => Category::Government]);
    EdgeRequest::factory()->for(Domain::factory()->for($user->currentTeam))->fromNetwork(64496, 'Example Ministry', NetworkKind::Unknown)->create(['client_ip' => '203.0.113.1']);
    $this->actingAs($user);

    Livewire::test('pages::activity.index')->set('view', 'clients')->assertSeeHtml('data-test="network-category-badge"')->assertSeeInOrder(['Example Ministry', 'Government']);
});

test('the category badge falls back to the curated list, and a correction to "not set" outranks it', function (bool $corrected, int $badges) {
    $user = User::factory()->create();

    if ($corrected) {
        NetworkCategory::factory()->override()->create(['asn' => 64496, 'category' => null]);
    }

    EdgeRequest::factory()->for(Domain::factory()->for($user->currentTeam))->fromNetwork(64496, 'Example Hosting', NetworkKind::Hosting)->create();
    $this->actingAs($user);

    expect(substr_count(Livewire::test('pages::activity.index')->html(), 'data-test="network-category-badge"'))->toBe($badges);
})->with([
    'no row' => [false, 1],
    'not set' => [true, 0],
]);

test('showing categories takes one query, however many networks are on the page', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();

    foreach (range(64496, 64505) as $asn) {
        NetworkCategory::factory()->create(['asn' => $asn]);
        EdgeRequest::factory()->for($domain)->fromNetwork($asn, "Example {$asn}", NetworkKind::Unknown)->create();
    }

    $this->actingAs($user);

    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        $queries += str_contains($query->sql, 'from "network_categories"') ? 1 : 0;
    });

    Livewire::test('pages::activity.index');

    expect($queries)->toBe(1);
});

test('the client menu offers the correction to administrators, for a client with an ASN', function () {
    $admin = networkAdministrator();
    EdgeRequest::factory()->for(Domain::factory()->for($admin->currentTeam))->fromNetwork(64496, 'Example Hosting')->create();
    $this->actingAs($admin);

    Livewire::test('pages::activity.index')
        ->assertSeeHtml('data-test="network-category"')
        ->assertSeeHtml('x-show="asn"')
        ->assertSeeHtml('data-test="network-category-form"');

    $member = User::factory()->create();
    EdgeRequest::factory()->for(Domain::factory()->for($member->currentTeam))->fromNetwork(64496, 'Example Hosting')->create();
    $this->actingAs($member);

    Livewire::test('pages::activity.index')
        ->assertSeeHtml('data-test="client-menu"')
        ->assertDontSeeHtml('data-test="network-category"')
        ->assertDontSeeHtml('data-test="network-category-form"');
});

test('an administrator corrects a network\'s category for the installation, and the page shows it', function () {
    $admin = networkAdministrator();
    Network::factory()->create(['asn' => 64496, 'name' => 'Example Hosting']);
    NetworkCategory::factory()->create(['asn' => 64496, 'category' => Category::Business, 'ranges' => 4, 'addresses' => 1024]);
    $this->actingAs($admin);

    Livewire::test('pages::activity.network-category-modal')
        ->call('open', 64496)
        ->assertSet('category', 'business')
        ->assertSeeText('AS64496 Example Hosting')
        ->set('category', 'hosting')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('network-category-changed');

    $row = NetworkCategory::query()->sole();

    expect($row->category)->toBe(Category::Hosting)
        ->and($row->is_override)->toBeTrue()
        ->and($row->ranges)->toBe(4)
        ->and($row->addresses)->toBe(1024);
});

test('a correction can say "not set", and can be made for a network the data doesn\'t know', function () {
    $this->actingAs(networkAdministrator());

    Livewire::test('pages::activity.network-category-modal')
        ->call('open', 64496)
        ->assertSet('category', '')
        ->set('category', '')
        ->call('save')
        ->assertHasNoErrors();

    $row = NetworkCategory::query()->sole();

    expect($row->asn)->toBe(64496)
        ->and($row->category)->toBeNull()
        ->and($row->is_override)->toBeTrue();
});

test('a correction takes one of the categories, for a real ASN', function () {
    $this->actingAs(networkAdministrator());

    Livewire::test('pages::activity.network-category-modal')
        ->call('open', 64496)
        ->set('category', 'cloud')
        ->call('save')
        ->assertHasErrors('category');

    Livewire::test('pages::activity.network-category-modal')->call('open', 0)->assertStatus(422);
    Livewire::test('pages::activity.network-category-modal')->call('save')->assertStatus(422);

    expect(NetworkCategory::query()->count())->toBe(0);
});

test('only an administrator can correct a network\'s category', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::activity.network-category-modal')->call('open', 64496)->assertForbidden();

    expect(NetworkCategory::query()->count())->toBe(0);
});

test('someone who stops being an administrator can\'t save from a modal they had open', function () {
    $admin = networkAdministrator();
    $this->actingAs($admin);
    $modal = Livewire::test('pages::activity.network-category-modal')->call('open', 64496)->set('category', 'isp');

    Administrator::query()->where('user_id', $admin->id)->delete();
    $this->actingAs($admin->fresh());

    $modal->call('save')->assertForbidden();

    expect(NetworkCategory::query()->count())->toBe(0);
});
