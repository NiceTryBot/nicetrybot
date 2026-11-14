<?php

use App\Enums\RuleAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations: rules now decide who goes in every tarpit, so a trap becomes a rule with a
     * TARPIT clause. A status trap's statuses become a status-only rule (the edge still holds the very
     * answer); a probe trap's strings become a rule matching those paths (the edge still holds the very
     * request). The trap's step-back ladder moves onto the tarpit. The converted traps are removed; the
     * tarpit_traps table stays, unused.
     */
    public function up(): void
    {
        $lastRequestId = (int) DB::table('edge_requests')->max('id');

        foreach (DB::table('tarpit_traps')->join('tarpits', 'tarpits.id', '=', 'tarpit_traps.tarpit_id')->get(['tarpit_traps.*', 'tarpits.team_id', 'tarpits.name']) as $trap) {
            $where = match ((int) $trap->catches) {
                1 => $this->statusWhere(json_decode($trap->statuses ?? '[404]', true) ?: [404]),
                2 => $this->probeWhere(json_decode($trap->strings ?? '[]', true) ?: []),
                default => null,
            };

            DB::table('tarpits')->where('id', $trap->tarpit_id)->update(['cooldowns' => $trap->cooldowns]);

            if ($where !== null) {
                $quoted = str_replace("'", "''", $trap->name);
                $ruleId = DB::table('edge_rules')->insertGetId([
                    'team_id' => $trap->team_id,
                    'name' => $trap->name,
                    'source' => "TAG ip, ja4 AS '{$quoted}' FOR 5 MINUTES\nTARPIT '{$quoted}'\nWHERE {$where}",
                    'enabled' => true,
                    'action' => RuleAction::Tag->value,
                    'duration_seconds' => 300,
                    'last_request_id' => $lastRequestId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('tarpit_rules')->insert(['tarpit_id' => $trap->tarpit_id, 'edge_rule_id' => $ruleId, 'created_at' => now(), 'updated_at' => now()]);
            }

            DB::table('tarpit_traps')->where('id', $trap->id)->delete();
        }
    }

    /**
     * @param  list<int>  $statuses
     */
    private function statusWhere(array $statuses): string
    {
        return 'status IN ('.implode(', ', array_map('intval', $statuses)).')';
    }

    /**
     * @param  list<string>  $strings
     */
    private function probeWhere(array $strings): ?string
    {
        $likes = array_map(fn (string $string) => "path LIKE '%".str_replace("'", "''", $string)."%'", $strings);

        return $likes === [] ? null : implode("\n   OR ", $likes);
    }

    /**
     * Reverse the migrations. The rules made from traps stay: they're the team's rules now.
     */
    public function down(): void
    {
        //
    }
};
