<?php

namespace App\Actions\Edge;

use App\Models\EdgeLogFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportEdgeLogFile
{
    public function __construct(private StoreEdgeLogLines $storeEdgeLogLines)
    {
        //
    }

    /**
     * Import one batch file shipped by an edge node into edge_requests.
     *
     * The file holds one JSON object per line, already redacted and enriched with network and location
     * on the node. The file is recorded in the same transaction as its rows, so importing a key twice
     * never duplicates requests, and lines the live ingest already stored are skipped.
     */
    public function handle(string $key): EdgeLogFile
    {
        $existing = EdgeLogFile::firstWhere('key', $key);

        if ($existing !== null) {
            return $existing;
        }

        $lines = StoreEdgeLogLines::decode($this->read($key));

        return DB::transaction(function () use ($key, $lines): EdgeLogFile {
            $stored = $this->storeEdgeLogLines->handle($lines);

            return EdgeLogFile::create([
                'key' => $key,
                'rows_imported' => $stored['imported'],
                'rows_skipped' => $stored['skipped'],
                'imported_at' => now(),
            ]);
        });
    }

    /**
     * Read the file's contents.
     *
     * The edge uploads gzip-compressed files, but the S3 client may already have decoded them
     * because of their Content-Encoding header, so decompress only when the gzip magic bytes are present.
     */
    private function read(string $key): string
    {
        $contents = (string) Storage::disk('edge-logs')->get($key);

        if (str_starts_with($contents, "\x1f\x8b")) {
            $contents = gzdecode($contents);

            if ($contents === false) {
                throw new RuntimeException("Edge log file [{$key}] is not valid gzip.");
            }
        }

        return $contents;
    }
}
