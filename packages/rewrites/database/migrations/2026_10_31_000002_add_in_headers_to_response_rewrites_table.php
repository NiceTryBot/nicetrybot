<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: whether a rewrite also changes the response's headers (cookies' Domain,
     * redirects' Location), not just its body.
     */
    public function up(): void
    {
        Schema::table('response_rewrites', function (Blueprint $table) {
            $table->boolean('in_headers')->default(false)->after('content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('response_rewrites', function (Blueprint $table) {
            $table->dropColumn('in_headers');
        });
    }
};
