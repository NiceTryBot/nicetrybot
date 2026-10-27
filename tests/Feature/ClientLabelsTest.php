<?php

use App\Data\LabelDirectory;
use App\Enums\LabelKind;
use App\Enums\TeamRole;
use App\Enums\TlsFingerprintType;
use App\Models\ClientLabel;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\Team;
use App\Models\TlsFingerprint;
use App\Models\User;
use App\Models\Verdict;
use Livewire\Livewire;

test('an IP, a fingerprint and a user agent are each named on their own', function (string $kind, string $value) {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::clients.label-modal')
        ->call('open', $kind, $value)
        ->set('name', 'Mine - Dev Box')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('label-changed');

    expect(ClientLabel::sole())
        ->team_id->toBe($user->currentTeam->id)
        ->kind->toBe(LabelKind::from($kind))
        ->value->toBe($value)
        ->name->toBe('Mine - Dev Box');
})->with([
    'an IP' => ['ip', '203.0.113.40'],
    'a TLS fingerprint' => ['ja4', 'q13d0314h3_55b375c5d22e_79cc91d6b50c'],
    'a user agent' => ['ua', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/154.0.0.0'],
    'a set of client hints' => ['hints', '"Not:A-Brand";v="24", "Chromium";v="134" | "Linux"'],
]);

test('values that aren\'t what their kind expects are refused', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::clients.label-modal')->call('open', 'ip', 'not-an-ip')->assertStatus(422);
});

test('an IP and the fingerprint seen on it show their own names', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $rule = EdgeRule::factory()->for($user->currentTeam)->create();
    $ja4 = TlsFingerprint::create(['type' => TlsFingerprintType::Ja4, 'value' => 'q13d0314h3_55b375c5d22e_79cc91d6b50c']);
    Verdict::factory()->for($rule, 'rule')->create(['team_id' => $user->currentTeam->id, 'client_ip' => '203.0.113.40', 'ja4_id' => $ja4->id]);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.40', 'ja4_id' => $ja4->id, 'path' => '/mine']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.4', 'path' => '/someone-else']);
    $user->currentTeam->clientLabels()->create(['kind' => LabelKind::Ip, 'value' => '203.0.113.40', 'name' => 'Home office']);
    $user->currentTeam->clientLabels()->create(['kind' => LabelKind::Ja4, 'value' => $ja4->value, 'name' => 'Dev Box Chrome']);

    $this->actingAs($user);

    $this->get(route('rules.index', ['tab' => 'verdicts']))->assertOk()->assertSee('Home office')->assertSee('Dev Box Chrome');

    Livewire::test('pages::activity.index')
        ->assertSee('Home office')
        ->set('search', 'dev box')
        ->assertSee('/mine')
        ->assertDontSee('/someone-else');
});

test('labels are the team\'s own', function () {
    $team = Team::factory()->create();
    $team->clientLabels()->create(['kind' => LabelKind::Ip, 'value' => '203.0.113.7', 'name' => 'Ours']);
    $directory = new LabelDirectory;

    expect($directory->labelFor($team->id, LabelKind::Ip, '203.0.113.7')->name)->toBe('Ours')
        ->and($directory->labelFor($team->id, LabelKind::Ja4, '203.0.113.7'))->toBeNull()
        ->and($directory->labelFor(Team::factory()->create()->id, LabelKind::Ip, '203.0.113.7'))->toBeNull();
});

test('a label can be renamed or removed, and members can\'t label', function () {
    $user = User::factory()->create();
    $user->currentTeam->clientLabels()->create(['kind' => LabelKind::Ip, 'value' => '203.0.113.7', 'name' => 'Old name']);
    $this->actingAs($user);

    $modal = Livewire::test('pages::clients.label-modal')
        ->call('open', 'ip', '203.0.113.7')
        ->assertSet('name', 'Old name')
        ->set('name', 'New name')
        ->call('save');

    expect(ClientLabel::sole()->name)->toBe('New name');

    $modal->call('open', 'ip', '203.0.113.7')->call('remove');

    expect(ClientLabel::count())->toBe(0);

    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $this->actingAs($member);

    Livewire::test('pages::clients.label-modal')->call('open', 'ip', '203.0.113.7')->assertForbidden();
});

test('a set of client hints shows its name on the requests that sent it, and finds them', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $hints = '"Not:A-Brand";v="24", "Chromium";v="134" | "Linux"';
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '5.255.102.26', 'client_hints' => $hints, 'path' => '/stuffing']);
    EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.4', 'client_hints' => '"Google Chrome";v="154" | "macOS"', 'path' => '/real-chrome']);

    $user->currentTeam->clientLabels()->create(['kind' => LabelKind::ClientHints, 'value' => $hints, 'name' => 'Serial Login Attempts']);

    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->assertSee('Serial Login Attempts')
        ->set('search', 'serial login')
        ->assertSee('/stuffing')
        ->assertDontSee('/real-chrome');
});
