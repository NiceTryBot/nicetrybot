<?php

namespace App\Actions\Edge;

use App\Actions\Clients\RecordClientProfiles;
use App\Enums\NetworkKind;
use App\Enums\PathCategory;
use App\Enums\TlsFingerprintType;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Turn repeated edge-log strings into lookup-table ids, inserting any values seen for the first time.
 *
 * Every method works on a whole batch, a few queries per chunk, so importing a file costs a few queries
 * per lookup table rather than one per request. Values already known are looked up before anything is
 * inserted: Postgres spends a sequence number on every row an insert-or-ignore offers, even one it
 * ignores, and offering every value of every batch once ran bot_tags' smallint ids out.
 */
class ResolveLookupIds
{
    /**
     * The number of values written or read per query.
     */
    private const int CHUNK_SIZE = 500;

    public function __construct(private RecordClientProfiles $recordClientProfiles)
    {
        //
    }

    /**
     * @param  iterable<string>  $paths
     * @return array<string, int> Ids keyed by path.
     */
    public function paths(iterable $paths): array
    {
        return $this->byHash('request_paths', 'path', $paths, fn (string $path) => ['category' => PathCategory::classify($path)?->value]);
    }

    /**
     * @param  iterable<string>  $userAgents
     * @return array<string, int> Ids keyed by user agent.
     */
    public function userAgents(iterable $userAgents): array
    {
        $ids = $this->byHash('user_agents', 'value', $userAgents);

        $this->recordClientProfiles->userAgents($ids);

        return $ids;
    }

    /**
     * @param  iterable<string>  $clientHints
     * @return array<string, int> Ids keyed by client hints value.
     */
    public function clientHints(iterable $clientHints): array
    {
        $ids = $this->byHash('client_hints', 'value', $clientHints);

        $this->recordClientProfiles->clientHints($ids);

        return $ids;
    }

    /**
     * @param  iterable<string>  $names
     * @return array<string, int> Ids keyed by tag name.
     */
    public function botTags(iterable $names): array
    {
        return $this->byName('bot_tags', $names);
    }

    /**
     * @param  iterable<string>  $names
     * @return array<string, int> Ids keyed by component name.
     */
    public function livewireComponents(iterable $names): array
    {
        return $this->byName('livewire_components', $names);
    }

    /**
     * @param  iterable<string>  $values
     * @return array<string, int> Ids keyed by fingerprint value.
     */
    public function fingerprints(TlsFingerprintType $type, iterable $values): array
    {
        $values = $this->distinct($values);
        $ids = [];

        $find = fn (array $values) => DB::table('tls_fingerprints')->where('type', $type->value)->whereIn('value', $values)->pluck('id', 'value')->all();

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            $found = $find($chunk);
            $missing = array_values(array_diff($chunk, array_keys($found)));

            if ($missing !== []) {
                DB::table('tls_fingerprints')->insertOrIgnore(array_map(fn (string $value) => ['type' => $type->value, 'value' => $value], $missing));
                $found += $find($missing);
            }

            $ids += $found;
        }

        if ($type === TlsFingerprintType::Ja4) {
            $this->recordClientProfiles->ja4Fingerprints($ids);
        }

        return $ids;
    }

    /**
     * Record each network's name and kind, refreshing both so changes to the hosting list apply on the next sighting.
     *
     * @param  array<int, string>  $namesByAsn
     */
    public function networks(array $namesByAsn): void
    {
        $rows = [];

        foreach ($namesByAsn as $asn => $name) {
            $rows[] = ['asn' => $asn, 'name' => mb_substr($name, 0, 255), 'kind' => NetworkKind::forAsn($asn)->value];
        }

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            DB::table('networks')->upsert($chunk, ['asn'], ['name', 'kind']);
        }
    }

    /**
     * @param  iterable<array{0: string, 1: string, 2: string, 3?: float|null, 4?: float|null}>  $places  Country code, region,
     *                                                                                                    city, and the latitude and longitude when known.
     * @return array<string, int> Ids keyed by Location::key().
     */
    public function locations(iterable $places): array
    {
        $rows = [];

        foreach ($places as $place) {
            [$countryCode, $region, $city] = $place;
            $row = ['country_code' => strtoupper($countryCode), 'region' => mb_substr($region, 0, 128), 'city' => mb_substr($city, 0, 128)];
            $key = Location::key(...array_values($row));

            // A place keeps the first coordinates it's seen with.
            $rows[$key] = [...$row, 'latitude' => $rows[$key]['latitude'] ?? $place[3] ?? null, 'longitude' => $rows[$key]['longitude'] ?? $place[4] ?? null];
        }

        $ids = [];

        foreach (array_chunk($rows, self::CHUNK_SIZE, preserve_keys: true) as $chunk) {
            $found = $this->locationIds($chunk);
            $missing = array_diff_key($chunk, $found);

            if ($missing !== []) {
                DB::table('locations')->insertOrIgnore(array_values($missing));
                $found += $this->locationIds($missing);
            }

            $ids += $found;
        }

        return $ids;
    }

    /**
     * Find the ids of the places already recorded, keyed by Location::key().
     *
     * @param  array<string, array{country_code: string, region: string, city: string, latitude: float|null, longitude: float|null}>  $rows
     * @return array<string, int>
     */
    private function locationIds(array $rows): array
    {
        $ids = [];

        DB::table('locations')
            ->whereIn('country_code', array_unique(array_column($rows, 'country_code')))
            ->get(['id', 'country_code', 'region', 'city', 'latitude'])
            ->each(function (object $location) use ($rows, &$ids) {
                $key = Location::key($location->country_code, $location->region, $location->city);

                if (! isset($rows[$key])) {
                    return;
                }

                $ids[$key] = $location->id;

                // Places recorded before the edge sent coordinates get them the first time they show up with some.
                if ($location->latitude === null && $rows[$key]['latitude'] !== null) {
                    DB::table('locations')->where('id', $location->id)->whereNull('latitude')->update([
                        'latitude' => $rows[$key]['latitude'],
                        'longitude' => $rows[$key]['longitude'],
                    ]);
                }
            });

        return $ids;
    }

    /**
     * Resolve long strings by their md5 hash, which carries the unique index.
     *
     * @param  iterable<string>  $values
     * @param  (callable(string): array<string, mixed>)|null  $extra  Extra columns to store with a new value.
     * @return array<string, int>
     */
    private function byHash(string $table, string $column, iterable $values, ?callable $extra = null): array
    {
        $valuesByHash = [];

        foreach ($this->distinct($values) as $value) {
            $valuesByHash[md5($value)] = $value;
        }

        $ids = [];

        $find = fn (array $hashes) => DB::table($table)->whereIn('hash', $hashes)->pluck('id', 'hash')->all();

        foreach (array_chunk($valuesByHash, self::CHUNK_SIZE, preserve_keys: true) as $chunk) {
            $found = $find(array_keys($chunk));
            $missing = array_diff_key($chunk, $found);

            if ($missing !== []) {
                DB::table($table)->insertOrIgnore(array_map(
                    fn (string $hash) => ['hash' => $hash, $column => $missing[$hash], ...($extra ? $extra($missing[$hash]) : [])],
                    array_keys($missing),
                ));
                $found += $find(array_keys($missing));
            }

            foreach ($found as $hash => $id) {
                $ids[$chunk[$hash]] = $id;
            }
        }

        return $ids;
    }

    /**
     * Resolve short names by a table's unique name column.
     *
     * @param  iterable<string>  $names
     * @return array<string, int>
     */
    private function byName(string $table, iterable $names): array
    {
        $names = $this->distinct($names);
        $ids = $this->pluck($table, 'name', $names);
        $missing = array_values(array_diff($names, array_keys($ids)));

        foreach (array_chunk($missing, self::CHUNK_SIZE) as $chunk) {
            DB::table($table)->insertOrIgnore(array_map(fn (string $name) => ['name' => $name], $chunk));
        }

        return $missing === [] ? $ids : $ids + $this->pluck($table, 'name', $missing);
    }

    /**
     * @param  list<string>  $values
     * @return array<string, int>
     */
    private function pluck(string $table, string $column, array $values): array
    {
        $ids = [];

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            $ids += DB::table($table)->whereIn($column, $chunk)->pluck('id', $column)->all();
        }

        return $ids;
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    private function distinct(iterable $values): array
    {
        $distinct = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $distinct[$value] = true;
            }
        }

        return array_map('strval', array_keys($distinct));
    }
}
