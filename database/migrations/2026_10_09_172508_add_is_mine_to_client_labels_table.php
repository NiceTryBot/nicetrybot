<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: a label can mark a value as the team's own traffic (an office IP, a dev box's
     * fingerprint), which Activity hides by default.
     */
    public function up(): void
    {
        Schema::table('client_labels', function (Blueprint $table) {
            $table->boolean('is_mine')->default(false)->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_labels', function (Blueprint $table) {
            $table->dropColumn('is_mine');
        });
    }
};
