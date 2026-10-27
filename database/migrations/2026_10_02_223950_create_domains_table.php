<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('hostname')->unique();
            $table->string('origin_url');
            $table->string('host_header_mode')->default('pass_through');
            $table->string('origin_host_header')->nullable();
            $table->string('forwarded_host_header')->nullable();
            $table->unsignedSmallInteger('origin_timeout_seconds')->default(30);
            $table->string('protection_mode')->default('observe');
            $table->string('status')->default('pending_verification');
            $table->string('verification_token', 64);
            $table->string('origin_secret', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
