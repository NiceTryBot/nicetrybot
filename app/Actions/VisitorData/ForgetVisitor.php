<?php

namespace App\Actions\VisitorData;

use App\Jobs\ForgetVisitorJob;
use App\Models\Team;
use App\Models\User;
use App\Models\VisitorErasure;
use App\Support\VisitorDataHolders;

class ForgetVisitor
{
    public function __construct(private VisitorDataHolders $holders)
    {
        //
    }

    /**
     * Record that a team member asked to erase one visitor (an IP address, a JA4 fingerprint, or both),
     * and erase them in the background: finding a visitor's requests can take a while on a big team.
     */
    public function request(Team $team, ?User $user, ?string $ip, ?string $ja4): VisitorErasure
    {
        $ip = self::normalizeIp($ip);
        $ja4 = blank($ja4) ? null : trim($ja4);

        $erasure = $team->visitorErasures()->create([
            'user_id' => $user?->id,
            'subject_hash' => VisitorErasure::hashSubject($ip, $ja4),
            'subject_kind' => match (true) {
                $ip !== null && $ja4 !== null => 'both',
                $ip !== null => 'ip',
                default => 'ja4',
            },
        ]);

        ForgetVisitorJob::dispatch($erasure, $ip, $ja4);

        return $erasure;
    }

    /**
     * Erase the visitor from everything that keeps visitors' data, and note what went.
     */
    public function handle(VisitorErasure $erasure, ?string $ip, ?string $ja4): void
    {
        $deleted = $this->holders->forget($erasure->team, $ip, $ja4);

        $erasure->update(['deleted' => $deleted, 'completed_at' => now()]);
    }

    /**
     * Write an IP address the way the database keeps it (IPv6 compressed), or null when it isn't one.
     */
    public static function normalizeIp(?string $ip): ?string
    {
        $packed = filter_var(trim((string) $ip), FILTER_VALIDATE_IP) === false ? false : inet_pton(trim((string) $ip));

        return $packed === false ? null : inet_ntop($packed);
    }
}
