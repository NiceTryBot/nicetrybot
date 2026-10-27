<?php

use App\Data\BrowserReleases;
use App\Models\GymSession;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('gym_sessions', function (Blueprint $table) {
            $table->unsignedInteger('client_hints_id')->nullable()->after('ja4');
        });

        // Captcha answers belong to the captchas add-on; without it there's nothing to backfill.
        if (Schema::hasTable('captcha_answers')) {
            $this->backfillClientHints();
            $this->remarkCaptchaAnswers();
        }
    }

    /**
     * Give the sessions that answered a captcha the client hints their visitor sent with the request
     * that brought them to the gym, which the edge logged: from their IP to their domain, closest to
     * when they entered.
     */
    private function backfillClientHints(): void
    {
        $sessionIds = DB::table('captcha_answers')->distinct()->pluck('gym_session_id');

        DB::table('gym_sessions')
            ->whereIn('id', $sessionIds)
            ->whereNotNull('domain_id')
            ->whereNotNull('client_ip')
            ->select(['id', 'domain_id', 'client_ip', 'started_at'])
            ->orderBy('id')
            ->each(function (object $session) {
                $startedAt = Carbon::parse($session->started_at);

                $hintsId = DB::table('edge_requests')
                    ->where('domain_id', $session->domain_id)
                    ->whereBetween('occurred_at', [$startedAt->copy()->subMinutes(2), $startedAt->copy()->addMinutes(2)])
                    ->where('client_ip', $session->client_ip)
                    ->whereNotNull('client_hints_id')
                    ->orderBy('occurred_at')
                    ->value('client_hints_id');

                if ($hintsId !== null) {
                    DB::table('gym_sessions')->where('id', $session->id)->update(['client_hints_id' => $hintsId]);
                }
            });
    }

    /**
     * Mark the answers kept so far again, now that their clients' hints are known: a client that lies
     * only in its client hints is spoofed.
     */
    private function remarkCaptchaAnswers(): void
    {
        $releases = BrowserReleases::now();

        DB::table('captcha_answers')
            ->select(['gym_session_id', 'client_ip'])
            ->distinct()
            ->orderBy('gym_session_id')
            ->get()
            ->groupBy('gym_session_id')
            ->chunk(500)
            ->each(function ($chunk) use ($releases) {
                $sessions = GymSession::query()->with('clientHint')->whereIn('id', $chunk->keys())->get()->keyBy('id');

                foreach ($chunk as $sessionId => $clients) {
                    foreach ($clients as $client) {
                        DB::table('captcha_answers')
                            ->where('gym_session_id', $sessionId)
                            ->where('client_ip', $client->client_ip)
                            ->update(['client' => $sessions->get($sessionId)?->clientProfile($client->client_ip)->family($releases)]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gym_sessions', function (Blueprint $table) {
            $table->dropColumn('client_hints_id');
        });
    }
};
