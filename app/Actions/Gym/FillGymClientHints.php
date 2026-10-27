<?php

namespace App\Actions\Gym;

use App\Enums\RequestAction;
use App\Models\GymSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FillGymClientHints
{
    /**
     * How far apart a gym session's start and the request that brought its visitor there can be.
     */
    private const int WINDOW_SECONDS = 120;

    /**
     * Give gym sessions the client hints their visitor sent with the request the edge answered with the
     * gym, from the edge's log. The edge's gym check doesn't pass client hints on, so a visitor that never
     * loads another gym page or makes an attempt (a scanner served a challenge in place of /.env) is only
     * heard here. A session that already has hints keeps them.
     *
     * @param  list<array{domain_id: int, client_ip: string|null, occurred_at: string, action: int, client_hints_id: int|null}>  $requests
     */
    public function handle(array $requests): void
    {
        $diverted = collect($requests)
            ->filter(fn (array $request) => $request['action'] === RequestAction::Diverted->value && $request['client_hints_id'] !== null && $request['client_ip'] !== null)
            ->unique(fn (array $request) => $request['domain_id'].'|'.$request['client_ip']);

        if ($diverted->isEmpty()) {
            return;
        }

        $teamIds = DB::table('domains')->whereIn('id', $diverted->pluck('domain_id')->unique()->values())->pluck('team_id', 'id');

        foreach ($diverted as $request) {
            $occurredAt = Carbon::parse($request['occurred_at']);

            GymSession::query()
                ->where('team_id', $teamIds->get($request['domain_id']))
                ->where('is_preview', false)
                ->whereBetween('started_at', [$occurredAt->copy()->subSeconds(self::WINDOW_SECONDS), $occurredAt->copy()->addSeconds(self::WINDOW_SECONDS)])
                ->where('domain_id', $request['domain_id'])
                ->where('client_ip', $request['client_ip'])
                ->whereNull('client_hints_id')
                ->update(['client_hints_id' => $request['client_hints_id']]);
        }
    }
}
