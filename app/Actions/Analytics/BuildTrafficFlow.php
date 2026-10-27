<?php

namespace App\Actions\Analytics;

use App\Data\FlowNode;
use App\Data\Ja4Fingerprint;
use App\Data\SankeyDiagram;
use App\Enums\PathCategory;
use App\Enums\RequestAction;
use App\Enums\TrafficFlow;
use App\Enums\TrafficPeriod;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Network;
use App\Models\RequestPath;
use App\Models\Team;
use App\Models\TlsFingerprint;
use App\Models\UserAgent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Locale;

class BuildTrafficFlow
{
    /**
     * The longest gap between a visitor's requests that still counts as the same visit.
     */
    private const int VISIT_GAP_SECONDS = 1800;

    /**
     * The most recent requests read when reconstructing journeys.
     */
    private const int JOURNEY_REQUEST_LIMIT = 50_000;

    /**
     * Build the Sankey diagram for a flow report over a team's edge requests. With $groupPaths, a path that
     * matches a PathCategory pattern (e.g. any .env file) is shown as its category (probe/creds).
     */
    public function handle(Team $team, TrafficFlow $flow, TrafficPeriod $period, ?Domain $domain = null, ?CarbonImmutable $now = null, bool $groupPaths = true): SankeyDiagram
    {
        $query = fn (): Builder => EdgeRequest::query()->toBase()
            ->whereIn('edge_requests.domain_id', $team->domains()->select('id'))
            ->when($domain, fn (Builder $query) => $query->where('edge_requests.domain_id', $domain->id))
            ->where('edge_requests.occurred_at', '>=', $period->start($now));

        $paths = match ($flow) {
            TrafficFlow::Identity => $this->identityPaths($query()),
            TrafficFlow::Origins => $this->originPaths($query()),
            TrafficFlow::Requests => $this->requestPaths($query(), $groupPaths),
            TrafficFlow::Journeys => $this->journeyPaths($query(), $groupPaths),
        };

        return SankeyDiagram::fromPaths($flow->columns(), $paths);
    }

    /**
     * Network → TLS fingerprint → user agent.
     *
     * @return list<array{nodes: list<FlowNode>, value: int}>
     */
    private function identityPaths(Builder $query): array
    {
        $rows = $query->selectRaw('asn, ja4_id, user_agent_id, count(*) as requests')->groupBy('asn', 'ja4_id', 'user_agent_id')->get();

        $networks = $this->networkNames($rows->pluck('asn'));
        $fingerprints = $this->fingerprintValues($rows->pluck('ja4_id'));
        $userAgents = $this->userAgentValues($rows->pluck('user_agent_id'));

        return $rows->map(fn (object $row) => [
            'nodes' => [
                $this->networkNode($row->asn, $networks),
                $this->fingerprintNode($row->ja4_id, $fingerprints),
                $this->userAgentNode($row->user_agent_id, $userAgents),
            ],
            'value' => (int) $row->requests,
        ])->all();
    }

    /**
     * Country → network → outcome.
     *
     * @return list<array{nodes: list<FlowNode>, value: int}>
     */
    private function originPaths(Builder $query): array
    {
        $rows = $query
            ->leftJoin('locations', 'locations.id', '=', 'edge_requests.location_id')
            ->selectRaw('locations.country_code as country_code, edge_requests.asn as asn, edge_requests.status_code as status_code, edge_requests.action as action, count(*) as requests')
            ->groupBy('locations.country_code', 'edge_requests.asn', 'edge_requests.status_code', 'edge_requests.action')
            ->get();

        $networks = $this->networkNames($rows->pluck('asn'));

        return $rows->map(fn (object $row) => [
            'nodes' => [
                $this->countryNode($row->country_code),
                $this->networkNode($row->asn, $networks),
                $this->outcomeNode((int) $row->status_code, (int) $row->action),
            ],
            'value' => (int) $row->requests,
        ])->all();
    }

    /**
     * TLS fingerprint → user agent → path.
     *
     * @return list<array{nodes: list<FlowNode>, value: int}>
     */
    private function requestPaths(Builder $query, bool $groupPaths): array
    {
        $rows = $query->selectRaw('ja4_id, user_agent_id, path_id, count(*) as requests')->groupBy('ja4_id', 'user_agent_id', 'path_id')->get();

        $fingerprints = $this->fingerprintValues($rows->pluck('ja4_id'));
        $userAgents = $this->userAgentValues($rows->pluck('user_agent_id'));
        $paths = $this->pathValues($rows->pluck('path_id'));
        $members = $this->categoryMembers($paths);

        return $rows->map(fn (object $row) => [
            'nodes' => [
                $this->fingerprintNode($row->ja4_id, $fingerprints),
                $this->userAgentNode($row->user_agent_id, $userAgents),
                $this->pathNode($row->path_id, $paths, $groupPaths, $members),
            ],
            'value' => (int) $row->requests,
        ])->all();
    }

    /**
     * The first four distinct steps of each visit. A visitor is one client IP and TLS fingerprint,
     * a visit ends after 30 quiet minutes, and an immediate repeat of the same step counts once. With
     * $groupPaths a step is the path's category when it has one, so .env then .env.local is one step.
     *
     * @return list<array{nodes: list<FlowNode>, value: int}>
     */
    private function journeyPaths(Builder $query, bool $groupPaths): array
    {
        $requests = $query
            ->select(['id', 'client_ip', 'ja4_id', 'path_id', 'occurred_at'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::JOURNEY_REQUEST_LIMIT)
            ->get()
            ->sortBy([['client_ip', 'asc'], ['ja4_id', 'asc'], ['occurred_at', 'asc'], ['id', 'asc']]);

        $paths = $this->pathValues($requests->pluck('path_id'));
        $stepFor = fn (int $pathId): string => $groupPaths && ($paths[$pathId]['category'] ?? null)
            ? 'c:'.$paths[$pathId]['category']->value
            : 'p:'.$pathId;

        $journeys = [];
        $visitor = null;
        $lastSeen = null;
        $steps = [];

        $finish = function () use (&$steps, &$journeys) {
            if ($steps !== []) {
                $signature = implode(',', $steps);
                $journeys[$signature] = ($journeys[$signature] ?? 0) + 1;
            }

            $steps = [];
        };

        foreach ($requests as $request) {
            $currentVisitor = $request->client_ip.'|'.$request->ja4_id;
            $seenAt = CarbonImmutable::parse($request->occurred_at)->getTimestamp();

            if ($currentVisitor !== $visitor || $seenAt - $lastSeen > self::VISIT_GAP_SECONDS) {
                $finish();
                $visitor = $currentVisitor;
            }

            $lastSeen = $seenAt;

            $step = $stepFor((int) $request->path_id);

            if (count($steps) < 4 && end($steps) !== $step) {
                $steps[] = $step;
            }
        }

        $finish();

        $categoryPaths = $this->categoryMembers($paths);

        return collect($journeys)->map(fn (int $visits, string $signature) => [
            'nodes' => array_map(function (string $step) use ($paths, $categoryPaths) {
                if (str_starts_with($step, 'c:')) {
                    return $this->categoryNode(PathCategory::from((int) substr($step, 2)), $categoryPaths);
                }

                return $this->pathNode((int) substr($step, 2), $paths, false);
            }, explode(',', $signature)),
            'value' => $visits,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, int|null>  $asns
     * @return array<int, string>
     */
    private function networkNames(Collection $asns): array
    {
        return Network::query()->whereIn('asn', $asns->filter()->unique()->values())->pluck('name', 'asn')->all();
    }

    /**
     * @param  Collection<int, int|null>  $ids
     * @return array<int, string>
     */
    private function fingerprintValues(Collection $ids): array
    {
        return TlsFingerprint::query()->whereIn('id', $ids->filter()->unique()->values())->pluck('value', 'id')->all();
    }

    /**
     * @param  Collection<int, int|null>  $ids
     * @return array<int, string>
     */
    private function userAgentValues(Collection $ids): array
    {
        return UserAgent::query()->whereIn('id', $ids->filter()->unique()->values())->pluck('value', 'id')->all();
    }

    /**
     * @param  Collection<int, int|string|null>  $ids
     * @return array<int, array{path: string, category: PathCategory|null}>
     */
    private function pathValues(Collection $ids): array
    {
        return RequestPath::query()
            ->whereIn('id', $ids->filter()->unique()->values())
            ->get(['id', 'path', 'category'])
            ->mapWithKeys(fn (RequestPath $path) => [$path->id => ['path' => $path->path, 'category' => $path->category]])
            ->all();
    }

    /**
     * List the paths seen in each category, so a category node can say what it groups.
     *
     * @param  array<int, array{path: string, category: PathCategory|null}>  $paths
     * @return array<int, list<string>>
     */
    private function categoryMembers(array $paths): array
    {
        $members = [];

        foreach ($paths as $path) {
            if ($path['category'] !== null) {
                $members[$path['category']->value][] = $path['path'];
            }
        }

        return array_map(function (array $list) {
            sort($list);

            return $list;
        }, $members);
    }

    /**
     * @param  array<int, string>  $names
     */
    private function networkNode(?int $asn, array $names): FlowNode
    {
        return $asn === null
            ? new FlowNode('asn:none', __('Unknown network'))
            : new FlowNode('asn:'.$asn, $names[$asn] ?? 'AS'.$asn, ['asn' => $asn], 'AS'.$asn);
    }

    /**
     * @param  array<int, string>  $values
     */
    private function fingerprintNode(?int $id, array $values): FlowNode
    {
        if ($id === null || ! isset($values[$id])) {
            return new FlowNode('ja4:none', __('No fingerprint (plain HTTP)'));
        }

        return new FlowNode('ja4:'.$id, $values[$id], ['ja4' => $values[$id]], Ja4Fingerprint::tryFrom($values[$id])?->summary());
    }

    /**
     * @param  array<int, string>  $values
     */
    private function userAgentNode(?int $id, array $values): FlowNode
    {
        return $id === null || ! isset($values[$id])
            ? new FlowNode('ua:none', __('No user agent sent'))
            : new FlowNode('ua:'.$id, $values[$id], ['ua' => $id], $values[$id]);
    }

    /**
     * @param  array<int, array{path: string, category: PathCategory|null}>  $values
     * @param  array<int, list<string>>  $members  Paths seen per category, from categoryMembers().
     */
    private function pathNode(?int $id, array $values, bool $groupPaths, array $members = []): FlowNode
    {
        $value = $values[$id] ?? ['path' => '/', 'category' => null];

        if ($groupPaths && $value['category'] !== null) {
            return $this->categoryNode($value['category'], $members);
        }

        return new FlowNode('path:'.$id, $value['path'], ['path' => $id], $value['path']);
    }

    /**
     * A node that stands for every path matching a category's patterns.
     *
     * @param  array<int, list<string>>  $members
     */
    private function categoryNode(PathCategory $category, array $members): FlowNode
    {
        $paths = $members[$category->value] ?? [];
        $listed = implode(', ', array_slice($paths, 0, 6)).(count($paths) > 6 ? ', …' : '');

        return new FlowNode(
            'category:'.$category->value,
            $category->slug(),
            ['category' => $category->slug()],
            trim($category->description()."\n".trans_choice(':count path|:count paths', count($paths)).($listed !== '' ? ': '.$listed : '')),
        );
    }

    private function countryNode(?string $countryCode): FlowNode
    {
        return $countryCode === null
            ? new FlowNode('country:none', __('Unknown location'))
            : new FlowNode('country:'.$countryCode, Locale::getDisplayRegion('-'.$countryCode, app()->getLocale()) ?: $countryCode, ['country' => $countryCode], $countryCode);
    }

    /**
     * Describe how a request ended: what the edge did with it when tagged, otherwise the status class.
     */
    private function outcomeNode(int $status, int $action): FlowNode
    {
        $requestAction = RequestAction::tryFrom($action) ?? RequestAction::Passed;

        if ($requestAction->isSuspicious()) {
            return new FlowNode('action:'.$requestAction->slug(), $requestAction->label(), ['action' => $requestAction->slug()]);
        }

        return match (true) {
            $status === 404 => new FlowNode('status:404', __('404 Not found'), ['status' => '404']),
            $status === 429 => new FlowNode('status:429', __('429 Rate limited'), ['status' => '4xx']),
            $status >= 500 => new FlowNode('status:5xx', __('5xx Server error'), ['status' => '5xx']),
            $status >= 400 => new FlowNode('status:4xx', __('4xx Client error'), ['status' => '4xx']),
            $status >= 300 => new FlowNode('status:3xx', __('3xx Redirect'), ['status' => '3xx']),
            $status >= 200 => new FlowNode('status:2xx', __('2xx Success'), ['status' => '2xx']),
            default => new FlowNode('status:none', __('No response')),
        };
    }
}
