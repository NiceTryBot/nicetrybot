<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a request says about itself in its headers, beyond the user agent: a JA4H-style header
 * fingerprint (in tls_fingerprints, type 3), the client hints Chromium sends (Sec-CH-UA and its
 * platform, in client_hints) and Accept-Language.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('client_hints', function (Blueprint $table) {
            $table->increments('id');
            $table->char('hash', 32)->unique();
            $table->text('value');
        });

        Schema::table('edge_requests', function (Blueprint $table) {
            $table->unsignedInteger('ja4h_id')->nullable();
            $table->unsignedInteger('client_hints_id')->nullable();
            $table->string('accept_language', 35)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('edge_requests', function (Blueprint $table) {
            $table->dropColumn(['ja4h_id', 'client_hints_id', 'accept_language']);
        });

        Schema::dropIfExists('client_hints');
    }
};
