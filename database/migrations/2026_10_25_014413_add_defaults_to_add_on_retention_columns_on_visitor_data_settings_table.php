<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give the retention columns that add-ons read (fingerprints, captured bodies) a default, so a
     * team's settings save on an installation without them. An installed add-on saves its own days
     * (its config's default until the team chooses), so the column default only fills in meanwhile.
     */
    public function up(): void
    {
        Schema::table('visitor_data_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('fingerprint_days')->default(90)->change();
            $table->unsignedSmallInteger('capture_days')->default(30)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visitor_data_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('fingerprint_days')->change();
            $table->unsignedSmallInteger('capture_days')->change();
        });
    }
};
