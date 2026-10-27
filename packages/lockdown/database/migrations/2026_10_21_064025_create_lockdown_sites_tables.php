<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: side tables for what a lockdown covers. An origin in the pool locks all of its
     * sites, including ones that join it later. A row for a domain is a decision about that site alone
     * (locked, or released back to its origin) and wins over its origin's. The allowlist names addresses
     * that reach the real sites while the lockdown is engaged.
     *
     * Origins come with the origins add-on. Without its table, the pool holds plain ids, which nothing
     * adds until shared origins are kept.
     */
    public function up(): void
    {
        $hasOrigins = Schema::hasTable('origins');

        Schema::create('lockdown_origins', function (Blueprint $table) use ($hasOrigins) {
            $originId = $table->foreignId('origin_id')->primary();

            if ($hasOrigins) {
                $originId->constrained()->cascadeOnDelete();
            }

            $table->foreignId('lockdown_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('lockdown_domains', function (Blueprint $table) {
            $table->foreignId('domain_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('lockdown_id')->constrained()->cascadeOnDelete();
            $table->boolean('locked');
            $table->timestamps();
        });

        Schema::create('lockdown_allowed_ips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lockdown_id')->constrained()->cascadeOnDelete();
            $table->string('range', 49);
            $table->string('label', 100)->nullable();
            $table->timestamps();

            $table->unique(['lockdown_id', 'range']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lockdown_allowed_ips');
        Schema::dropIfExists('lockdown_domains');
        Schema::dropIfExists('lockdown_origins');
    }
};
