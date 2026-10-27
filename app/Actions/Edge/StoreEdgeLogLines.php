<?php

namespace App\Actions\Edge;

use App\Actions\Gym\FillGymClientHints;
use App\Enums\HttpMethod;
use App\Enums\RequestAction;
use App\Enums\TlsFingerprintType;
use App\Models\ClientHint;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Location;
use App\Support\EdgeLineAddon;
use App\Support\EdgeLineAddons;
use App\Support\PreviewHostname;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Store a batch of edge log lines as edge requests. Batches come from the S3 files the minute import
 * reads and from the edge's live ingest; both carry the same lines, so a line whose event id is already
 * stored is skipped rather than stored twice. Lines without an event id (from before the edge added
 * them) are always stored.
 */
class StoreEdgeLogLines
{
    /**
     * The number of rows written per insert statement.
     */
    private const int INSERT_CHUNK_SIZE = 1000;

    public function __construct(private ResolveLookupIds $resolveLookupIds, private FillGymClientHints $fillGymClientHints, private EdgeLineAddons $edgeLineAddons)
    {
        //
    }

    /**
     * Decode newline-delimited JSON, one entry per non-blank line (null when a line isn't a JSON object).
     *
     * @return list<array<string, mixed>|null>
     */
    public static function decode(string $contents): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            $lines[] = is_array($decoded) ? $decoded : null;
        }

        return $lines;
    }

    /**
     * Store the lines. Lines for hostnames that aren't registered domains, or that can't be parsed, are
     * skipped; lines already stored are counted as duplicates. Repeated strings are resolved to lookup
     * ids once per batch. Call it inside a transaction.
     *
     * What else a line carries, like a captured response, is kept beside its request by the add-on that
     * reads it (App\Support\EdgeLineAddon); the core ignores it. A line the edge named a Livewire
     * component on is linked to it in livewire_calls. A request the edge answered with the gym gives the
     * session it started the client hints it carried.
     *
     * @param  list<array<string, mixed>|null>  $lines
     * @return array{imported: int, skipped: int, duplicates: int}
     */
    public function handle(array $lines): array
    {
        $addons = $this->edgeLineAddons->all();
        $domainIds = $this->domainIdsByHostname($lines);

        $rows = [];
        $skipped = 0;

        foreach ($lines as $line) {
            $row = $line === null ? null : $this->toRow($line, $domainIds, $addons);

            if ($row === null) {
                $skipped++;

                continue;
            }

            $rows[] = $row;
        }

        $parsed = count($rows);
        $rows = $this->withoutStored($rows);
        $kept = $this->kept($addons, $rows);
        $keptRows = array_replace([], ...$kept);
        $componentIds = $this->resolveLookupIds->livewireComponents(array_filter(array_column($rows, 'livewire_component')));
        $requests = $this->withLookupIds($rows);
        $pending = [];
        $edgeRequestIds = [];

        foreach ($requests as $index => $request) {
            $componentId = $rows[$index]['livewire_component'] === null ? null : $componentIds[$rows[$index]['livewire_component']];

            if (! array_key_exists($index, $keptRows) && $componentId === null) {
                $pending[] = $request;

                if (count($pending) === self::INSERT_CHUNK_SIZE) {
                    EdgeRequest::insert($pending);
                    $pending = [];
                }

                continue;
            }

            // Flush first so request ids keep the batch's order, which same-second sorting relies on.
            if ($pending !== []) {
                EdgeRequest::insert($pending);
                $pending = [];
            }

            $edgeRequestId = EdgeRequest::insertGetId($request);

            if ($componentId !== null) {
                DB::table('livewire_calls')->insert(['edge_request_id' => $edgeRequestId, 'livewire_component_id' => $componentId]);
            }

            $edgeRequestIds[$index] = $edgeRequestId;
        }

        if ($pending !== []) {
            EdgeRequest::insert($pending);
        }

        foreach ($addons as $key => $addon) {
            if ($kept[$key] !== []) {
                $addon->store(array_combine(array_map(fn (int $index) => $edgeRequestIds[$index], array_keys($kept[$key])), $kept[$key]));
            }
        }

        $this->fillGymClientHints->handle($requests);

        return ['imported' => count($rows), 'skipped' => $skipped, 'duplicates' => $parsed - count($rows)];
    }

    /**
     * Ask each add-on which of what it read from the batch's rows to keep: by add-on (as listed), then
     * by row, in the batch's order.
     *
     * @param  list<EdgeLineAddon>  $addons
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array<int, mixed>>
     */
    private function kept(array $addons, array $rows): array
    {
        $kept = [];

        foreach ($addons as $key => $addon) {
            $read = [];

            foreach ($rows as $index => $row) {
                if (array_key_exists($key, $row['addons'])) {
                    $read[$index] = ['domain_id' => $row['domain_id'], 'read' => $row['addons'][$key]];
                }
            }

            $kept[$key] = $read === [] ? [] : array_intersect_key($addon->keep($read), $read);
            ksort($kept[$key]);
        }

        return $kept;
    }

    /**
     * Drop rows whose event is already stored, or repeated within the batch, keeping the batch's order.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withoutStored(array $rows): array
    {
        $eventIds = array_values(array_filter(array_column($rows, 'event_id')));

        if ($eventIds === []) {
            return $rows;
        }

        $seen = array_fill_keys(EdgeRequest::query()->whereIn('event_id', $eventIds)->pluck('event_id')->all(), true);

        return array_values(array_filter($rows, function (array $row) use (&$seen) {
            if ($row['event_id'] === null) {
                return true;
            }

            if (isset($seen[$row['event_id']])) {
                return false;
            }

            return $seen[$row['event_id']] = true;
        }));
    }

    /**
     * Map each hostname in the batch to its domain's id. A preview maps to the domain it previews.
     * www.site maps to site's domain when site serves www. (unless www.site is still a domain of its
     * own).
     *
     * @param  list<array<string, mixed>|null>  $lines
     * @return array<string, int>
     */
    private function domainIdsByHostname(array $lines): array
    {
        $hostnames = collect($lines)
            ->filter()
            ->map(fn (array $line) => Str::lower((string) ($line['host'] ?? '')))
            ->filter()
            ->unique()
            ->values();

        if ($hostnames->isEmpty()) {
            return [];
        }

        $ids = Domain::query()
            ->whereIn('hostname', $hostnames)
            ->pluck('id', 'hostname')
            ->all();

        $previews = $hostnames->reject(fn (string $hostname) => isset($ids[$hostname]))->filter(PreviewHostname::isPreview(...));

        if ($previews->isNotEmpty()) {
            $ids += Domain::query()
                ->whereIn('preview_hostname', $previews)
                ->pluck('id', 'preview_hostname')
                ->all();
        }

        $bareByWww = $hostnames
            ->reject(fn (string $hostname) => isset($ids[$hostname]))
            ->mapWithKeys(fn (string $hostname) => [$hostname => Domain::withoutWww($hostname)])
            ->filter();

        if ($bareByWww->isNotEmpty()) {
            $folded = Domain::query()
                ->whereIn('hostname', $bareByWww->values())
                ->servingWww()
                ->pluck('id', 'hostname');

            foreach ($bareByWww as $www => $bare) {
                if ($folded->has($bare)) {
                    $ids[$www] = $folded[$bare];
                }
            }
        }

        return $ids;
    }

    /**
     * Turn one log line into a parsed request, or null when it can't be attributed to a domain or is
     * the gym's own traffic (under config('gym.path')), which belongs to NiceTryBot rather than the
     * site and is recorded on gym sessions instead. Strings that belong in lookup tables are kept under their own keys until withLookupIds().
     * What each add-on read from the line is kept under addons, by the add-on's place in the list.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, int>  $domainIds
     * @param  list<EdgeLineAddon>  $addons
     * @return array<string, mixed>|null
     */
    private function toRow(array $line, array $domainIds, array $addons): ?array
    {
        $domainId = $domainIds[Str::lower((string) ($line['host'] ?? ''))] ?? null;

        if ($domainId === null || str_starts_with((string) ($line['path'] ?? ''), '/'.config('gym.path').'/')) {
            return null;
        }

        foreach (['occurred_at', 'client_ip', 'method', 'path', 'status'] as $field) {
            if (blank($line[$field] ?? null)) {
                return null;
            }
        }

        if (filter_var($line['client_ip'], FILTER_VALIDATE_IP) === false) {
            return null;
        }

        try {
            $occurredAt = Carbon::parse((string) $line['occurred_at'])->utc();
        } catch (Throwable) {
            return null;
        }

        $countryCode = Str::upper($this->limit($line['country_code'] ?? '', 2));
        $asn = filter_var($line['asn'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return [
            'event_id' => Str::isUuid($line['event_id'] ?? null) ? Str::lower($line['event_id']) : null,
            'domain_id' => $domainId,
            'occurred_at' => $occurredAt->toDateTimeString(),
            'client_ip' => (string) $line['client_ip'],
            'method' => HttpMethod::fromVerb((string) $line['method'])->value,
            'status_code' => (int) $line['status'],
            'duration_ms' => max(0, (int) round((float) ($line['duration_ms'] ?? 0))),
            'action' => (RequestAction::fromSlug((string) ($line['action'] ?? '')) ?? RequestAction::Passed)->value,
            'asn' => $asn === false ? null : $asn,
            'path' => $this->limit($line['path'], 2048),
            'user_agent' => $this->limitOrNull($line['user_agent'] ?? null, 512),
            'bot_tag' => $this->limitOrNull($line['bot_tag'] ?? null, 64),
            'ja4' => $this->limitOrNull($line['ja4'] ?? null, 64),
            'ja3' => $this->limitOrNull($line['ja3'] ?? null, 32),
            'ja4h' => $this->limitOrNull($line['ja4h'] ?? null, 64),
            'client_hints' => $this->clientHints($line),
            'accept_language' => $this->limitOrNull($line['lang'] ?? null, 35),
            'livewire_component' => $this->limitOrNull($line['livewire_component'] ?? null, 255),
            'network_name' => $asn === false ? null : $this->limitOrNull($line['as_org'] ?? null, 255),
            'location' => strlen($countryCode) === 2
                ? [$countryCode, $this->limit($line['region'] ?? '', 128), $this->limit($line['city'] ?? '', 128), ...$this->coordinates($line)]
                : null,
            'addons' => array_filter(array_map(fn (EdgeLineAddon $addon) => $addon->read($line), $addons), fn (mixed $read) => $read !== null),
        ];
    }

    /**
     * Read the geolocation's latitude and longitude, or [null, null] when the line has none (older lines) or
     * they're out of range.
     *
     * @param  array<string, mixed>  $line
     * @return array{0: float|null, 1: float|null}
     */
    private function coordinates(array $line): array
    {
        $latitude = filter_var($line['latitude'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => -90, 'max_range' => 90]]);
        $longitude = filter_var($line['longitude'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => -180, 'max_range' => 180]]);

        return $latitude === false || $longitude === false ? [null, null] : [round($latitude, 5), round($longitude, 5)];
    }

    /**
     * Join the client hints a line carries (Sec-CH-UA and Sec-CH-UA-Platform) as "<brands> | <platform>",
     * or null when the client sent neither, as non-Chromium browsers and most scripts don't.
     *
     * @param  array<string, mixed>  $line
     */
    private function clientHints(array $line): ?string
    {
        return ClientHint::valueOf(
            is_string($line['ch_ua'] ?? null) ? $line['ch_ua'] : null,
            is_string($line['ch_platform'] ?? null) ? $line['ch_platform'] : null,
        );
    }

    /**
     * Replace each parsed request's strings with lookup-table ids, resolving every distinct value once.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withLookupIds(array $rows): array
    {
        $paths = $this->resolveLookupIds->paths(array_column($rows, 'path'));
        $userAgents = $this->resolveLookupIds->userAgents(array_column($rows, 'user_agent'));
        $botTags = $this->resolveLookupIds->botTags(array_column($rows, 'bot_tag'));
        $ja4s = $this->resolveLookupIds->fingerprints(TlsFingerprintType::Ja4, array_column($rows, 'ja4'));
        $ja3s = $this->resolveLookupIds->fingerprints(TlsFingerprintType::Ja3, array_column($rows, 'ja3'));
        $ja4hs = $this->resolveLookupIds->fingerprints(TlsFingerprintType::Ja4h, array_column($rows, 'ja4h'));
        $clientHints = $this->resolveLookupIds->clientHints(array_column($rows, 'client_hints'));
        $locations = $this->resolveLookupIds->locations(array_filter(array_column($rows, 'location')));

        $networkNames = [];

        foreach ($rows as $row) {
            if ($row['asn'] !== null) {
                $networkNames[$row['asn']] = $row['network_name'] ?? 'AS'.$row['asn'];
            }
        }

        $this->resolveLookupIds->networks($networkNames);

        return array_map(fn (array $row) => [
            'event_id' => $row['event_id'],
            'domain_id' => $row['domain_id'],
            'occurred_at' => $row['occurred_at'],
            'client_ip' => $row['client_ip'],
            'method' => $row['method'],
            'status_code' => $row['status_code'],
            'duration_ms' => $row['duration_ms'],
            'action' => $row['action'],
            'asn' => $row['asn'],
            'path_id' => $paths[$row['path']],
            'user_agent_id' => $row['user_agent'] === null ? null : $userAgents[$row['user_agent']],
            'bot_tag_id' => $row['bot_tag'] === null ? null : $botTags[$row['bot_tag']],
            'ja4_id' => $row['ja4'] === null ? null : $ja4s[$row['ja4']],
            'ja3_id' => $row['ja3'] === null ? null : $ja3s[$row['ja3']],
            'ja4h_id' => $row['ja4h'] === null ? null : $ja4hs[$row['ja4h']],
            'client_hints_id' => $row['client_hints'] === null ? null : $clientHints[$row['client_hints']],
            'accept_language' => $row['accept_language'],
            'location_id' => $row['location'] === null ? null : $locations[Location::key(...$row['location'])],
        ], $rows);
    }

    /**
     * Cast a value to a string no longer than the given column length.
     */
    private function limit(mixed $value, int $length): string
    {
        return mb_substr((string) $value, 0, $length);
    }

    /**
     * Like limit(), but returns null for blank values.
     */
    private function limitOrNull(mixed $value, int $length): ?string
    {
        return blank($value) ? null : $this->limit($value, $length);
    }
}
