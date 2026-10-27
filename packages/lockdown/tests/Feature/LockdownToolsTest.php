<?php

use App\Enums\ApiAbility;
use App\Models\AddonSwitch;
use App\Models\Domain;
use Illuminate\Testing\Fluent\AssertableJson;
use NiceTry\Lockdown\Mcp\GetLockdownStatus;
use NiceTry\Lockdown\Mcp\LiftLockdown;
use NiceTry\Lockdown\Mcp\LockDownDomains;
use NiceTry\Lockdown\Mcp\ReleaseDomains;
use NiceTry\Lockdown\Models\Lockdown;
use NiceTry\Origins\Models\Origin;

test('the status shows whether the lockdown is engaged, what it covers, who gets through and where to see it', function () {
    [$token] = apiToken();
    $team = $token->team;
    $origin = Origin::factory()->for($team)->create(['name' => 'Shop origin']);
    Domain::factory()->for($team)->create(['hostname' => 'shop.example', 'origin_id' => $origin->id]);
    $released = Domain::factory()->for($team)->create(['hostname' => 'blog.example', 'origin_id' => $origin->id]);
    Domain::factory()->for($team)->create(['hostname' => 'serving.example']);

    $lockdown = Lockdown::for($team);
    $lockdown->addOrigin($origin->id);
    $lockdown->release($released);
    $lockdown->allowedIps()->create(['range' => '203.0.113.4', 'label' => 'Office']);
    $lockdown->engage();

    mcpAs($token)->tool(GetLockdownStatus::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('engaged', true)
            ->where('engaged_since', $lockdown->fresh()->engaged_at->toIso8601String())
            ->where('site_count', 3)
            ->where('locked_count', 1)
            ->where('locked_sites', ['shop.example'])
            ->where('pooled_origins', [['name' => 'Shop origin', 'sites' => 2]])
            ->where('released_sites', ['blog.example'])
            ->where('allowed_ips', [['range' => '203.0.113.4', 'label' => 'Office']])
            ->where('page_url', route('lockdown.index', $team))
            ->etc());
})->skip(fn () => ! class_exists(Origin::class), 'Needs the paid Origins add-on (nicetry/origins).');

test('locking down hostnames puts only those sites in the pool and engages the lockdown', function () {
    [$token] = apiToken([ApiAbility::Lockdown]);
    $team = $token->team;
    $shop = Domain::factory()->for($team)->create(['hostname' => 'shop.example']);
    $other = Domain::factory()->for($team)->create(['hostname' => 'other.example']);

    mcpAs($token)->tool(LockDownDomains::class, ['hostnames' => ['https://Shop.Example/']])
        ->assertOk()
        ->assertSee('shop.example');

    $lockdown = Lockdown::for($team);
    expect($lockdown->isEngaged())->toBeTrue()
        ->and($lockdown->covers($shop))->toBeTrue()
        ->and($lockdown->covers($other))->toBeFalse();
});

test('engage false only prepares the pool', function () {
    [$token] = apiToken([ApiAbility::Lockdown]);
    $shop = Domain::factory()->for($token->team)->create(['hostname' => 'shop.example']);

    mcpAs($token)->tool(LockDownDomains::class, ['hostnames' => ['shop.example'], 'engage' => false])->assertOk();

    $lockdown = Lockdown::for($token->team);
    expect($lockdown->isEngaged())->toBeFalse()
        ->and($lockdown->covers($shop))->toBeTrue();
});

test('locking down everything covers every site of the team', function () {
    [$token] = apiToken([ApiAbility::Lockdown]);
    $team = $token->team;
    $origin = Origin::factory()->for($team)->create();
    Domain::factory()->for($team)->create(['origin_id' => $origin->id]);
    Domain::factory()->for($team)->create();

    mcpAs($token)->tool(LockDownDomains::class, ['everything' => true])->assertOk();

    $lockdown = Lockdown::for($team);
    expect($lockdown->isEngaged())->toBeTrue()
        ->and($lockdown->lockedDomains()->count())->toBe(2);
})->skip(fn () => ! class_exists(Origin::class), 'Needs the paid Origins add-on (nicetry/origins).');

test('hostnames the team has no site for change nothing and are named in the error', function () {
    [$token] = apiToken([ApiAbility::Lockdown]);
    Domain::factory()->for($token->team)->create(['hostname' => 'shop.example']);
    Domain::factory()->create(['hostname' => 'theirs.example']);

    mcpAs($token)->tool(LockDownDomains::class, ['hostnames' => ['shop.example', 'theirs.example', 'nowhere.example']])
        ->assertHasErrors(['theirs.example, nowhere.example', 'Nothing was changed']);

    $lockdown = Lockdown::for($token->team);
    expect($lockdown->isEngaged())->toBeFalse()
        ->and($lockdown->lockedDomains()->count())->toBe(0);
});

test('locking down needs hostnames or everything', function () {
    [$token] = apiToken([ApiAbility::Lockdown]);

    mcpAs($token)->tool(LockDownDomains::class)->assertHasErrors(['Pass the hostnames to lock down']);

    expect(Lockdown::for($token->team)->isEngaged())->toBeFalse();
});

test('releasing sites lets them serve while the rest stay locked', function () {
    [$token] = apiToken([ApiAbility::Lockdown]);
    $team = $token->team;
    $origin = Origin::factory()->for($team)->create();
    $shop = Domain::factory()->for($team)->create(['hostname' => 'shop.example', 'origin_id' => $origin->id]);
    $blog = Domain::factory()->for($team)->create(['hostname' => 'blog.example', 'origin_id' => $origin->id]);
    $lockdown = Lockdown::factory()->for($team)->engaged()->create();
    $lockdown->addOrigin($origin->id);

    mcpAs($token)->tool(ReleaseDomains::class, ['hostnames' => ['shop.example']])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('released', ['shop.example'])
            ->where('locked_sites', ['blog.example'])
            ->etc());

    expect($lockdown->covers($shop))->toBeFalse()
        ->and($lockdown->covers($blog))->toBeTrue();
})->skip(fn () => ! class_exists(Origin::class), 'Needs the paid Origins add-on (nicetry/origins).');

test('another team\'s site can\'t be released', function () {
    [$token] = apiToken([ApiAbility::Lockdown]);
    Domain::factory()->create(['hostname' => 'theirs.example']);

    mcpAs($token)->tool(ReleaseDomains::class, ['hostnames' => ['theirs.example']])->assertHasErrors(['theirs.example']);
});

test('lifting the lockdown disengages it and keeps the pool', function () {
    [$token] = apiToken([ApiAbility::Lockdown]);
    $shop = Domain::factory()->for($token->team)->create();
    $lockdown = Lockdown::factory()->for($token->team)->engaged()->create();
    $lockdown->lock($shop);

    mcpAs($token)->tool(LiftLockdown::class)->assertOk()->assertSee('Lockdown lifted');

    expect($lockdown->fresh()->isEngaged())->toBeFalse()
        ->and($lockdown->covers($shop))->toBeTrue();
});

test('a read-only token sees the status but not the tools that change the lockdown', function () {
    [$token] = apiToken();

    mcpAs($token)->tools()
        ->assertRegistered(GetLockdownStatus::class)
        ->assertNotRegistered([LockDownDomains::class, ReleaseDomains::class, LiftLockdown::class]);

    mcpAs($token)->tool(LockDownDomains::class, ['everything' => true])->assertNotRegistered();
});

test('the lockdown tools are gone while an administrator has the add-on turned off', function () {
    [$token] = apiToken([ApiAbility::Lockdown]);
    $tools = [GetLockdownStatus::class, LockDownDomains::class, ReleaseDomains::class, LiftLockdown::class];

    mcpAs($token)->tools()->assertRegistered($tools);

    AddonSwitch::factory()->create(['slug' => 'lockdown']);

    mcpAs($token)->tools()->assertNotRegistered($tools);
    mcpAs($token)->tool(LockDownDomains::class, ['everything' => true])->assertNotRegistered();
});
