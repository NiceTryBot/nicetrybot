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
        // For reading a whole day across every domain.
        Schema::table('edge_requests', function (Blueprint $table) {
            $table->index('occurred_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('edge_requests', function (Blueprint $table) {
            $table->dropIndex(['occurred_at']);
        });
    }
};
