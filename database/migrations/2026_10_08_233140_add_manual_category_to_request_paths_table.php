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
        Schema::table('request_paths', function (Blueprint $table) {
            $table->foreignId('categorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('categorized_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('request_paths', function (Blueprint $table) {
            $table->dropConstrainedForeignId('categorized_by');
            $table->dropColumn('categorized_at');
        });
    }
};
