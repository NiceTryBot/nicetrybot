<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: whether the edge sends Strict-Transport-Security, and whether it tells browsers
     * to cache /img, /css and /js, chosen per origin and copied onto its sites like its other settings.
     * Origins come with the origins add-on; without it only domains get them, and the add-on adds them to
     * origins when it's installed later.
     */
    public function up(): void
    {
        foreach (array_filter(['origins', 'domains'], fn (string $tableName) => Schema::hasTable($tableName)) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('hsts_policy')->default('off');
                $table->boolean('cache_assets')->default(false);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_filter(['origins', 'domains'], fn (string $tableName) => Schema::hasColumn($tableName, 'hsts_policy')) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['hsts_policy', 'cache_assets']);
            });
        }
    }
};
