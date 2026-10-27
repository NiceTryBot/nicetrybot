<?php

use App\Enums\ClientBrowser;
use App\Enums\ClientPlatform;
use App\Enums\TlsFingerprintType;
use App\Enums\TlsStack;
use App\Models\ClientHint;
use App\Models\TlsFingerprint;
use App\Models\UserAgent;
use Illuminate\Support\Facades\DB;

test('profiling classifies every recorded user agent, JA4 fingerprint and set of client hints', function () {
    $value = 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/152.0.7977.53 Mobile/15E148 Safari/604.1';
    $userAgent = UserAgent::factory()->create(['hash' => md5($value), 'value' => $value]);
    DB::table('user_agent_profiles')->insert(['user_agent_id' => $userAgent->id, 'platform' => ClientPlatform::Windows, 'browser' => ClientBrowser::Unknown, 'major_version' => null]);
    $ja4 = TlsFingerprint::factory()->create(['value' => 't13d2013h2_a09f3c656075_7f0f34a4126d']);
    $ja3 = TlsFingerprint::factory()->create(['type' => TlsFingerprintType::Ja3, 'value' => '773906b0efdefa24a7f2b8eb6985bf37']);
    $hintsValue = '"Chromium";v="152", "Not?A_Brand";v="24", "Google Chrome";v="152" | "iOS"';
    $clientHints = ClientHint::query()->create(['hash' => md5($hintsValue), 'value' => $hintsValue]);

    $this->artisan('edge:profile-clients')
        ->expectsOutputToContain('Profiled 1 user agent(s), 1 JA4 fingerprint(s) and 1 set(s) of client hints.')
        ->assertSuccessful();

    $this->assertDatabaseHas('user_agent_profiles', ['user_agent_id' => $userAgent->id, 'platform' => ClientPlatform::Ios, 'browser' => ClientBrowser::Chromium, 'major_version' => 152]);
    $this->assertDatabaseHas('tls_fingerprint_profiles', ['tls_fingerprint_id' => $ja4->id, 'stack' => TlsStack::Apple]);
    $this->assertDatabaseMissing('tls_fingerprint_profiles', ['tls_fingerprint_id' => $ja3->id]);
    $this->assertDatabaseHas('client_hint_profiles', ['client_hint_id' => $clientHints->id, 'platform' => ClientPlatform::Ios]);
});
