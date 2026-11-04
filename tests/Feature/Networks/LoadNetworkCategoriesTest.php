<?php

use App\Actions\Networks\LoadNetworkCategories;
use App\Enums\NetworkCategory as Category;
use App\Models\NetworkCategory;

/**
 * Write a data file like the shipped one, and return its path.
 *
 * @param  list<string>  $lines
 */
function networkCategoriesFile(array $lines): string
{
    $path = tempnam(sys_get_temp_dir(), 'network-categories');
    file_put_contents($path, gzencode(implode("\n", $lines)."\n"));

    test()->beforeApplicationDestroyed(fn () => @unlink($path));

    return $path;
}

test('the loader writes each line of the file, and passes over what isn\'t a row', function () {
    $written = app(LoadNetworkCategories::class)->handle(networkCategoriesFile([
        "64496\t1\t3\t768",
        "64497\t5\t2\t4294967296",
        'not a row',
        "64498\t9\t1\t256",
        "64499\t2\t1",
    ]));

    expect($written)->toBe(2)
        ->and(NetworkCategory::query()->pluck('category', 'asn')->all())->toBe([64496 => Category::Hosting, 64497 => Category::Government])
        ->and(NetworkCategory::query()->find(64496)->ranges)->toBe(3)
        ->and(NetworkCategory::query()->find(64497)->addresses)->toBe(4294967296)
        ->and(NetworkCategory::query()->find(64496)->is_override)->toBeFalse();
});

test('running the loader again updates its own rows', function () {
    $load = app(LoadNetworkCategories::class);

    $load->handle(networkCategoriesFile(["64496\t1\t3\t768"]));
    $load->handle(networkCategoriesFile(["64496\t3\t4\t1024"]));

    $row = NetworkCategory::query()->sole();

    expect($row->category)->toBe(Category::Business)
        ->and($row->ranges)->toBe(4)
        ->and($row->addresses)->toBe(1024);
});

test('the loader never overwrites an administrator\'s correction, a "not set" included', function () {
    NetworkCategory::factory()->override()->create(['asn' => 64496, 'category' => Category::Education, 'ranges' => 1, 'addresses' => 256]);
    NetworkCategory::factory()->override()->create(['asn' => 64497, 'category' => null]);

    $written = app(LoadNetworkCategories::class)->handle(networkCategoriesFile(["64496\t1\t3\t768", "64497\t1\t3\t768", "64498\t1\t3\t768"]));

    expect($written)->toBe(1)
        ->and(NetworkCategory::query()->find(64496)->category)->toBe(Category::Education)
        ->and(NetworkCategory::query()->find(64496)->ranges)->toBe(1)
        ->and(NetworkCategory::query()->find(64497)->category)->toBeNull()
        ->and(NetworkCategory::query()->find(64498)->category)->toBe(Category::Hosting);
});

test('the loader says so when it can\'t read the file', function () {
    app(LoadNetworkCategories::class)->handle(sys_get_temp_dir().'/no-such-network-categories.tsv.gz');
})->throws(RuntimeException::class, 'Can\'t read the network categories');

test('networks:load-categories loads the shipped data', function () {
    $this->artisan('networks:load-categories')
        ->expectsOutputToContain('110,106 network categories loaded.')
        ->assertSuccessful();

    expect(NetworkCategory::query()->count())->toBe(110106)
        ->and(NetworkCategory::query()->find(24940)->only('category', 'ranges', 'addresses'))->toBe(['category' => Category::Hosting, 'ranges' => 80, 'addresses' => 3006976])
        ->and(NetworkCategory::query()->find(208137)->category)->toBe(Category::Business)
        ->and(NetworkCategory::query()->find(218785))->toBeNull();
});
