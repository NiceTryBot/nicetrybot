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
        Schema::table('edge_requests', function (Blueprint $table) {
            $table->string('ja3', 32)->nullable()->after('bot_tag');
            $table->string('ja4', 64)->nullable()->after('ja3');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('edge_requests', function (Blueprint $table) {
            $table->dropColumn(['ja3', 'ja4']);
        });
    }
};
