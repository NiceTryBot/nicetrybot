<?php

namespace App\Mcp\Tools\Rules\Concerns;

use App\Actions\Gym\SendRuleToRoutine;
use App\Data\RuleScriptSyntaxError;
use App\Enums\ApiAbility;
use App\Enums\DomainStatus;
use App\Enums\ProtectionMode;
use App\Mcp\ApiContext;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Response;

/**
 * What the rule tools share: finding one of the team's rules, saying why a script doesn't parse, and
 * sending a rule's clients to a gym.
 *
 * @method Team team()
 * @method User user()
 */
trait WorksWithRules
{
    /**
     * Find one of the token's team's rules.
     */
    protected function findRule(int $id): ?EdgeRule
    {
        return $this->team()->edgeRules()->find($id);
    }

    /**
     * The answer for a rule id the team doesn't have.
     */
    protected function ruleNotFound(int $id): Response
    {
        return Response::error(__('This team has no rule with id :id. Call list-rules to see its rules.', ['id' => $id]));
    }

    /**
     * The answer for a script that doesn't parse, naming where it breaks.
     */
    protected function syntaxError(RuleScriptSyntaxError $error): Response
    {
        return Response::error(__('The rule script doesn\'t parse. Line :line, column :column: :message Fix the script and try again; describe-rule-language has the grammar and the fields.', [
            'line' => $error->scriptLine,
            'column' => $error->scriptColumn,
            'message' => $error->getMessage(),
        ]));
    }

    /**
     * A rule as the tools show it.
     *
     * @return array{id: int, name: string, enabled: bool, action: string, duration: string, source: string}
     */
    protected function ruleData(EdgeRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'enabled' => $rule->enabled,
            'action' => strtolower($rule->action->name),
            'duration' => $rule->durationLabel(),
            'source' => $rule->source,
        ];
    }

    /**
     * Send the clients a rule flags through a gym: one of the team's by id, or one by name (the team's
     * routine with that name, ignoring case, or a new one). Null when no gym was asked for; otherwise
     * whether it worked and, when it didn't, why, in words the agent can pass on.
     *
     * @return array{linked: bool, gym?: array{id: int, name: string, created: bool}, reason?: string, note?: string}|null
     */
    protected function sendRuleToGym(EdgeRule $rule, ?int $gymId, ?string $gymName): ?array
    {
        $gymName = $gymName === null ? '' : trim($gymName);

        if ($gymId === null && $gymName === '') {
            return null;
        }

        if (! app(ApiContext::class)->token()->canOnTeam(ApiAbility::ManageGym)) {
            return ['linked' => false, 'reason' => __('The rule was saved, but not sent to a gym: this token can\'t change gyms. It needs the "Create and change gyms" ability, and its user the team permission to manage the gym.')];
        }

        $routine = $gymName;

        if ($gymId !== null) {
            $routine = $this->team()->gymRoutines()->find($gymId);

            if ($routine === null) {
                return ['linked' => false, 'reason' => __('The rule was saved, but not sent to a gym: this team has no gym with id :id. Call list-gyms to see them, or pass gym_name to make a new one.', ['id' => $gymId])];
            }
        }

        $allowed = $routine instanceof GymRoutine
            ? Gate::forUser($this->user())->allows('update', $routine)
            : Gate::forUser($this->user())->allows('create', [GymRoutine::class, $this->team()]);

        if (! $allowed) {
            return ['linked' => false, 'reason' => __('The rule was saved, but not sent to a gym: the token\'s user can\'t change this team\'s gym.')];
        }

        $sent = app(SendRuleToRoutine::class)->handle($rule, $routine);

        if ($sent === null) {
            $name = $routine instanceof GymRoutine ? $routine->name : $gymName;

            return ['linked' => false, 'reason' => __('The rule was saved, but not sent to a gym: :gym can\'t take it without changing who else enters (it lets every visitor in, or needs all of several conditions and none is about rule verdicts). Pass gym_name with a new name to make a gym for this rule\'s clients.', ['gym' => '"'.$name.'"'])];
        }

        $result = ['linked' => true, 'gym' => ['id' => $sent->id, 'name' => $sent->name, 'created' => $sent->wasRecentlyCreated]];

        if (! $this->team()->domains()->where('protection_mode', ProtectionMode::Enforce)->where('status', DomainStatus::Active)->exists()) {
            $result['note'] = __('None of the team\'s domains is in Enforce mode yet, so no visitor is sent to a gym until one is.');
        }

        return $result;
    }
}
