<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Names now label one value each (an IP, a TLS fingerprint or a user agent) instead of a client made of
 * several, so an IP and the fingerprint seen on it can be named independently. Existing names carry
 * over: a name on a fingerprint (alone or with an IP) becomes that fingerprint's label, a name on an IP
 * alone becomes the IP's.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('client_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->text('value');
            $table->char('value_hash', 32);
            $table->string('name', 60);
            $table->timestamps();

            $table->unique(['team_id', 'kind', 'value_hash']);
        });

        foreach (DB::table('client_names')->orderBy('id')->get() as $name) {
            [$kind, $value] = $name->ja4 !== null ? ['ja4', $name->ja4] : ['ip', $name->client_ip];

            DB::table('client_labels')->insertOrIgnore([
                'team_id' => $name->team_id,
                'kind' => $kind,
                'value' => $value,
                'value_hash' => md5($value),
                'name' => $name->name,
                'created_at' => $name->created_at,
                'updated_at' => $name->updated_at,
            ]);
        }

        Schema::dropIfExists('client_names');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('client_names', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->ipAddress('client_ip')->nullable();
            $table->string('ja4', 64)->nullable();
            $table->timestamps();

            $table->index('team_id');
        });

        Schema::dropIfExists('client_labels');
    }
};
