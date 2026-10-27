<?php

use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\Team;
use App\Models\User;
use NiceTry\Lockdown\Models\Lockdown;
use NiceTry\Lockdown\Support\LockdownPass;
use NiceTry\Origins\Models\Origin;

beforeEach(function () {
    config(['services.nicetry_edge.token' => 'edge-token']);
});

test('the edge hears every hostname of an engaged lockdown\'s locked sites, and nothing else', function () {
    $team = Team::factory()->create();
    $origin = Origin::factory()->for($team)->create();
    Domain::factory()->for($team)->verified()->create(['origin_id' => $origin->id, 'hostname' => 'a.cases.test']);
    $released = Domain::factory()->for($team)->verified()->create(['origin_id' => $origin->id, 'hostname' => 'b.cases.test']);
    Domain::factory()->for($team)->verified()->servingWww()->create(['hostname' => 'shop.example']);
    Domain::factory()->for($team)->create(['hostname' => 'unverified.example']);

    $lockdown = Lockdown::factory()->for($team)->engaged()->create();
    $lockdown->lockEverything();
    $lockdown->release($released);
    $lockdown->allowedIps()->create(['range' => '203.0.113.4']);

    Lockdown::factory()->create();
    Domain::factory()->verified()->create(['hostname' => 'other-team.example']);

    $this->getJson(route('edge.lockdowns'))->assertUnauthorized();

    $response = $this->withToken('edge-token')->getJson(route('edge.lockdowns'))->assertOk();

    expect($response->json('lockdowns'))->toHaveCount(1)
        ->and($response->json('lockdowns.0'))->toMatchArray([
            'team' => $team->id,
            'hosts' => ['a-cases-test.preview.example', 'a.cases.test', 'shop-example.preview.example', 'shop.example', 'www.a.cases.test', 'www.shop.example'],
            'allow' => ['203.0.113.4'],
            'secret' => $lockdown->secret,
            'retry_after' => 3600,
        ])
        ->and($response->json('lockdowns.0.html'))->toContain('Scheduled Maintenance')->not->toContain('NiceTry');
})->skip(fn () => ! class_exists(Origin::class), 'Needs the paid Origins add-on (nicetry/origins).');

test('a lifted lockdown leaves the edge with nothing locked', function () {
    $lockdown = Lockdown::factory()->engaged()->create();
    Domain::factory()->for($lockdown->team)->verified()->create();
    $lockdown->lockEverything();

    $lockdown->lift();

    $this->withToken('edge-token')->getJson(route('edge.lockdowns'))->assertExactJson(['lockdowns' => []]);
});

test('a site that joins a pooled origin is locked, and one released from it stays released', function () {
    $team = Team::factory()->create();
    $origin = Origin::factory()->for($team)->create();
    $lockdown = Lockdown::factory()->for($team)->engaged()->create();
    $lockdown->addOrigin($origin->id);

    $joined = Domain::factory()->for($team)->verified()->create(['origin_id' => $origin->id]);
    expect($lockdown->covers($joined))->toBeTrue();

    $lockdown->release($joined);
    expect($lockdown->covers($joined))->toBeFalse();

    $lockdown->lockEverything();
    expect($lockdown->covers($joined))->toBeTrue();

    $lockdown->removeOrigin($origin->id);
    expect($lockdown->covers($joined))->toBeFalse();
})->skip(fn () => ! class_exists(Origin::class), 'Needs the paid Origins add-on (nicetry/origins).');

test('an admin is sent to the site with a pass link the edge can check', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->verified()->create(['hostname' => 'shop.example']);
    $this->freezeTime();

    $location = $this->actingAs($user)->get(route('lockdown.pass', $domain))->assertRedirect()->headers->get('Location');

    $lockdown = Lockdown::for($user->currentTeam);
    $linkExpiresAt = now()->addMinutes(5)->getTimestamp();
    $passExpiresAt = now()->addHours(12)->getTimestamp();
    $signature = hash_hmac('sha256', "link\nshop.example\n{$linkExpiresAt}\n{$passExpiresAt}", $lockdown->secret);

    expect($location)->toBe("https://shop.example/_q/pass?t={$linkExpiresAt}.{$passExpiresAt}.{$signature}");
});

test('pass links are signed the way the edge checks them', function () {
    // The same known answer is pinned in the edge's lockdown_test.go (TestLockdownMACsMatchTheKnownAnswers).
    expect(LockdownPass::token('s3cret', 'A.Example.com', 1900000000, 1900003600))
        ->toBe('1900000000.1900003600.67b47e211b759ee5e6683718ad0efe227bd07866f48f1b0be55e56eaf246909f');
});

test('members can\'t take a pass, and another team\'s site is a 404', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);
    $domain = Domain::factory()->for($team)->verified()->create();

    $this->actingAs($member)->get(route('lockdown.pass', $domain))->assertForbidden();

    $admin = User::factory()->create();
    $this->actingAs($admin)->get(route('lockdown.pass', Domain::factory()->verified()->create()))->assertNotFound();
});
