<?php

namespace App\Actions\Networks;

use App\Enums\NetworkCategory as Category;
use App\Models\NetworkCategory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Load what each autonomous system is for from database/data/network-categories.tsv.gz: a gzipped TSV with
 * no header, of ASN, category (App\Enums\NetworkCategory), ranges (announced aggregated IPv4 and IPv6
 * prefixes) and addresses (IPv4 addresses). The file is generated from quantcdn/asn-info's as.json, CC0-1.0,
 * as last pushed 2026-03-11.
 *
 * Safe to run again: rows are upserted by ASN, a row an administrator set by hand is never overwritten,
 * and rows the data no longer has are left as they are.
 */
class LoadNetworkCategories
{
    /**
     * The number of rows written per query.
     */
    private const int CHUNK_SIZE = 1000;

    /**
     * Load the file, a chunk at a time, and return how many of its rows were written.
     *
     * @throws RuntimeException When the file can't be read.
     */
    public function handle(?string $path = null): int
    {
        $path ??= database_path('data/network-categories.tsv.gz');
        $file = is_readable($path) ? gzopen($path, 'rb') : false;

        if ($file === false) {
            throw new RuntimeException("Can't read the network categories at {$path}.");
        }

        $written = 0;
        $rows = [];

        try {
            while (($line = gzgets($file)) !== false) {
                $row = $this->parse($line);

                if ($row === null) {
                    continue;
                }

                $rows[$row['asn']] = $row;

                if (count($rows) === self::CHUNK_SIZE) {
                    $written += $this->write($rows);
                    $rows = [];
                }
            }

            return $written + $this->write($rows);
        } finally {
            gzclose($file);
        }
    }

    /**
     * Turn a line of the file into a row, or null for one that isn't a row of it.
     *
     * @return array{asn: int, category: int, ranges: int, addresses: int}|null
     */
    private function parse(string $line): ?array
    {
        $fields = explode("\t", rtrim($line, "\r\n"));

        if (count($fields) !== 4 || ! ctype_digit(implode('', $fields)) || Category::tryFrom((int) $fields[1]) === null) {
            return null;
        }

        return ['asn' => (int) $fields[0], 'category' => (int) $fields[1], 'ranges' => (int) $fields[2], 'addresses' => (int) $fields[3]];
    }

    /**
     * Write a chunk of rows, leaving out those an administrator set by hand.
     *
     * @param  array<int, array{asn: int, category: int, ranges: int, addresses: int}>  $rows  Keyed by ASN.
     */
    private function write(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $overrides = NetworkCategory::query()->whereKey(array_keys($rows))->where('is_override', true)->pluck('asn')->flip()->all();
        $rows = array_values(array_diff_key($rows, $overrides));

        DB::table('network_categories')->upsert($rows, ['asn'], ['category', 'ranges', 'addresses']);

        return count($rows);
    }
}
