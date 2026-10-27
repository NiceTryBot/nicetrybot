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
        Schema::table('ai_credentials', function (Blueprint $table) {
            $table->string('workspace_id')->nullable()->after('hint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_credentials', function (Blueprint $table) {
            $table->dropColumn('workspace_id');
        });
    }
};
