<?php

namespace App\Console\Commands;

use App\Actions\Rules\EvaluateRule;
use App\Data\RuleScriptSyntaxError;
use App\Models\EdgeRule;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('rules:evaluate')]
#[Description('Run every enabled rule over newly imported edge requests and record verdicts')]
class EvaluateRules extends Command
{
    /**
     * Execute the console command. A rule whose script no longer parses (one of its fields came with an
     * add-on that's been removed) is skipped and named, not reported: it runs again once it parses.
     */
    public function handle(EvaluateRule $evaluateRule): int
    {
        $reached = 0;
        $skipped = 0;
        $failed = 0;

        EdgeRule::query()->where('enabled', true)->with('team')->orderBy('id')->each(function (EdgeRule $rule) use ($evaluateRule, &$reached, &$skipped, &$failed) {
            try {
                $reached += $evaluateRule->handle($rule);
            } catch (RuleScriptSyntaxError $error) {
                $skipped++;
                $this->warn(sprintf('Rule #%d (%s) skipped, its script doesn\'t parse: %s', $rule->id, $rule->name, $error->getMessage()));
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->warn(sprintf('Rule #%d (%s) failed: %s', $rule->id, $rule->name, $exception->getMessage()));
            }
        });

        $this->info(sprintf(
            'Reached %d new verdict(s).%s%s',
            $reached,
            $skipped > 0 ? " {$skipped} rule(s) skipped." : '',
            $failed > 0 ? " {$failed} rule(s) failed." : '',
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
