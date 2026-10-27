<?php

namespace App\Support;

use BackedEnum;
use Closure;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * A request field rule scripts and Activity searches can test: one of the core's (App\Enums\RuleField)
 * or one an add-on brings, listed in RuleFields. Fields are enum cases named by their value, as a
 * script writes them ("status", "network.kind"), so a parsed script's nodes can hold them and
 * compare them with ===.
 */
interface RuleFieldDefinition extends BackedEnum
{
    /**
     * Get the SQL expression the field reads, against edge_requests, the core's lookup tables and
     * whatever its joins() add.
     */
    public function column(): string;

    /**
     * Get the expression LIKE compares against: a lowercased string, so matching ignores case.
     */
    public function likeColumn(): string;

    /**
     * Determine if the field holds free text that LIKE can search.
     */
    public function isText(): bool;

    /**
     * Determine if the field is known while a request is happening, before the site answers.
     */
    public function isLive(): bool;

    /**
     * Determine if the field is a number that supports < and >.
     */
    public function isNumeric(): bool;

    /**
     * Convert a script literal into the value(s) stored for this field.
     *
     * @return list<int|string>
     *
     * @throws InvalidArgumentException When the literal can't be a value of this field.
     */
    public function storedValues(int|string $literal): array;

    /**
     * Get the field as people say it.
     */
    public function englishName(): string;

    /**
     * Get the palette group the field is listed under.
     */
    public function group(): string;

    /**
     * Get a one-line hint shown in the field palette, or ''.
     */
    public function hint(): string;

    /**
     * Get what Claude should know before writing a rule with the field, beyond its hint, or ''.
     * Fields with the same note are told it together.
     */
    public function ruleNote(): string;

    /**
     * Get the joins the field reads through, beyond the core's lookups, keyed by the table (or alias)
     * each adds, so fields reading the same table join it once. A rule query joins them only when its
     * script reads the field.
     *
     * @return array<string, Closure(Builder): mixed>
     */
    public function joins(): array;
}
