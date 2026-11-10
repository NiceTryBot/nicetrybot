<?php

namespace App\Actions\Rules;

use App\Data\RuleScriptParser;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Write each rule's current destinations into its script, now that the script owns them. Rules keep the
 * gym routines, tarpits and blackhole they were linked to through the old hand-off panels, so the first
 * edit under the new model doesn't unlink them. Idempotent: a keyword already in a rule's script is left
 * as it is.
 */
class BackfillRuleDestinations
{
    /**
     * The clause keywords and the stops after which destinations may no longer appear.
     */
    private const array KEYWORDS = ['GYM', 'TARPIT', 'BLACKHOLE'];

    private const array STOPS = ['WHERE', 'WITHIN', 'UNTIL'];

    public function handle(): void
    {
        $hasTarpits = Schema::hasTable('tarpits') && Schema::hasTable('tarpit_rules');
        $hasBlackhole = Schema::hasTable('blackhole_feeders');
        $routinesByTeam = GymRoutine::all()->groupBy('team_id');

        EdgeRule::query()->select(['id', 'team_id', 'source'])->chunkById(200, function ($rules) use ($routinesByTeam, $hasTarpits, $hasBlackhole) {
            foreach ($rules as $rule) {
                $clauses = $this->clausesFor($rule, $routinesByTeam, $hasTarpits, $hasBlackhole);

                if ($clauses === []) {
                    continue;
                }

                $source = $this->inject($rule->source, $clauses);

                if ($source !== $rule->source) {
                    DB::table('edge_rules')->where('id', $rule->id)->update(['source' => $source]);
                }
            }
        });
    }

    /**
     * The destination clauses a rule is missing from its script but linked to in the tables.
     *
     * @param  Collection<array-key, EloquentCollection<int, GymRoutine>>  $routinesByTeam
     * @return list<string>
     */
    private function clausesFor(EdgeRule $rule, $routinesByTeam, bool $hasTarpits, bool $hasBlackhole): array
    {
        $tokens = rescue(fn () => (new RuleScriptParser($rule->source))->tokens(), null, report: false);

        if ($tokens === null) {
            return [];
        }

        $present = $this->keywordsPresent($tokens);
        $clauses = [];

        if (! in_array('GYM', $present, true)) {
            foreach (($routinesByTeam[$rule->team_id] ?? collect()) as $routine) {
                if ($routine->entry()->withoutVerdictsFrom($rule->id) !== null) {
                    $clauses[] = 'GYM '.$this->quote($routine->name);
                }
            }
        }

        if ($hasTarpits && ! in_array('TARPIT', $present, true)) {
            $names = DB::table('tarpit_rules')
                ->join('tarpits', 'tarpits.id', '=', 'tarpit_rules.tarpit_id')
                ->where('tarpit_rules.edge_rule_id', $rule->id)
                ->orderBy('tarpits.name')
                ->pluck('tarpits.name');

            foreach ($names as $name) {
                $clauses[] = 'TARPIT '.$this->quote($name);
            }
        }

        if ($hasBlackhole && ! in_array('BLACKHOLE', $present, true)
            && DB::table('blackhole_feeders')->where('edge_rule_id', $rule->id)->exists()) {
            $clauses[] = 'BLACKHOLE';
        }

        return $clauses;
    }

    /**
     * The destination keywords already in a script, uppercase, so backfilling twice does nothing.
     *
     * @param  list<array{type: string, value: string, offset: int}>  $tokens
     * @return list<string>
     */
    private function keywordsPresent(array $tokens): array
    {
        $present = [];

        foreach ($tokens as $token) {
            if ($token['type'] === 'word' && in_array($keyword = strtoupper($token['value']), self::KEYWORDS, true)) {
                $present[] = $keyword;
            }
        }

        return $present;
    }

    /**
     * Put the clauses after the duration, before WHERE/WITHIN/UNTIL (or at the end when there is none).
     *
     * @param  list<string>  $clauses
     */
    private function inject(string $source, array $clauses): string
    {
        $tokens = rescue(fn () => (new RuleScriptParser($source))->tokens(), null, report: false);

        if ($tokens === null) {
            return $source;
        }

        $offset = strlen($source);

        foreach ($tokens as $token) {
            if ($token['type'] === 'word' && in_array(strtoupper($token['value']), self::STOPS, true)) {
                $offset = $token['offset'];

                break;
            }
        }

        $before = rtrim(substr($source, 0, $offset));
        $after = ltrim(substr($source, $offset));
        $block = implode("\n", $clauses);

        return $after === '' ? $before."\n".$block : $before."\n".$block."\n".$after;
    }

    private function quote(string $name): string
    {
        return "'".str_replace("'", "''", $name)."'";
    }
}
