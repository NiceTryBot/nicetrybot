<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A side table naming the visitor's own record a path points at: the last UUID of a path that nests
 * one UUID under another, like a claim under its form in /sign/{form}/{claim}/thank-you. Requests for
 * the same ref from different IPs are one visit that changed IP partway (client = 'hopped').
 * Run `php artisan edge:record-path-refs` after migrating to cover the paths already recorded.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('request_path_refs', function (Blueprint $table) {
            $table->unsignedInteger('path_id')->primary();
            $table->uuid('ref')->index();

            $table->foreign('path_id')->references('id')->on('request_paths')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_path_refs');
    }
};
