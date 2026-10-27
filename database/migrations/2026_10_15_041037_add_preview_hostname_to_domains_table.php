<?php

use App\Support\PreviewHostname;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('preview_hostname')->nullable()->unique()->after('privacy_hostname');
        });

        DB::table('domains')->whereNull('preview_hostname')->orderBy('id')->each(function (object $domain) {
            DB::table('domains')->where('id', $domain->id)->update(['preview_hostname' => PreviewHostname::generate($domain->hostname)]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropUnique(['preview_hostname']);
            $table->dropColumn('preview_hostname');
        });
    }
};
