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
     * Run the migrations: an index on just the 404s by time, so the 404 pages read a week's 404s (a few
     * percent of requests) instead of every request in the week. Built concurrently (online), so the
     * edge keeps writing requests meanwhile.
     */
    public function up(): void
    {
        $concurrently = DB::connection()->getDriverName() === 'pgsql' ? 'concurrently' : '';

        DB::statement("create index {$concurrently} if not exists edge_requests_not_found_occurred_at_index on edge_requests (occurred_at) where status_code = 404");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop index if exists edge_requests_not_found_occurred_at_index');
    }
};
