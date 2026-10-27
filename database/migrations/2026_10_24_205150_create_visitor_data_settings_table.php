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
        Schema::create('visitor_data_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('request_days');
            $table->unsignedSmallInteger('gym_days');
            $table->unsignedSmallInteger('fingerprint_days');
            $table->unsignedSmallInteger('capture_days');
            $table->unsignedSmallInteger('verdict_days');
            $table->string('contact')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitor_data_settings');
    }
};
