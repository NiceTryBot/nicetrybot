<?php

namespace NiceTry\RulePacks;

use App\Data\RuleScript;
use App\Data\RuleScriptSyntaxError;
use App\Enums\GymExit;

/**
 * A rule pack: a named, versioned set of rules, and gym routines for the clients they tag, that a
 * team installs in one go from the Rules page. Packs are tagged in the container under
 * RulePacks::TAG, so another add-on can bring its own.
 *
 * A pack's rules are generic, never one site's or one customer's, and only TAG: installed, they
 * observe. A routine is entered by the verdicts of the pack rules it names, by conditions of its own,
 * or both, and is installed switched off. Bump the version whenever a rule or routine changes, so
 * teams with the old one are offered the update.
 */
abstract class RulePack
{
    /**
     * The pack's slug, unique among packs. Installs are recorded under it, so it never changes.
     */
    abstract public function key(): string;

    /**
     * The pack's version: a whole number, bumped whenever a rule or routine changes.
     */
    abstract public function version(): int;

    /**
     * The pack's name, untranslated.
     */
    abstract public function name(): string;

    /**
     * What the pack catches and what installing it does, untranslated.
     */
    abstract public function description(): string;

    /**
     * The Heroicon the pack is drawn with.
     */
    abstract public function icon(): string;

    /**
     * The pack's rules, keyed by a slug unique within the pack (installs are recorded under it, so it
     * never changes). Name and blurb are untranslated; the source must parse with the core's fields,
     * or say in a comment which add-on it needs. The first is offered in the Rules page's template
     * gallery.
     *
     * @return array<string, array{name: string, blurb: string, source: string}>
     */
    abstract public function rules(): array;

    /**
     * The pack's gym routines, keyed like the rules (a key a rule has is left out). Each is entered by
     * the verdicts of the pack rules in its `rules`, joined to its `conditions` as `match_all` says,
     * and goes through the core's obstacles. One that names no rules is offered in the gym's template
     * gallery too.
     *
     * @return array<string, array{name: string, blurb: string, icon: string, rules: list<string>, match_all: bool, conditions: list<array{field: string, operator: string, values: list<string>}>, obstacles: list<array{type: string, config: array<string, mixed>, escalate: string|null}>, exit: GymExit, pass_seconds: int}>
     */
    public function routines(): array
    {
        return [];
    }

    /**
     * Parse one of the pack's rules with the fields installed now.
     *
     * @throws RuleScriptSyntaxError When it doesn't parse, e.g. it reads a field of an add-on that
     *                               isn't installed.
     */
    public function script(string $rule): RuleScript
    {
        return RuleScript::parse($this->rules()[$rule]['source']);
    }

    /**
     * Why one of the pack's rules can't be installed now, or null when it can: the parser's own
     * message, which names a field an add-on brings when that add-on isn't installed.
     */
    public function unavailableBecause(string $rule): ?string
    {
        try {
            $this->script($rule);
        } catch (RuleScriptSyntaxError $error) {
            return $error->getMessage();
        }

        return null;
    }

    /**
     * Determine if one of the pack's routines can be installed now: it names no rules, or at least one
     * of those it names can be.
     */
    public function routineIsAvailable(string $routine): bool
    {
        $rules = $this->routines()[$routine]['rules'];

        return $rules === [] || array_filter($rules, fn (string $rule): bool => isset($this->rules()[$rule]) && $this->unavailableBecause($rule) === null) !== [];
    }
}
