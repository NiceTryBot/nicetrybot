<?php

namespace App\Actions\Edge;

use Illuminate\Support\Facades\DB;

/**
 * Record which visitor's record a path points at, in the request_path_refs side table, so a rule can
 * ask whether a request picks up a visit another IP started (client = 'hopped') by id instead of
 * matching text on every request.
 */
class RecordPathRefs
{
    /**
     * The number of refs written per query.
     */
    private const int CHUNK_SIZE = 500;

    /**
     * A UUID as paths carry it.
     */
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * @param  array<string, int>  $idsByPath
     */
    public function paths(array $idsByPath): void
    {
        $rows = [];

        foreach ($idsByPath as $path => $id) {
            if (($ref = self::refIn((string) $path)) !== null) {
                $rows[] = ['path_id' => $id, 'ref' => $ref];
            }
        }

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            DB::table('request_path_refs')->insertOrIgnore($chunk);
        }
    }

    /**
     * Get the visitor's own record a path points at: its last UUID, when it nests that under another
     * UUID. A path with one UUID (/sign/{form}) names something every visitor shares, so it has none.
     */
    public static function refIn(string $path): ?string
    {
        $path = strtolower(strtok($path, '?') ?: '');

        if (preg_match_all('#/('.self::UUID.')(?=/|$)#', $path, $matches) < 2) {
            return null;
        }

        $refs = $matches[1];

        return array_pop($refs);
    }
}
