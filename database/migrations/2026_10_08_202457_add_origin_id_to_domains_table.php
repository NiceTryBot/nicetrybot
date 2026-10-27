<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: which shared origin a domain is a site of. The origins themselves come with the
     * origins add-on, whose table is made just before this; without it the column is a plain one, and the
     * add-on links it to its table when it's installed later.
     */
    public function up(): void
    {
        $hasOrigins = Schema::hasTable('origins');

        Schema::table('domains', function (Blueprint $table) use ($hasOrigins) {
            $column = $table->foreignId('origin_id')->nullable()->after('team_id');

            $hasOrigins ? $column->constrained()->nullOnDelete() : $table->index('origin_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasForeignKey = collect(Schema::getForeignKeys('domains'))->contains(fn (array $key) => $key['columns'] === ['origin_id']);

        Schema::table('domains', function (Blueprint $table) use ($hasForeignKey) {
            if ($hasForeignKey) {
                $table->dropConstrainedForeignId('origin_id');

                return;
            }

            $table->dropIndex(['origin_id']);
            $table->dropColumn('origin_id');
        });
    }
};
