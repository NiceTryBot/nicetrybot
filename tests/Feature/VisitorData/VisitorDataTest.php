<?php

use App\Enums\GymExit;
use App\Enums\GymSessionStatus;
use App\Enums\LabelKind;
use App\Enums\TeamRole;
use App\Models\ClientLabel;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\GymRoutine;
use App\Models\GymSession;
use App\Models\Team;
use App\Models\User;
use App\Models\Verdict;
use App\Models\VisitorDataSetting;
use App\Models\VisitorErasure;
use Livewire\Livewire;

test('data past a team\'s retention is deleted, and the rest stays', function () {
    $this->freezeTime();
    $team = Team::factory()->create();
    VisitorDataSetting::factory()->for($team)->create(['request_days' => 30, 'gym_days' => 10, 'verdict_days' => 7]);
    $domain = Domain::factory()->for($team)->create();
    $otherDomain = Domain::factory()->create();

    $oldRequest = EdgeRequest::factory()->for($domain)->create(['occurred_at' => now()->subDays(31)]);
    $recentRequest = EdgeRequest::factory()->for($domain)->create(['occurred_at' => now()->subDays(20)]);
    $theirOldRequest = EdgeRequest::factory()->for($otherDomain)->create(['occurred_at' => now()->subDays(31)]);
    $oldSession = GymSession::factory()->for($team)->create(['started_at' => now()->subDays(11)]);
    $recentSession = GymSession::factory()->for($team)->create(['started_at' => now()->subDays(9)]);
    $endedVerdict = Verdict::factory()->create(['team_id' => $team->id, 'expires_at' => now()->subDays(8)]);
    $liftedVerdict = Verdict::factory()->create(['team_id' => $team->id, 'lifted_at' => now()->subDays(8)]);
    $recentlyEnded = Verdict::factory()->create(['team_id' => $team->id, 'expires_at' => now()->subDays(6)]);
    $forever = Verdict::factory()->create(['team_id' => $team->id, 'expires_at' => null, 'first_matched_at' => now()->subYear()]);

    $this->artisan('visitor-data:prune')->assertSuccessful();

    expect(EdgeRequest::find($oldRequest->id))->toBeNull()
        ->and(EdgeRequest::find($recentRequest->id))->not->toBeNull()
        ->and(EdgeRequest::find($theirOldRequest->id))->not->toBeNull()
        ->and(GymSession::find($oldSession->id))->toBeNull()
        ->and(GymSession::find($recentSession->id))->not->toBeNull()
        ->and(Verdict::whereKey([$endedVerdict->id, $liftedVerdict->id])->count())->toBe(0)
        ->and(Verdict::whereKey([$recentlyEnded->id, $forever->id])->count())->toBe(2);
});

test('a team that hasn\'t chosen keeps data for the default periods', function () {
    $this->freezeTime();
    config(['visitor_data.retention.request_days' => 90]);
    $domain = Domain::factory()->create();
    $old = EdgeRequest::factory()->for($domain)->create(['occurred_at' => now()->subDays(91)]);
    $kept = EdgeRequest::factory()->for($domain)->create(['occurred_at' => now()->subDays(89)]);

    $this->artisan('visitor-data:prune')->assertSuccessful();

    expect(EdgeRequest::find($old->id))->toBeNull()
        ->and(EdgeRequest::find($kept->id))->not->toBeNull()
        ->and(VisitorDataSetting::count())->toBe(0);
});

test('erasing a visitor deletes everything the team holds about its IP and fingerprint, and records that it did', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $domain = Domain::factory()->for($team)->create();
    $ja4 = 't13d1517h2_8daaf6152771_cb7bf5808d99';
    $request = EdgeRequest::factory()->for($domain)->create(['client_ip' => '203.0.113.7']);
    $sameBrowser = EdgeRequest::factory()->for($domain)->fromClient('curl/8.7.1', $ja4)->create(['client_ip' => '198.51.100.20']);
    $someoneElse = EdgeRequest::factory()->for($domain)->create(['client_ip' => '198.51.100.9']);
    $theirs = EdgeRequest::factory()->create(['client_ip' => '203.0.113.7']);
    $session = GymSession::factory()->for($team)->create(['client_ip' => '203.0.113.7', 'ja4' => $ja4]);
    $handedOff = GymSession::factory()->for($team)->create(['client_ip' => '198.51.100.30', 'attempt_clients' => [['ip' => '203.0.113.7', 'ja4' => null, 'hints' => null]]]);
    $verdict = Verdict::factory()->create(['team_id' => $team->id, 'client_ip' => '203.0.113.7']);
    $label = ClientLabel::factory()->for($team)->create(['kind' => LabelKind::Ip, 'value' => '203.0.113.7']);
    $fingerprintLabel = ClientLabel::factory()->for($team)->create(['kind' => LabelKind::Ja4, 'value' => $ja4]);
    $this->actingAs($user);

    Livewire::test('pages::visitor-data.index')
        ->set('lookup', '203.0.113.7')
        ->call('erase')
        ->assertHasNoErrors();

    $erasure = VisitorErasure::sole();
    expect(EdgeRequest::where('client_ip', '203.0.113.7')->whereIn('domain_id', [$domain->id])->count())->toBe(0)
        ->and(EdgeRequest::find($someoneElse->id))->not->toBeNull()
        ->and(EdgeRequest::find($sameBrowser->id))->not->toBeNull()
        ->and(EdgeRequest::find($theirs->id))->not->toBeNull()
        ->and(GymSession::whereKey([$session->id, $handedOff->id])->count())->toBe(0)
        ->and(Verdict::find($verdict->id))->toBeNull()
        ->and(ClientLabel::find($label->id))->toBeNull()
        ->and(ClientLabel::find($fingerprintLabel->id))->not->toBeNull()
        ->and($erasure)
        ->user_id->toBe($user->id)
        ->subject_kind->toBe('ip')
        ->subject_hash->toBe(VisitorErasure::hashSubject('203.0.113.7', null))
        ->completed_at->not->toBeNull()
        ->and($erasure->deleted)->toMatchArray(['requests' => 1, 'gym_sessions' => 2, 'verdicts' => 1, 'labels' => 1])
        ->and(json_encode($erasure->toArray()))->not->toContain('203.0.113.7');
});

test('a fingerprint can be erased on its own', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create();
    $ja4 = 't13d131100_f57a46bbacb6_e7c285222651';
    EdgeRequest::factory()->for($domain)->fromClient('curl/8.7.1', $ja4)->count(2)->create();
    $kept = EdgeRequest::factory()->for($domain)->genuineChrome()->create();
    $this->actingAs($user);

    Livewire::test('pages::visitor-data.index')->set('lookup', $ja4)->call('erase');

    expect(EdgeRequest::pluck('id')->all())->toBe([$kept->id])
        ->and(VisitorErasure::sole()->subject_kind)->toBe('ja4');
});

test('a visitor quoting their gym reference is found with what\'s holding them back, which can be lifted', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $session = GymSession::factory()->for($team)->create(['client_ip' => '203.0.113.7', 'token' => 'AbCdEfGhIj'.str_repeat('x', 30)]);
    $verdict = Verdict::factory()->create(['team_id' => $team->id, 'client_ip' => '203.0.113.7']);
    Verdict::factory()->create(['team_id' => $team->id, 'client_ip' => '198.51.100.9']);
    $this->actingAs($user);

    $page = Livewire::test('pages::visitor-data.index')
        ->set('lookup', 'AbCdEfGhIj')
        ->assertSeeHtml('data-test="found-session"')
        ->assertSee('AbCdEfGhIj');

    expect($page->instance()->found['ip'])->toBe('203.0.113.7')
        ->and($page->instance()->found['verdicts']->modelKeys())->toBe([$verdict->id]);

    $page->call('liftVerdict', $verdict->id);

    expect($verdict->fresh()->lifted_at)->not->toBeNull();
});

test('retention and the visitor contact are saved within bounds, by those who may manage them', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $this->actingAs($owner);

    Livewire::test('pages::visitor-data.index')
        ->set('days.request_days', 0)
        ->call('saveRetention')
        ->assertHasErrors('days.request_days')
        ->set('days.request_days', 45)
        ->call('saveRetention')
        ->assertHasNoErrors()
        ->set('contact', 'http://example.com/help')
        ->call('saveContact')
        ->assertHasErrors('contact')
        ->set('contact', 'help@example.com')
        ->call('saveContact')
        ->assertHasNoErrors();

    expect(VisitorDataSetting::sole())->request_days->toBe(45)->contact->toBe('help@example.com');

    $member->switchTeam($team);
    $this->actingAs($member);

    Livewire::test('pages::visitor-data.index')
        ->assertSet('days.request_days', 45)
        ->set('days.request_days', 400)
        ->call('saveRetention')
        ->assertForbidden();
});

test('the gym shows a held or turned-away visitor the team\'s contact and their reference, but not a scanner\'s 404', function () {
    $routine = GymRoutine::factory()->obstacles(['waiting-room' => ['seconds' => 10]])->create();
    VisitorDataSetting::factory()->for($routine->team)->create(['contact' => 'help@example.com']);
    $session = GymSession::factory()->create(['team_id' => $routine->team_id]);
    $session->routines()->attach($routine);

    $this->get(route('gym.show', $session))
        ->assertSeeHtml('data-test="gym-help"')
        ->assertSee('mailto:help@example.com?subject=Reference%20'.$session->reference(), escape: false)
        ->assertSee($session->reference());

    $blocked = GymSession::factory()->create(['team_id' => $routine->team_id, 'status' => GymSessionStatus::TurnedAway, 'exit_action' => GymExit::Block, 'finished_at' => now()]);
    $blocked->routines()->attach($routine);
    $this->get(route('gym.show', $blocked))->assertSeeHtml('data-test="gym-help"');

    $scanner = GymSession::factory()->create(['team_id' => $routine->team_id, 'status' => GymSessionStatus::TurnedAway, 'exit_action' => GymExit::NotFound, 'finished_at' => now()]);
    $scanner->routines()->attach($routine);
    $this->get(route('gym.show', $scanner))->assertDontSeeHtml('data-test="gym-help"');
});

test('the gym shows no contact when the team hasn\'t given one', function () {
    $routine = GymRoutine::factory()->obstacles(['waiting-room' => ['seconds' => 10]])->create();
    $session = GymSession::factory()->create(['team_id' => $routine->team_id]);
    $session->routines()->attach($routine);

    $this->get(route('gym.show', $session))->assertDontSeeHtml('data-test="gym-help"');
});
