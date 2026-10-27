<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Side tables that classify each client lookup once, when it's first stored: the platform, browser
 * and version a user agent claims, the TLS stack behind a JA4, and the platform client hints claim.
 * Run `php artisan edge:profile-clients` after migrating to profile the values already recorded.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_agent_profiles', function (Blueprint $table) {
            $table->unsignedInteger('user_agent_id')->primary();
            $table->unsignedTinyInteger('platform');
            $table->unsignedTinyInteger('browser');
            $table->unsignedSmallInteger('major_version')->nullable();

            $table->foreign('user_agent_id')->references('id')->on('user_agents')->cascadeOnDelete();
        });

        Schema::create('tls_fingerprint_profiles', function (Blueprint $table) {
            $table->unsignedInteger('tls_fingerprint_id')->primary();
            $table->unsignedTinyInteger('stack');

            $table->foreign('tls_fingerprint_id')->references('id')->on('tls_fingerprints')->cascadeOnDelete();
        });

        Schema::create('client_hint_profiles', function (Blueprint $table) {
            $table->unsignedInteger('client_hint_id')->primary();
            $table->unsignedTinyInteger('platform');

            $table->foreign('client_hint_id')->references('id')->on('client_hints')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_hint_profiles');
        Schema::dropIfExists('tls_fingerprint_profiles');
        Schema::dropIfExists('user_agent_profiles');
    }
};
