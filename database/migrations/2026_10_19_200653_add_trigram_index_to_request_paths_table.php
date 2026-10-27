<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Built concurrently, which Postgres won't do inside a transaction.
     *
     * @var bool
     */
    public $withinTransaction = false;

    /**
     * Run the migrations: a trigram index on request_paths' lowercased path, so a search's
     * path LIKE '%dump%' (built as lower(path) like …) finds its paths without reading every one.
     * Built concurrently, so the edge keeps recording paths meanwhile. Postgres only.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('create extension if not exists pg_trgm');
        DB::statement('create index concurrently if not exists request_paths_path_trigram_index on request_paths using gin (lower(path) gin_trgm_ops)');
    }

    /**
     * Reverse the migrations. The extension stays; other indexes may come to use it.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('drop index concurrently if exists request_paths_path_trigram_index');
    }
};
