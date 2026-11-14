<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use App\Data\LiveRequest;
use App\Models\Domain;
use Illuminate\Contracts\Container\Container;

/**
 * The fields add-ons can tell a front gate about the request in front of it (App\Support\DoorField),
 * tagged in under TAG from an add-on's registerAddon().
 */
class DoorFields
{
    /**
     * The container tag door fields register under.
     */
    public const string TAG = 'rules.door-fields';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * The fields add-ons that are on can tell a gate.
     *
     * @return list<RuleFieldDefinition>
     */
    public function fields(): array
    {
        return array_map(fn (DoorField $field) => $field->field(), $this->all());
    }

    /**
     * Each field's value for this request, keyed as a script writes the field, ready for
     * LiveRequest::withKnown().
     *
     * @param  list<RuleFieldDefinition>|null  $only  Just these fields (the ones a gate's rules read).
     * @return array<string, int|string|list<string>|null>
     */
    public function values(Domain $domain, LiveRequest $live, ?array $only = null): array
    {
        $values = [];

        foreach ($this->all() as $field) {
            $definition = $field->field();

            if ($only !== null && ! in_array($definition, $only, true)) {
                continue;
            }

            $values[(string) $definition->value] = $field->value($domain, $live);
        }

        return $values;
    }

    /**
     * @return list<DoorField>
     */
    private function all(): array
    {
        return array_values(array_filter(
            app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)),
            fn (mixed $field) => $field instanceof DoorField,
        ));
    }
}
