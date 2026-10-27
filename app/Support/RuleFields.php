<?php

namespace App\Support;

use App\Enums\RuleField;
use Illuminate\Container\Attributes\Singleton;
use ReflectionEnum;

/**
 * Every field rule scripts and Activity searches can test: the core's (App\Enums\RuleField), then the
 * ones add-ons bring, each a string-backed enum of RuleFieldDefinition cases. A name already taken
 * keeps the field that had it first, so an add-on can't redefine the core's.
 */
#[Singleton]
class RuleFields
{
    /**
     * @var list<class-string<RuleFieldDefinition>>
     */
    private array $enums = [RuleField::class];

    /**
     * Add every case of each enum, after the fields already listed.
     *
     * @param  class-string<RuleFieldDefinition>  ...$enums
     */
    public function add(string ...$enums): void
    {
        foreach ($enums as $enum) {
            if (is_subclass_of($enum, RuleFieldDefinition::class) && ! in_array($enum, $this->enums, true) && (string) (new ReflectionEnum($enum))->getBackingType() === 'string') {
                $this->enums[] = $enum;
            }
        }
    }

    /**
     * @return list<RuleFieldDefinition>
     */
    public function all(): array
    {
        $fields = [];

        foreach ($this->enums as $enum) {
            foreach ($enum::cases() as $field) {
                $fields[$field->value] ??= $field;
            }
        }

        return array_values($fields);
    }

    /**
     * Find the field a script names, ignoring case.
     */
    public function find(string $name): ?RuleFieldDefinition
    {
        $name = strtolower($name);

        foreach ($this->enums as $enum) {
            if (($field = $enum::tryFrom($name)) instanceof RuleFieldDefinition) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Every field's name, as scripts write them.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(fn (RuleFieldDefinition $field): string => (string) $field->value, $this->all());
    }
}
