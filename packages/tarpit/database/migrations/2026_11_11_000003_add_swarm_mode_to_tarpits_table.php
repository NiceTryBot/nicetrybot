<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Swarm mode: when more than swarm_ip_count distinct IPs are held within swarm_within_seconds (a
     * distributed flood, each IP too quiet on its own to climb the ladder), every new IP skips the ladder
     * and waits the tarpit's cap at once. Off when swarm_ip_count is null. A hold the edge capped this way
     * is marked on tarpit_holds.swarm, so the page can show when it fired.
     */
    public function up(): void
    {
        Schema::table('tarpits', function (Blueprint $table) {
            $table->unsignedInteger('swarm_ip_count')->nullable()->after('burst_within_seconds');
            $table->unsignedSmallInteger('swarm_within_seconds')->default(120)->after('swarm_ip_count');
        });

        Schema::table('tarpit_holds', function (Blueprint $table) {
            $table->boolean('swarm')->default(false)->after('try');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tarpits', function (Blueprint $table) {
            $table->dropColumn(['swarm_ip_count', 'swarm_within_seconds']);
        });

        Schema::table('tarpit_holds', function (Blueprint $table) {
            $table->dropColumn('swarm');
        });
    }
};
