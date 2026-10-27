<?php

namespace App\Actions\Clients;

use App\Data\IpNeighborhood;
use App\Models\Team;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Measure how busy some IPv4 addresses and their neighbors were in a team's traffic: each address's
 * requests and those of its /24, /16 and /8, over the last few hours.
 *
 * One query covers every address asked about: it counts the requests and hosts of each /24 inside
 * their /8s, setting the addresses themselves apart, and the larger prefixes are added up from those.
 * Each answer is cached for a few minutes, so a page that re-renders doesn't measure again.
 */
class MeasureIpNeighborhoods
{
    private const int CACHE_SECONDS = 300;

    /**
     * @param  list<string>  $ips
     * @return array<string, IpNeighborhood> Keyed by IP. Addresses that aren't IPv4 are left out.
     */
    public function handle(Team $team, array $ips, ?int $hours = null): array
    {
        $hours ??= (int) config('activity.neighborhood.hours');
        $ips = array_values(array_unique(array_filter($ips, fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)));

        if ($ips === []) {
            return [];
        }

        $cacheKey = fn (string $ip) => "activity.neighborhood.{$team->id}.{$hours}.{$ip}";
        $cached = Cache::many(array_map($cacheKey, $ips));
        $missing = array_values(array_filter($ips, fn (string $ip) => $cached[$cacheKey($ip)] === null));
        $measured = $this->measure($team, $missing, $hours);

        if ($measured !== []) {
            Cache::putMany(array_combine(
                array_map($cacheKey, array_keys($measured)),
                array_map(fn (IpNeighborhood $neighborhood) => $neighborhood->toArray(), $measured),
            ), self::CACHE_SECONDS);
        }

        $neighborhoods = [];

        foreach ($ips as $ip) {
            $neighborhoods[$ip] = $measured[$ip] ?? IpNeighborhood::fromArray($cached[$cacheKey($ip)]);
        }

        return $neighborhoods;
    }

    /**
     * @param  list<string>  $ips
     * @return array<string, IpNeighborhood>
     */
    private function measure(Team $team, array $ips, int $hours): array
    {
        if ($ips === []) {
            return [];
        }

        $isPostgres = DB::connection()->getDriverName() === 'pgsql';
        $firstOctets = array_values(array_unique(array_map(fn (string $ip) => strtok($ip, '.'), $ips)));
        $placeholders = implode(', ', array_fill(0, count($ips), '?'));

        // Postgres keeps the IP as inet; elsewhere (the tests' SQLite) it's text.
        [$slash24, $host, $inSlash8] = $isPostgres
            ? ['host(network(set_masklen(client_ip, 24)))', "case when client_ip in ({$placeholders}) then host(client_ip) end", 'client_ip << ?::inet']
            : ["rtrim(client_ip, '0123456789') || '0'", "case when client_ip in ({$placeholders}) then client_ip end", 'client_ip like ?'];

        $rows = DB::table('edge_requests')
            ->selectRaw("{$slash24} as network, {$host} as host, count(*) as hits, count(distinct client_ip) as hosts", $ips)
            ->whereIn('domain_id', $team->domains()->select('id'))
            ->where('occurred_at', '>=', now()->subHours($hours))
            ->where(function ($query) use ($firstOctets, $inSlash8, $isPostgres) {
                foreach ($firstOctets as $octet) {
                    $query->orWhereRaw($inSlash8, [$isPostgres ? "{$octet}.0.0.0/8" : "{$octet}.%"]);
                }
            })
            ->groupByRaw('1, 2')
            ->get();

        $neighborhoods = [];

        foreach ($ips as $ip) {
            [$a, $b, $c] = explode('.', $ip);
            $sum = fn (string $column, callable $within) => (int) $rows->filter($within)->sum($column);

            $neighborhoods[$ip] = new IpNeighborhood(
                ip: $ip,
                count8: $sum('hits', fn (object $row) => str_starts_with($row->network, "{$a}.")),
                count16: $sum('hits', fn (object $row) => str_starts_with($row->network, "{$a}.{$b}.")),
                count24: $sum('hits', fn (object $row) => $row->network === "{$a}.{$b}.{$c}.0"),
                count32: $sum('hits', fn (object $row) => $row->host === $ip),
                distinctHosts24: $sum('hosts', fn (object $row) => $row->network === "{$a}.{$b}.{$c}.0"),
            );
        }

        return $neighborhoods;
    }
}
