<?php

use App\Data\RuleScriptParser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The rule action keyword `BAN` was renamed to `BLOCK`. Rewrite the action token in every saved rule's
 * source so existing rules keep parsing. Only the leading action keyword is touched (found via the real
 * tokenizer), so a "ban" inside a comment, tag or value is left alone. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewriteAction('BAN', 'BLOCK');
    }

    public function down(): void
    {
        $this->rewriteAction('BLOCK', 'BAN');
    }

    /**
     * Replace the first word token of each rule's source when it is $from (ignoring case), with $to.
     */
    private function rewriteAction(string $from, string $to): void
    {
        DB::table('edge_rules')->select('id', 'source')->orderBy('id')->chunkById(200, function ($rules) use ($from, $to) {
            foreach ($rules as $rule) {
                $tokens = rescue(fn () => (new RuleScriptParser($rule->source))->tokens(), null, report: false);

                if ($tokens === null) {
                    continue;
                }

                $action = collect($tokens)->first(fn (array $token) => $token['type'] === 'word');

                if ($action === null || strtoupper($action['value']) !== $from) {
                    continue;
                }

                DB::table('edge_rules')->where('id', $rule->id)->update([
                    'source' => substr($rule->source, 0, $action['offset']).$to.substr($rule->source, $action['offset'] + strlen($action['value'])),
                ]);
            }
        });
    }
};
