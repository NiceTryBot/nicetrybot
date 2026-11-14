<?php

namespace NiceTry\Honeypots\Activity;

use App\Models\EdgeRequest;
use App\Models\Team;
use App\Support\ActionAddon;
use App\Support\ActionBadge;
use App\Support\RequestAddon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use NiceTry\Honeypots\Enums\HoneypotOutcome;
use NiceTry\Honeypots\Models\HoneypotHit;

/**
 * Honeypots on the Activity page: a Honeypot badge in the Action column of each request a honeypot
 * answered (in place of Passed), with its status and how long it took, and a sentence in the request's
 * details saying which honeypot and trap. A request the honeypot couldn't answer keeps its Passed badge,
 * with a note that the site's own 404 went out.
 */
class HoneypotActivity implements ActionAddon, RequestAddon
{
    public function badges(Team $team, array $requestIds): array
    {
        $hits = $this->hits($requestIds);

        return array_map(fn (int $id) => ($hit = $hits->get($id)) === null ? null : new ActionBadge(
            badge: view('honeypots::activity.badge', ['hit' => $hit]),
            note: view('honeypots::activity.note', ['hit' => $hit]),
            replacesPassed: $hit->outcome === HoneypotOutcome::Served,
        ), $requestIds);
    }

    public function marks(Team $team, array $requestIds): array
    {
        return array_map(fn () => null, $requestIds);
    }

    public function details(Team $team, EdgeRequest $request): ?Htmlable
    {
        $hit = $this->hits([$request->id])->get($request->id);

        return $hit === null ? null : view('honeypots::activity.details', ['hit' => $hit]);
    }

    public function actions(Team $team, EdgeRequest $request): ?Htmlable
    {
        return null;
    }

    /**
     * @param  array<int, int>  $requestIds
     * @return Collection<int, HoneypotHit>
     */
    private function hits(array $requestIds): Collection
    {
        return HoneypotHit::query()
            ->whereIn('edge_request_id', array_values($requestIds))
            ->with(['honeypot:id,name', 'trap:id,patterns'])
            ->get()
            ->keyBy('edge_request_id');
    }
}
