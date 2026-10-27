<?php

namespace App\Actions\Clients;

use App\Enums\ClientBrowser;
use App\Enums\ClientPlatform;
use App\Enums\TlsStack;
use Illuminate\Support\Facades\DB;

/**
 * Classify client lookups into their profile side tables, so filters can ask "which user agents claim
 * iOS?" or "which fingerprints are Chrome's TLS?" by id instead of matching text on every request.
 */
class RecordClientProfiles
{
    /**
     * The number of profiles written per query.
     */
    private const int CHUNK_SIZE = 500;

    /**
     * @param  array<string, int>  $idsByUserAgent
     * @param  bool  $refresh  Rewrite existing profiles too, after a classifier changes.
     */
    public function userAgents(array $idsByUserAgent, bool $refresh = false): void
    {
        $this->write('user_agent_profiles', 'user_agent_id', $idsByUserAgent, $refresh, function (string $userAgent) {
            $browser = ClientBrowser::fromUserAgent($userAgent);

            return [
                'platform' => ClientPlatform::fromUserAgent($userAgent)->value,
                'browser' => $browser->value,
                'major_version' => $browser->majorVersionIn($userAgent),
            ];
        });
    }

    /**
     * @param  array<string, int>  $idsByJa4
     * @param  bool  $refresh  Rewrite existing profiles too, after a classifier changes.
     */
    public function ja4Fingerprints(array $idsByJa4, bool $refresh = false): void
    {
        $this->write('tls_fingerprint_profiles', 'tls_fingerprint_id', $idsByJa4, $refresh, fn (string $ja4) => [
            'stack' => TlsStack::fromJa4($ja4)->value,
        ]);
    }

    /**
     * @param  array<string, int>  $idsByClientHints
     * @param  bool  $refresh  Rewrite existing profiles too, after a classifier changes.
     */
    public function clientHints(array $idsByClientHints, bool $refresh = false): void
    {
        $this->write('client_hint_profiles', 'client_hint_id', $idsByClientHints, $refresh, fn (string $clientHints) => [
            'platform' => ClientPlatform::fromClientHints($clientHints)->value,
        ]);
    }

    /**
     * @param  array<string, int>  $idsByValue
     * @param  callable(string): array<string, int|null>  $classify
     */
    private function write(string $table, string $key, array $idsByValue, bool $refresh, callable $classify): void
    {
        $rows = [];

        foreach ($idsByValue as $value => $id) {
            $rows[] = [$key => $id, ...$classify((string) $value)];
        }

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $refresh
                ? DB::table($table)->upsert($chunk, [$key], array_values(array_diff(array_keys($chunk[0]), [$key])))
                : DB::table($table)->insertOrIgnore($chunk);
        }
    }
}
