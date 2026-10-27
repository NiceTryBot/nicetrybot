<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Built concurrently, which Postgres won't do inside a transaction.
     *
     * @var bool
     */
    public $withinTransaction = false;

    /**
     * Run the migrations: an index on edge_requests by path and time, so a search on paths reads just
     * the requests for the paths it matches instead of every request in the period. Built
     * concurrently (online), so the edge keeps writing requests meanwhile.
     */
    public function up(): void
    {
        Schema::table('edge_requests', function (Blueprint $table) {
            $table->index(['path_id', 'occurred_at'])->online();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('edge_requests', function (Blueprint $table) {
            $table->dropIndex(['path_id', 'occurred_at']);
        });
    }
};
