<?php

namespace NiceTry\Tarpit\Support;

use App\Enums\GymSessionStatus;
use App\Models\GymSession;

/**
 * The clients a team sent to the gym that never ran its script: no attempt, no hashes, gone within a
 * couple of seconds of the page and never back. A browser runs the gym's page on its own, so these are
 * almost always tools that fetch a URL and move on.
 */
class GymNoShows
{
    /**
     * How long after the page a no-show may still have been seen: the page itself touches the session.
     */
    private const int SECONDS_ON_THE_PAGE = 2;

    /**
     * How far back no-shows are counted.
     */
    private const int LOOK_BACK_HOURS = 24;

    /**
     * The team's no-shows of the last day, one entry per client.
     *
     * @return list<array{ip: string|null, ja4: string|null}>
     */
    public function for(int $teamId): array
    {
        $clients = [];

        GymSession::query()
            ->where('team_id', $teamId)
            ->where('is_preview', false)
            ->where('status', GymSessionStatus::Training)
            ->whereNull('attempt_clients')
            ->where('hashes', 0)
            ->where('started_at', '>=', now()->subHours(self::LOOK_BACK_HOURS))
            ->where('last_seen_at', '<', now()->subMinutes((int) config('gym.idle_minutes', 15)))
            ->whereNotNull('client_ip')
            ->orderBy('id')
            ->toBase()
            ->get(['client_ip', 'ja4', 'started_at', 'last_seen_at'])
            ->each(function (object $session) use (&$clients) {
                if (strtotime((string) $session->last_seen_at) - strtotime((string) $session->started_at) <= self::SECONDS_ON_THE_PAGE) {
                    $clients[$session->client_ip] ??= ['ip' => $session->client_ip, 'ja4' => null];
                }
            });

        return array_values($clients);
    }
}
