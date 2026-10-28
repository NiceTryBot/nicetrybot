<?php

namespace NiceTry\Tarpit\Activity;

use App\Models\EdgeRequest;
use App\Models\Team;
use App\Support\ActionAddon;
use App\Support\ActionBadge;
use App\Support\RequestAddon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use NiceTry\Tarpit\Models\TarpitHold;

/**
 * Tarpits on the Activity page: a Tarpit badge in the Action column of each request a tarpit touched
 * (in place of Passed, or beside In the gym when a routine took it after), with how long it was held,
 * and a sentence in the request's details saying which tarpit, why, and what came of it.
 */
class TarpitActivity implements ActionAddon, RequestAddon
{
    public function badges(Team $team, array $requestIds): array
    {
        $holds = $this->holds($requestIds);

        return array_map(fn (int $id) => ($hold = $holds->get($id)) === null ? null : new ActionBadge(
            badge: view('tarpit::activity.badge', ['hold' => $hold]),
            note: view('tarpit::activity.note', ['hold' => $hold]),
        ), $requestIds);
    }

    public function marks(Team $team, array $requestIds): array
    {
        return array_map(fn () => null, $requestIds);
    }

    public function details(Team $team, EdgeRequest $request): ?Htmlable
    {
        $hold = $this->holds([$request->id])->get($request->id);

        return $hold === null ? null : view('tarpit::activity.details', ['hold' => $hold, 'request' => $request]);
    }

    public function actions(Team $team, EdgeRequest $request): ?Htmlable
    {
        return null;
    }

    /**
     * @param  array<int, int>  $requestIds
     * @return Collection<int, TarpitHold>
     */
    private function holds(array $requestIds): Collection
    {
        return TarpitHold::query()
            ->whereIn('edge_request_id', array_values($requestIds))
            ->with('tarpit:id,name')
            ->get()
            ->keyBy('edge_request_id');
    }
}
