<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The IP ranges crawler operators publish for their bots (OpenAI's GPTBot, for one), so a request from
 * inside them can be named as that crawler. Refreshed daily by `crawlers:refresh`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('crawler_ip_ranges', function (Blueprint $table) {
            $table->id();
            $table->string('crawler', 32)->index();
            $table->string('cidr', 43);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crawler_ip_ranges');
    }
};
