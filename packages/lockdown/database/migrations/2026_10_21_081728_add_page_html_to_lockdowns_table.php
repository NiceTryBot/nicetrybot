<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: the team's own maintenance page. Null serves the stock one.
     */
    public function up(): void
    {
        Schema::table('lockdowns', function (Blueprint $table) {
            $table->text('page_html')->nullable()->after('secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lockdowns', function (Blueprint $table) {
            $table->dropColumn('page_html');
        });
    }
};
