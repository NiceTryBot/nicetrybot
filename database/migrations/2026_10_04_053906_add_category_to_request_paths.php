<?php

use App\Enums\PathCategory;
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
        Schema::table('request_paths', function (Blueprint $table) {
            $table->unsignedTinyInteger('category')->nullable()->index();
        });

        DB::table('request_paths')->orderBy('id')->chunkById(1000, function ($paths) {
            foreach ($paths as $path) {
                if ($category = PathCategory::classify($path->path)) {
                    DB::table('request_paths')->where('id', $path->id)->update(['category' => $category->value]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('request_paths', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
