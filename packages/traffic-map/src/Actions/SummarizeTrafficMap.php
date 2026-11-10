<?php

namespace NiceTry\TrafficMap\Actions;

use App\Actions\Rules\BuildRuleQuery;
use App\Enums\NetworkCategory;
use App\Enums\NetworkKind;
use App\Enums\PathCategory;
use App\Enums\RequestAction;
use App\Enums\TrafficPeriod;
use App\Models\BotTag;
use App\Models\EdgeRequest;
use App\Models\Network;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Locale;
use NiceTry\TrafficMap\Data\TreemapBlock;
use NiceTry\TrafficMap\Data\TreemapClient;
use NiceTry\TrafficMap\Data\TreemapData;
use NiceTry\TrafficMap\Data\TreemapTile;
use NiceTry\TrafficMap\Enums\MapDimension;
use NiceTry\TrafficMap\Support\Squarify;

class SummarizeTrafficMap
{
    private const int TOP = 24;

    private const int TOP_BLOCKS = 16;

    private const int TOP_LEAVES = 12;

    /**
     * Group a team's recent edge requests into treemap tiles: sized by volume, coloured by the share
     * the edge did not pass to origin (observed, sent to the gym, or blocked). With a secondary
     * dimension, each primary tile is subdivided by it.
     *
     * @param  array<string, mixed>|null  $where  a parsed Activity search (App\Actions\Rules\BuildRuleQuery)
     */
    public function handle(
        Team $team,
        MapDimension $dimension,
        TrafficPeriod $period,
        ?int $domainId = null,
        ?array $where = null,
        ?MapDimension $secondary = null,
        ?CarbonImmutable $now = null,
    ): TreemapData {
        $since = $period->start($now);

        $base = fn (): Builder => EdgeRequest::query()->toBase()
            ->whereIn('edge_requests.domain_id', $team->domains()->select('id'))
            ->when($domainId !== null, fn (Builder $query) => $query->where('edge_requests.domain_id', $domainId))
            ->where('edge_requests.occurred_at', '>=', $since);

        // Any Activity search is applied through a subquery of matching ids, so its conditional joins
        // never clash with the join this grouping adds. The subquery is built from the team's
        // edgeRequests relation, which joins `domains`, so fields like `host` (domains.hostname) resolve.
        $ids = null;

        if ($where) {
            $ids = $team->edgeRequests()
                ->when($domainId !== null, fn ($query) => $query->where('edge_requests.domain_id', $domainId))
                ->where('edge_requests.occurred_at', '>=', $since)
                ->toBase()
                ->select('edge_requests.id');
            app(BuildRuleQuery::class)->constrain($ids, $team, $where);
        }

        $measures = 'count(*) as requests, sum(case when edge_requests.action <> ? then 1 else 0 end) as suspicious';
        $bindings = [RequestAction::Passed->value];

        if ($secondary !== null && $secondary !== $dimension && $dimension !== MapDimension::Team) {
            return $this->twoLevel($dimension, $secondary, $base, $ids, $measures, $bindings, $team);
        }

        return $this->oneLevel($dimension, $base, $ids, $measures, $bindings, $team);
    }

    /**
     * The grouping column/expression and the joins a dimension needs.
     *
     * @param  callable(): Builder  $base
     * @return array{0: string, 1: list<array{0: string, 1: string, 2: string}>}
     */
    private function spec(MapDimension $dimension, callable $base): array
    {
        return match ($dimension) {
            MapDimension::Domain => ['edge_requests.domain_id', []],
            MapDimension::Ip => ['edge_requests.client_ip', []],
            MapDimension::BotTag => ['edge_requests.bot_tag_id', []],
            MapDimension::Country => ['locations.country_code', [['locations', 'locations.id', 'edge_requests.location_id']]],
            MapDimension::Path => ['request_paths.category', [['request_paths', 'request_paths.id', 'edge_requests.path_id']]],
            MapDimension::Client => [$this->clientFamily($base), [
                ['user_agent_profiles', 'user_agent_profiles.user_agent_id', 'edge_requests.user_agent_id'],
                ['client_hint_profiles', 'client_hint_profiles.client_hint_id', 'edge_requests.client_hints_id'],
            ]],
            MapDimension::NetworkCategory => [$this->networkCategory(), [
                ['network_categories', 'network_categories.asn', 'edge_requests.asn'],
                ['networks', 'networks.asn', 'edge_requests.asn'],
            ]],
            MapDimension::Team => ['0', []],
        };
    }

    // ---- one level ----

    /**
     * @param  callable(): Builder  $base
     */
    private function oneLevel(MapDimension $dimension, callable $base, ?Builder $ids, string $measures, array $bindings, Team $team): TreemapData
    {
        if ($dimension === MapDimension::Team) {
            $row = (object) $this->grouped($base(), $ids)->selectRaw($measures, $bindings)->first();
            $requests = (int) ($row->requests ?? 0);
            $rows = $requests === 0 ? [] : [[
                'key' => 't'.$team->id, 'sym' => mb_strtoupper($team->name), 'sub' => null,
                'requests' => $requests, 'suspicious' => (int) ($row->suspicious ?? 0),
            ]];

            return $this->flatten($dimension, $rows);
        }

        [$expr, $joins] = $this->spec($dimension, $base);
        $hostnames = $dimension === MapDimension::Domain ? $team->domains()->pluck('hostname', 'id') : collect();
        $botTags = $dimension === MapDimension::BotTag ? BotTag::pluck('name', 'id') : collect();

        // The IP view carries each address's country, for its flag, and its network.
        $extra = '';
        if ($dimension === MapDimension::Ip) {
            $joins[] = ['locations', 'locations.id', 'edge_requests.location_id'];
            $extra = ', max(locations.country_code) as cc, max(edge_requests.asn) as asn';
        }

        $query = $this->grouped($base(), $ids);
        $query = $this->applyJoins($query, $joins);

        $rows = $query
            ->selectRaw("{$expr} as row_key{$extra}, {$measures}", $bindings)
            ->groupByRaw($expr)
            ->orderByDesc('requests')
            ->get()
            ->map(function (object $row) use ($dimension, $hostnames, $botTags): array {
                [$id, $sym, $sub] = $this->describe($dimension, $row->row_key, $row->cc ?? null, $hostnames, $botTags);

                return [
                    'key' => $id, 'sym' => $sym, 'sub' => $sub, 'requests' => (int) $row->requests, 'suspicious' => (int) $row->suspicious,
                    'ip' => $dimension === MapDimension::Ip ? $row->row_key : null, 'asn' => $row->asn ?? null,
                ];
            })
            ->all();

        return $this->flatten($dimension, $rows);
    }

    /**
     * Fold the long tail into one OTHERS tile, lay the tiles out, and colour them.
     *
     * @param  list<array{key: string, sym: string, sub: string|null, requests: int, suspicious: int, ip?: mixed, asn?: mixed}>  $rows
     */
    private function flatten(MapDimension $dimension, array $rows): TreemapData
    {
        $rows = $this->trim($rows, self::TOP);
        $groups = $this->countBefore;
        $totalRequests = array_sum(array_column($rows, 'requests'));
        $totalSuspicious = array_sum(array_column($rows, 'suspicious'));

        $rects = Squarify::rects(array_map(static fn (array $row): int => $row['requests'], $rows));
        $clients = $this->clients($rows);

        $tiles = [];
        foreach ($rows as $i => $row) {
            $rect = $rects[$i] ?? ['x' => 0.0, 'y' => 0.0, 'w' => 0.0, 'h' => 0.0];
            $tiles[] = $this->tile($row['key'], $row['sym'], $row['sub'], $row['requests'], $row['suspicious'], $rect, client: $clients[$row['ip'] ?? ''] ?? null);
        }

        return new TreemapData($dimension, null, $tiles, [], $totalRequests, $totalSuspicious, $groups);
    }

    // ---- two levels ----

    /**
     * @param  callable(): Builder  $base
     */
    private function twoLevel(MapDimension $primary, MapDimension $secondary, callable $base, ?Builder $ids, string $measures, array $bindings, Team $team): TreemapData
    {
        [$pExpr, $pJoins] = $this->spec($primary, $base);
        [$sExpr, $sJoins] = $this->spec($secondary, $base);

        $joins = [];
        foreach ([...$pJoins, ...$sJoins] as $join) {
            $joins[$join[0]] = $join;
        }

        // The IP view (on either side) carries each address's country, for its flag, and its network.
        $extra = '';
        if ($primary === MapDimension::Ip || $secondary === MapDimension::Ip) {
            $joins['locations'] = ['locations', 'locations.id', 'edge_requests.location_id'];
            $extra .= $primary === MapDimension::Ip ? ', max(locations.country_code) as p_cc, max(edge_requests.asn) as p_asn' : '';
            $extra .= $secondary === MapDimension::Ip ? ', max(locations.country_code) as s_cc, max(edge_requests.asn) as s_asn' : '';
        }

        $query = $this->grouped($base(), $ids);
        $query = $this->applyJoins($query, array_values($joins));

        $records = $query
            ->selectRaw("{$pExpr} as p_key, {$sExpr} as s_key{$extra}, {$measures}", $bindings)
            ->groupByRaw("{$pExpr}, {$sExpr}")
            ->get();

        $hostnames = ($primary === MapDimension::Domain || $secondary === MapDimension::Domain) ? $team->domains()->pluck('hostname', 'id') : collect();
        $botTags = ($primary === MapDimension::BotTag || $secondary === MapDimension::BotTag) ? BotTag::pluck('name', 'id') : collect();

        // Gather primaries with their children.
        $primaries = [];
        foreach ($records as $row) {
            $pid = $this->describe($primary, $row->p_key, $row->p_cc ?? null, $hostnames, $botTags);
            $key = $pid[0];
            $primaries[$key] ??= ['key' => $key, 'sym' => $pid[1], 'requests' => 0, 'suspicious' => 0, 'children' => [], 'ip' => $primary === MapDimension::Ip ? $row->p_key : null, 'asn' => null];
            $primaries[$key]['asn'] ??= $row->p_asn ?? null;
            $primaries[$key]['requests'] += (int) $row->requests;
            $primaries[$key]['suspicious'] += (int) $row->suspicious;
            [$sid, $ssym, $ssub] = $this->describe($secondary, $row->s_key, $row->s_cc ?? null, $hostnames, $botTags);
            $primaries[$key]['children'][] = [
                'key' => $sid, 'sym' => $ssym, 'sub' => $ssub, 'requests' => (int) $row->requests, 'suspicious' => (int) $row->suspicious,
                'ip' => $secondary === MapDimension::Ip ? $row->s_key : null, 'asn' => $row->s_asn ?? null,
            ];
        }

        $primaries = array_values($primaries);
        usort($primaries, static fn (array $a, array $b): int => $b['requests'] <=> $a['requests']);

        $totalRequests = array_sum(array_column($primaries, 'requests'));
        $totalSuspicious = array_sum(array_column($primaries, 'suspicious'));
        $groups = count($primaries);

        $primaries = array_map(fn (array $row): array => ['children' => $this->trim($row['children'], self::TOP_LEAVES)] + $row, $this->trimPrimaries($primaries, self::TOP_BLOCKS));

        $rects = Squarify::rects(array_map(static fn (array $p): int => $p['requests'], $primaries));
        $clients = $this->clients([...$primaries, ...array_merge(...array_column($primaries, 'children'))]);

        $blocks = [];
        foreach ($primaries as $i => $primaryRow) {
            $rect = $rects[$i] ?? ['x' => 0.0, 'y' => 0.0, 'w' => 0.0, 'h' => 0.0];
            $blocks[] = $this->block($primaryRow, $rect, $clients);
        }

        return new TreemapData($primary, $secondary, [], $blocks, $totalRequests, $totalSuspicious, $groups);
    }

    /**
     * Build one block (a primary group), subdivided by its children unless it is too small or is the
     * folded OTHERS group.
     *
     * @param  array{key: string, sym: string, requests: int, suspicious: int, children: list<array{key: string, sym: string, sub: string|null, requests: int, suspicious: int, ip?: string|null}>, others?: bool, ip?: string|null}  $primaryRow
     * @param  array{x: float, y: float, w: float, h: float}  $rect
     * @param  array<string, TreemapClient>  $clients
     */
    private function block(array $primaryRow, array $rect, array $clients): TreemapBlock
    {
        $share = $primaryRow['requests'] > 0 ? $primaryRow['suspicious'] / $primaryRow['requests'] : 0.0;
        $children = $primaryRow['children'];
        $client = $clients[$primaryRow['ip'] ?? ''] ?? null;

        // Subdivide only a block big enough to carry a header strip and still show readable leaves.
        $subdivide = ! ($primaryRow['others'] ?? false)
            && count($children) > 1
            && $rect['w'] >= 10 && $rect['h'] >= 8;

        $blockSize = $this->sizeTier($rect['w'], $rect['h']);

        if (! $subdivide) {
            // One tile filling the block's body (coordinates are relative to the block).
            $leaf = $this->tile(
                $primaryRow['key'].'/all', $primaryRow['sym'], null,
                $primaryRow['requests'], $primaryRow['suspicious'],
                ['x' => 0.0, 'y' => 0.0, 'w' => 100.0, 'h' => 100.0], $blockSize, $client,
            );

            return $this->makeBlock($primaryRow, $rect, $share, $blockSize, false, [$leaf], $client);
        }

        // Leaves are laid out in the block's body (a 0..100 box), so the header never overlaps them.
        $subRects = Squarify::rects(array_map(static fn (array $c): int => $c['requests'], $children));

        $leaves = [];
        foreach ($children as $j => $child) {
            $sub = $subRects[$j] ?? ['x' => 0.0, 'y' => 0.0, 'w' => 0.0, 'h' => 0.0];
            // Font sizing follows the leaf's true on-screen size, not its size within the small body.
            $onScreen = $this->sizeTier($rect['w'] * $sub['w'] / 100, $rect['h'] * $sub['h'] / 100);
            $leaves[] = $this->tile($primaryRow['key'].'/'.$child['key'], $child['sym'], $child['sub'], $child['requests'], $child['suspicious'], $sub, $onScreen, $clients[$child['ip'] ?? ''] ?? null);
        }

        return $this->makeBlock($primaryRow, $rect, $share, $blockSize, true, $leaves, $client);
    }

    /**
     * @param  array<string, mixed>  $primaryRow
     * @param  array{x: float, y: float, w: float, h: float}  $rect
     * @param  list<TreemapTile>  $tiles
     */
    private function makeBlock(array $primaryRow, array $rect, float $share, string $size, bool $subdivided, array $tiles, ?TreemapClient $client): TreemapBlock
    {
        return new TreemapBlock(
            key: $primaryRow['key'], sym: $primaryRow['sym'],
            requests: $primaryRow['requests'], suspicious: $primaryRow['suspicious'],
            x: round($rect['x'], 3), y: round($rect['y'], 3), w: round($rect['w'], 3), h: round($rect['h'], 3),
            color: $this->color($share), size: $size, subdivided: $subdivided, tiles: $tiles, client: $client,
        );
    }

    // ---- shared helpers ----

    private int $countBefore = 0;

    /**
     * Sort rows by volume, keep the biggest, fold the rest into one OTHERS row. Records the group count
     * before folding in $countBefore.
     *
     * @param  list<array{key: string, sym: string, sub: string|null, requests: int, suspicious: int, ip?: mixed, asn?: mixed}>  $rows
     * @return list<array{key: string, sym: string, sub: string|null, requests: int, suspicious: int, ip?: mixed, asn?: mixed}>
     */
    private function trim(array $rows, int $limit): array
    {
        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['requests'] > 0));
        usort($rows, static fn (array $a, array $b): int => $b['requests'] <=> $a['requests']);
        $this->countBefore = count($rows);

        if (count($rows) <= $limit) {
            return $rows;
        }

        $kept = array_slice($rows, 0, $limit);
        $rest = array_slice($rows, $limit);
        $kept[] = [
            'key' => 'others', 'sym' => count($rest).' '.__('OTHERS'), 'sub' => null,
            'requests' => array_sum(array_column($rest, 'requests')),
            'suspicious' => array_sum(array_column($rest, 'suspicious')),
        ];

        return $kept;
    }

    /**
     * Like trim(), but marks the folded OTHERS primary so it is drawn solid, not subdivided.
     *
     * @param  list<array{key: string, sym: string, requests: int, suspicious: int, children: list<mixed>}>  $primaries
     * @return list<array<string, mixed>>
     */
    private function trimPrimaries(array $primaries, int $limit): array
    {
        if (count($primaries) <= $limit) {
            return $primaries;
        }

        $kept = array_slice($primaries, 0, $limit);
        $rest = array_slice($primaries, $limit);
        $kept[] = [
            'key' => 'others', 'sym' => count($rest).' '.__('OTHERS'),
            'requests' => array_sum(array_column($rest, 'requests')),
            'suspicious' => array_sum(array_column($rest, 'suspicious')),
            'children' => [], 'others' => true,
        ];

        return $kept;
    }

    /**
     * @param  array{x: float, y: float, w: float, h: float}  $rect
     */
    private function tile(string $key, string $sym, ?string $sub, int $requests, int $suspicious, array $rect, ?string $size = null, ?TreemapClient $client = null): TreemapTile
    {
        $share = $requests > 0 ? $suspicious / $requests : 0.0;

        return new TreemapTile(
            key: $key, sym: $sym, sub: $sub, requests: $requests, suspicious: $suspicious,
            x: round($rect['x'], 3), y: round($rect['y'], 3), w: round($rect['w'], 3), h: round($rect['h'], 3),
            color: $this->color($share), hot: $share >= 0.72 && $requests > 12, size: $size ?? $this->sizeTier($rect['w'], $rect['h']), client: $client,
        );
    }

    /**
     * The clients among the rows that made it onto the map, by IP, each with its network. Looked up
     * only for these, since a busy window has thousands of addresses and most fold into OTHERS.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, TreemapClient>
     */
    private function clients(array $rows): array
    {
        $rows = array_filter($rows, static fn (array $row): bool => ($row['ip'] ?? null) !== null);

        if ($rows === []) {
            return [];
        }

        $networks = Network::query()
            ->with('networkCategory')
            ->whereIn('asn', array_values(array_unique(array_filter(array_column($rows, 'asn')))))
            ->get()
            ->keyBy('asn');

        $clients = [];
        foreach ($rows as $row) {
            $network = $networks[$row['asn']] ?? null;
            $clients[$row['ip']] = new TreemapClient($row['ip'], $row['asn'] === null ? null : (int) $row['asn'], $network?->name, $network?->shownCategory()?->label());
        }

        return $clients;
    }

    private function grouped(Builder $query, ?Builder $ids): Builder
    {
        return $ids === null ? $query : $query->whereIn('edge_requests.id', $ids);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $joins
     */
    private function applyJoins(Builder $query, array $joins): Builder
    {
        foreach ($joins as [$table, $first, $second]) {
            $query->leftJoin($table, $first, '=', $second);
        }

        return $query;
    }

    /**
     * A SQL CASE for the category a network is shown under, as App\Models\Network::shownCategory() has
     * it, so these tiles agree with the ones an IP's network is named on.
     */
    private function networkCategory(): string
    {
        return 'case when network_categories.asn is not null then network_categories.category '
            .'when networks.kind = '.NetworkKind::Hosting->value.' then '.NetworkCategory::Hosting->value.' end';
    }

    /**
     * A SQL CASE that labels each request's client profile, approximating App\Enums\ClientTrait: the
     * "current" major version is taken from the busiest versions in view rather than a release feed.
     *
     * @param  callable(): Builder  $base
     */
    private function clientFamily(callable $base): string
    {
        $current = $base()
            ->leftJoin('user_agent_profiles', 'user_agent_profiles.user_agent_id', '=', 'edge_requests.user_agent_id')
            ->whereIn('user_agent_profiles.browser', [1, 3])
            ->whereNotNull('user_agent_profiles.major_version')
            ->groupBy('user_agent_profiles.browser')
            ->selectRaw('user_agent_profiles.browser as b, max(user_agent_profiles.major_version) as mx')
            ->pluck('mx', 'b');

        $chrome = (int) ($current[1] ?? 0);
        $firefox = (int) ($current[3] ?? 0);
        $ua = 'user_agent_profiles';
        $hint = 'client_hint_profiles';

        $spoofed = "(({$hint}.platform is not null and {$hint}.platform not in (0,6) and {$hint}.platform <> {$ua}.platform "
            ."and not ({$ua}.platform = 3 and {$hint}.platform = 5)) "
            ."or ({$ua}.browser = 1 and {$ua}.platform in (1,2,3,4,5) and {$ua}.major_version >= 100 "
            .'and edge_requests.client_hints_id is null and edge_requests.method <> 7))';

        $outdated = "({$ua}.browser in (1,3) and {$ua}.major_version is not null and {$ua}.major_version <= "
            ."((case when {$ua}.browser = 1 then {$chrome} when {$ua}.browser = 3 then {$firefox} else 0 end) "
            ."- (case when {$ua}.platform = 5 then 3 else 6 end)))";

        return 'case '
            ."when {$ua}.browser = 4 then 'declared bot' "
            ."when {$ua}.browser is null or {$ua}.browser = 0 then 'unknown' "
            ."when {$spoofed} and {$outdated} then 'spoofed + outdated' "
            ."when {$spoofed} then 'spoofed' "
            ."when {$outdated} then 'outdated' "
            ."else 'clean' end";
    }

    /**
     * Label a raw grouped key for a dimension.
     *
     * @param  Collection<int, string>  $hostnames
     * @param  Collection<int, string>  $botTags
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function describe(MapDimension $dimension, mixed $key, ?string $cc, Collection $hostnames, Collection $botTags): array
    {
        return match ($dimension) {
            MapDimension::Domain => $this->domainLabel($key, $hostnames),
            MapDimension::Ip => $key === null
                ? ['ip-none', __('No IP'), null]
                : ['ip'.$key, ($cc !== null && $cc !== '' ? $this->flag($cc).' ' : '').$key, $cc !== null && $cc !== '' ? (Locale::getDisplayRegion('-'.$cc, app()->getLocale()) ?: $cc) : null],
            MapDimension::BotTag => $key === null
                ? ['untagged', mb_strtoupper(__('Untagged')), null]
                : ['b'.$key, mb_strtoupper((string) ($botTags[(int) $key] ?? __('Untagged'))), null],
            MapDimension::Country => $key === null
                ? ['??', '🏁 ??', __('Unknown')]
                : ['c'.$key, $this->flag((string) $key).' '.$key, Locale::getDisplayRegion('-'.$key, app()->getLocale()) ?: (string) $key],
            MapDimension::Path => $key === null
                ? ['page', mb_strtoupper(__('Page')), __('Ordinary pages')]
                : ['p'.$key, mb_strtoupper(PathCategory::from((int) $key)->slug()), PathCategory::from((int) $key)->slug()],
            MapDimension::Client => ['cl'.$key, mb_strtoupper((string) $key), null],
            MapDimension::NetworkCategory => $key === null
                ? ['nc-none', mb_strtoupper(__('No category')), null]
                : ['nc'.$key, mb_strtoupper(NetworkCategory::from((int) $key)->label()), null],
            MapDimension::Team => ['t'.$key, mb_strtoupper((string) $key), null],
        };
    }

    /**
     * @param  Collection<int, string>  $hostnames
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function domainLabel(mixed $key, Collection $hostnames): array
    {
        $hostname = (string) ($hostnames[(int) $key] ?? __('Unknown site'));
        $first = explode('.', $hostname)[0] ?: $hostname;

        return ['d'.$key, mb_strtoupper($first), $hostname];
    }

    /**
     * A green → amber → red colour for a suspect share in 0..1.
     */
    private function color(float $share): string
    {
        $stops = [[0.0, [46, 125, 87]], [0.5, [201, 167, 62]], [1.0, [194, 66, 54]]];
        $share = max(0.0, min(1.0, $share));

        for ($i = 0; $i < count($stops) - 1; $i++) {
            [$from, $fromRgb] = $stops[$i];
            [$to, $toRgb] = $stops[$i + 1];

            if ($share >= $from && $share <= $to) {
                $t = ($to - $from) > 0 ? ($share - $from) / ($to - $from) : 0.0;
                $r = (int) round($fromRgb[0] + ($toRgb[0] - $fromRgb[0]) * $t);
                $g = (int) round($fromRgb[1] + ($toRgb[1] - $fromRgb[1]) * $t);
                $b = (int) round($fromRgb[2] + ($toRgb[2] - $fromRgb[2]) * $t);

                return "rgb({$r},{$g},{$b})";
            }
        }

        return 'rgb(80,80,80)';
    }

    private function sizeTier(float $w, float $h): string
    {
        $area = $w * $h;
        $min = min($w, $h);

        return match (true) {
            $area >= 11 && $min >= 14 => 'xl',
            $area >= 4.5 && $min >= 9 => 'l',
            $area >= 1.6 && $min >= 6 => 'm',
            $area >= 0.6 => 's',
            default => 'xs',
        };
    }

    private function flag(string $code): string
    {
        if (preg_match('/^[A-Za-z]{2}$/', $code) !== 1) {
            return '🏁';
        }

        $code = mb_strtoupper($code);

        return mb_chr(127397 + mb_ord($code[0])).mb_chr(127397 + mb_ord($code[1]));
    }
}
