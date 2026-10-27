<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move edge_requests' repeated strings into lookup tables and its closed sets into smallint enums,
 * so each row is a handful of fixed-width numbers instead of ~240 bytes of text.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('request_paths', function (Blueprint $table) {
            $table->increments('id');
            $table->char('hash', 32)->unique();
            $table->text('path');
        });

        Schema::create('user_agents', function (Blueprint $table) {
            $table->increments('id');
            $table->char('hash', 32)->unique();
            $table->text('value');
        });

        Schema::create('tls_fingerprints', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedTinyInteger('type');
            $table->string('value', 64);
            $table->unique(['type', 'value']);
        });

        Schema::create('bot_tags', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('name', 64)->unique();
        });

        Schema::create('networks', function (Blueprint $table) {
            $table->unsignedInteger('asn')->primary();
            $table->string('name');
            $table->unsignedTinyInteger('kind')->default(0);
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->increments('id');
            $table->char('country_code', 2);
            $table->string('region', 128)->default('');
            $table->string('city', 128)->default('');
            $table->unique(['country_code', 'region', 'city']);
        });

        Schema::table('edge_requests', function (Blueprint $table) {
            $table->ipAddress('ip')->nullable();
            $table->unsignedTinyInteger('method_code')->default(1);
            $table->unsignedInteger('path_id')->nullable();
            $table->unsignedTinyInteger('action_code')->default(1);
            $table->unsignedInteger('user_agent_id')->nullable();
            $table->unsignedSmallInteger('bot_tag_id')->nullable();
            $table->unsignedInteger('ja4_id')->nullable();
            $table->unsignedInteger('ja3_id')->nullable();
            $table->unsignedInteger('asn')->nullable();
            $table->unsignedInteger('location_id')->nullable();
        });

        $this->backfill();

        Schema::table('edge_requests', function (Blueprint $table) {
            $table->dropColumn(['client_ip', 'country_code', 'method', 'path', 'user_agent', 'action', 'bot_tag', 'ja3', 'ja4']);
        });

        Schema::table('edge_requests', function (Blueprint $table) {
            $table->renameColumn('ip', 'client_ip');
            $table->renameColumn('method_code', 'method');
            $table->renameColumn('action_code', 'action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('edge_requests', function (Blueprint $table) {
            $table->string('client_ip_text', 45)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('method_text', 10)->default('GET');
            $table->string('path', 2048)->default('/');
            $table->string('user_agent', 512)->nullable();
            $table->string('action_text')->default('passed');
            $table->string('bot_tag')->nullable();
            $table->string('ja3', 32)->nullable();
            $table->string('ja4', 64)->nullable();
        });

        $methods = [0 => 'OTHER', 1 => 'GET', 2 => 'POST', 3 => 'HEAD', 4 => 'PUT', 5 => 'PATCH', 6 => 'DELETE', 7 => 'OPTIONS', 8 => 'CONNECT', 9 => 'TRACE'];
        $actions = [1 => 'passed', 2 => 'observed', 3 => 'diverted', 4 => 'blocked'];

        DB::table('edge_requests')->orderBy('id')->chunkById(1000, function ($rows) use ($methods, $actions) {
            foreach ($rows as $row) {
                DB::table('edge_requests')->where('id', $row->id)->update([
                    'client_ip_text' => $row->client_ip,
                    'country_code' => DB::table('locations')->where('id', $row->location_id)->value('country_code'),
                    'method_text' => $methods[$row->method] ?? 'GET',
                    'path' => DB::table('request_paths')->where('id', $row->path_id)->value('path') ?? '/',
                    'user_agent' => DB::table('user_agents')->where('id', $row->user_agent_id)->value('value'),
                    'action_text' => $actions[$row->action] ?? 'passed',
                    'bot_tag' => DB::table('bot_tags')->where('id', $row->bot_tag_id)->value('name'),
                    'ja3' => DB::table('tls_fingerprints')->where('id', $row->ja3_id)->value('value'),
                    'ja4' => DB::table('tls_fingerprints')->where('id', $row->ja4_id)->value('value'),
                ]);
            }
        });

        Schema::table('edge_requests', function (Blueprint $table) {
            $table->dropColumn(['client_ip', 'method', 'path_id', 'action', 'user_agent_id', 'bot_tag_id', 'ja4_id', 'ja3_id', 'asn', 'location_id']);
        });

        Schema::table('edge_requests', function (Blueprint $table) {
            $table->renameColumn('client_ip_text', 'client_ip');
            $table->renameColumn('method_text', 'method');
            $table->renameColumn('action_text', 'action');
        });

        Schema::dropIfExists('locations');
        Schema::dropIfExists('networks');
        Schema::dropIfExists('bot_tags');
        Schema::dropIfExists('tls_fingerprints');
        Schema::dropIfExists('user_agents');
        Schema::dropIfExists('request_paths');
    }

    /**
     * Copy every existing row's strings into the lookup tables and point the row at them.
     */
    private function backfill(): void
    {
        $methods = ['GET' => 1, 'POST' => 2, 'HEAD' => 3, 'PUT' => 4, 'PATCH' => 5, 'DELETE' => 6, 'OPTIONS' => 7, 'CONNECT' => 8, 'TRACE' => 9];
        $actions = ['passed' => 1, 'observed' => 2, 'diverted' => 3, 'blocked' => 4];

        DB::table('edge_requests')->orderBy('id')->chunkById(1000, function ($rows) use ($methods, $actions) {
            foreach ($rows as $row) {
                DB::table('edge_requests')->where('id', $row->id)->update([
                    'ip' => filter_var($row->client_ip, FILTER_VALIDATE_IP) ? $row->client_ip : '0.0.0.0',
                    'method_code' => $methods[strtoupper((string) $row->method)] ?? 0,
                    'path_id' => $this->lookupId('request_paths', ['hash' => md5((string) $row->path)], ['path' => (string) $row->path]),
                    'action_code' => $actions[(string) $row->action] ?? 1,
                    'user_agent_id' => filled($row->user_agent) ? $this->lookupId('user_agents', ['hash' => md5($row->user_agent)], ['value' => $row->user_agent]) : null,
                    'bot_tag_id' => filled($row->bot_tag) ? $this->lookupId('bot_tags', ['name' => $row->bot_tag]) : null,
                    'ja4_id' => filled($row->ja4) ? $this->lookupId('tls_fingerprints', ['type' => 1, 'value' => $row->ja4]) : null,
                    'ja3_id' => filled($row->ja3) ? $this->lookupId('tls_fingerprints', ['type' => 2, 'value' => $row->ja3]) : null,
                    'location_id' => filled($row->country_code) ? $this->lookupId('locations', ['country_code' => strtoupper($row->country_code), 'region' => '', 'city' => '']) : null,
                ]);
            }
        });
    }

    /**
     * Find or insert a lookup row by its unique columns and return its id.
     *
     * @param  array<string, mixed>  $unique
     * @param  array<string, mixed>  $values
     */
    private function lookupId(string $table, array $unique, array $values = []): int
    {
        $id = DB::table($table)->where($unique)->value('id');

        return $id ?? DB::table($table)->insertGetId([...$unique, ...$values]);
    }
};
