<?php

use App\Support\PrivacyHostname;
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
            $table->string('privacy_hostname')->nullable()->unique()->after('hostname');
        });

        DB::table('domains')->whereNull('privacy_hostname')->orderBy('id')->each(function (object $domain) {
            DB::table('domains')->where('id', $domain->id)->update(['privacy_hostname' => PrivacyHostname::generate()]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropUnique(['privacy_hostname']);
            $table->dropColumn('privacy_hostname');
        });
    }
};
