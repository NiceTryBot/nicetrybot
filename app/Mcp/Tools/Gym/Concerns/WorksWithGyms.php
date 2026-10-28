<?php

namespace App\Mcp\Tools\Gym\Concerns;

use App\Enums\GymExit;
use App\Enums\GymField;
use App\Enums\GymOperator;
use App\Gym\ObstacleRegistry;
use App\Gym\ObstacleSetting;
use App\Models\GymRoutine;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;

/**
 * What the gym tools share: finding one of the team's routines, naming exits, checking conditions and
 * obstacles an agent sends as strictly as the routine editor does, and showing a routine.
 *
 * @method Team team()
 * @method User user()
 */
trait WorksWithGyms
{
    /**
     * Find one of the token's team's routines.
     */
    protected function findRoutine(int $id): ?GymRoutine
    {
        return $this->team()->gymRoutines()->find($id);
    }

    /**
     * The answer for a routine id the team doesn't have.
     */
    protected function routineNotFound(int $id): Response
    {
        return Response::error(__('This team has no gym with id :id. Call list-gyms to see its gyms.', ['id' => $id]));
    }

    /**
     * A routine's settings as they stand, for saving it back with one thing changed.
     *
     * @return array{name: string, enabled: bool, match_all: bool, conditions: list<array{field: string, operator: string, values: list<string>}>, obstacles: list<array{id: string, type: string, config: array<string, mixed>, escalate: string|null}>, exit_action: GymExit, pass_seconds: int, one_try: bool}
     */
    protected function currentAttributes(GymRoutine $routine): array
    {
        return [
            'name' => $routine->name,
            'enabled' => $routine->enabled,
            'match_all' => $routine->match_all,
            'conditions' => $routine->conditions,
            'obstacles' => $routine->obstacles,
            'exit_action' => $routine->exit_action,
            'pass_seconds' => $routine->pass_seconds,
            'one_try' => $routine->one_try,
        ];
    }

    /**
     * The names agents use for the exits: continue, not_found and block.
     *
     * @return list<string>
     */
    protected function exitNames(): array
    {
        return array_map(fn (GymExit $exit) => $this->exitName($exit), GymExit::cases());
    }

    /**
     * Name an exit as agents use it, e.g. "not_found".
     */
    protected function exitName(GymExit $exit): string
    {
        return Str::snake($exit->name);
    }

    /**
     * Get the exit an agent named.
     */
    protected function exitFrom(string $name): GymExit
    {
        return collect(GymExit::cases())->first(fn (GymExit $exit) => $this->exitName($exit) === $name) ?? GymExit::Continue;
    }

    /**
     * Describe the exits and pass lengths, for a schema.
     */
    protected function exitHelp(): string
    {
        return collect(GymExit::cases())->map(fn (GymExit $exit) => $this->exitName($exit).' ('.lcfirst($exit->label()).')')->implode(', ');
    }

    /**
     * The schema of a gym's settings, shared by create-gym and update-gym.
     *
     * @return array<string, Type>
     */
    protected function gymProperties(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(100)->description('The gym\'s name, like "Netherlands gym".'),
            'enabled' => $schema->boolean()->description('Whether visitors are sent through it.'),
            'match_all' => $schema->boolean()->description('true: a visitor enters when every condition holds. false: when any one does.'),
            'conditions' => $schema->array()->items($schema->object([
                'field' => $schema->string()->enum(array_column(GymField::cases(), 'value'))->description('What to test: network (hosting provider, or "hosting" for any), country (ISO 3166 code: NL is the Netherlands, NE is Niger), browser, ua (user agent), ip (address or CIDR range), ja4 (TLS fingerprint), domain (one of the team\'s), category (path category, or "probe"), path, verdict (a rule id from list-rules, or "any": visitors that rule tagged or banned), tag (an edge tag).')->required(),
                'operator' => $schema->string()->enum(array_column(GymOperator::cases(), 'value'))->description('in or not_in for most fields; contains, not_contains or starts_with for ua and path; ja4 also takes starts_with. Defaults to the field\'s first operator (not_in for browser, contains for ua and path, in otherwise).'),
                'values' => $schema->array()->items($schema->string())->description('The values to compare with; any one matching is enough, e.g. ["NL"].')->required(),
            ]))->description('Who enters. An empty list lets every visitor in.'),
            'obstacles' => $schema->array()->items($schema->object([
                'type' => $schema->string()->description('An obstacle type key from list-obstacle-types.')->required(),
                'config' => $schema->object()->description('Settings by key, from list-obstacle-types. Settings left out take their default; values are brought within bounds.'),
                'escalate' => $schema->string()->description('For an obstacle that can flag visitors as automated: the obstacle type to add when it does. Must not already be in the gym.'),
            ]))->description('The obstacles in the order visitors meet them, at most one of each type.'),
            'exit_action' => $schema->string()->enum($this->exitNames())->description('Where a visitor goes after clearing every obstacle: '.$this->exitHelp().'.'),
            'pass_seconds' => $schema->integer()->enum(array_keys(GymRoutine::PASS_DURATIONS))->description('How long a cleared visitor skips the gym, in seconds: '.collect(GymRoutine::PASS_DURATIONS)->map(fn (string $label, int $seconds) => "{$seconds} ({$label})")->implode(', ').'.'),
            'one_try' => $schema->boolean()->description('true: a pass allows one form submission rather than any pages until it expires.'),
        ];
    }

    /**
     * The validation rules for a gym's settings.
     *
     * @return array<string, list<mixed>>
     */
    protected function gymRules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'nullable', 'string', 'max:100'],
            'enabled' => ['nullable', 'boolean'],
            'match_all' => ['nullable', 'boolean'],
            'conditions' => ['nullable', 'array'],
            'obstacles' => ['nullable', 'array'],
            'exit_action' => ['nullable', 'string', Rule::in($this->exitNames())],
            'pass_seconds' => ['nullable', 'integer', Rule::in(array_keys(GymRoutine::PASS_DURATIONS))],
            'one_try' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The messages for gymRules() that need to say what's allowed.
     *
     * @return array<string, string>
     */
    protected function gymMessages(): array
    {
        return [
            'exit_action.in' => __('exit_action must be one of :exits.', ['exits' => implode(', ', $this->exitNames())]),
            'pass_seconds.in' => __('pass_seconds must be one of :seconds.', ['seconds' => implode(', ', array_keys(GymRoutine::PASS_DURATIONS))]),
        ];
    }

    /**
     * Check entry conditions as an agent sent them, and clean their values up as the editor does.
     *
     * @param  mixed  $rows  Untrusted input, meant to be a list of {field, operator, values}.
     * @return list<array{field: string, operator: string, values: list<string>}>
     *
     * @throws ValidationException When a condition can't be used, saying what to fix.
     */
    protected function validatedConditions(mixed $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->fail('conditions', __('conditions must be a list of {field, operator, values}.'));
        }

        $conditions = [];

        foreach ($rows as $index => $row) {
            $number = $index + 1;
            $field = is_array($row) && is_string($row['field'] ?? null) ? GymField::tryFrom($row['field']) : null;

            if ($field === null) {
                $this->fail('conditions', __('Condition :n: field must be one of :fields.', ['n' => $number, 'fields' => implode(', ', array_column(GymField::cases(), 'value'))]));
            }

            $operators = $field->operators();
            $operator = isset($row['operator']) ? GymOperator::tryFrom((string) $row['operator']) : $operators[0];

            if ($operator === null || ! in_array($operator, $operators, true)) {
                $this->fail('conditions', __('Condition :n: :field takes the operator :operators.', [
                    'n' => $number,
                    'field' => $field->value,
                    'operators' => Arr::join(array_column($operators, 'value'), ', ', ' or '),
                ]));
            }

            $values = is_array($row['values'] ?? null) ? $row['values'] : (isset($row['values']) ? [$row['values']] : []);
            $options = $field->isPicked() ? $field->options($this->team()) : [];
            $normalized = [];

            foreach ($values as $value) {
                $clean = is_scalar($value) ? $field->normalize((string) $value) : null;

                if ($clean === null) {
                    $this->fail('conditions', __('Condition :n: “:value” won\'t do for :field. :hint', ['n' => $number, 'value' => is_scalar($value) ? $value : '?', 'field' => $field->value, 'hint' => $field->invalidValueMessage()]));
                }

                if ($field->isPicked() && ! array_key_exists($clean, $options)) {
                    $this->fail('conditions', __('Condition :n: “:value” isn\'t a :field value. :hint', ['n' => $number, 'value' => $clean, 'field' => $field->value, 'hint' => $this->optionsHint($field, $options)]));
                }

                $normalized[] = $clean;
            }

            if ($normalized === []) {
                $this->fail('conditions', __('Condition :n: give at least one value, or leave the condition out.', ['n' => $number]));
            }

            $conditions[] = ['field' => $field->value, 'operator' => $operator->value, 'values' => array_values(array_unique($normalized))];
        }

        return $conditions;
    }

    /**
     * Check obstacles as an agent sent them and configure each one, keeping the id of an obstacle of the
     * same type the routine already has, so visitors part way through keep their progress.
     *
     * @param  mixed  $rows  Untrusted input, meant to be a list of {type, config, escalate}.
     * @param  list<array{id: string, type: string, config: array<string, mixed>, escalate: string|null}>  $current
     * @return list<array{id: string, type: string, config: array<string, int|string|bool|list<string>>, escalate: string|null}>
     *
     * @throws ValidationException When an obstacle can't be used, saying what to fix.
     */
    protected function validatedObstacles(mixed $rows, array $current = []): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->fail('obstacles', __('obstacles must be a list of {type, config, escalate}.'));
        }

        $types = array_map(fn (mixed $row) => is_array($row) ? ($row['type'] ?? null) : null, $rows);
        $ids = array_column($current, 'id', 'type');
        $obstacles = [];

        foreach ($rows as $index => $row) {
            $entry = $this->validatedObstacle($row, $index + 1, $types);

            if (array_key_exists($entry['type'], $obstacles)) {
                $this->fail('obstacles', __('Obstacle :n: the gym already has a :type. A gym takes at most one of each obstacle type.', ['n' => $index + 1, 'type' => $entry['type']]));
            }

            $obstacles[$entry['type']] = ['id' => $ids[$entry['type']] ?? $this->newObstacleId(), ...$entry];
        }

        return array_values($obstacles);
    }

    /**
     * Check one obstacle as an agent sent it and configure it.
     *
     * @param  list<mixed>  $routineTypes  The obstacle types the routine will have, which escalation can't add again.
     * @return array{type: string, config: array<string, int|string|bool|list<string>>, escalate: string|null}
     *
     * @throws ValidationException
     */
    protected function validatedObstacle(mixed $row, int $number, array $routineTypes): array
    {
        $registry = app(ObstacleRegistry::class);
        $obstacle = is_array($row) && is_string($row['type'] ?? null) ? $registry->find($row['type']) : null;

        if ($obstacle === null) {
            $this->fail('obstacles', __('Obstacle :n: type must be one of :types (list-obstacle-types describes them).', ['n' => $number, 'types' => implode(', ', array_keys($registry->all()))]));
        }

        $config = $row['config'] ?? [];

        if (! is_array($config)) {
            $this->fail('obstacles', __('Obstacle :n: config must be an object of setting => value.', ['n' => $number]));
        }

        $settings = array_map(fn (ObstacleSetting $setting) => $setting->key, $obstacle->settings());

        if (($unknown = array_diff(array_keys($config), $settings)) !== []) {
            $this->fail('obstacles', __('Obstacle :n: :type has no setting :unknown. Its settings are :settings.', [
                'n' => $number,
                'type' => $obstacle->key(),
                'unknown' => implode(', ', $unknown),
                'settings' => $settings === [] ? __('none') : implode(', ', $settings),
            ]));
        }

        $escalate = filled($row['escalate'] ?? null) ? (string) $row['escalate'] : null;

        if ($escalate !== null) {
            if ($obstacle->flagsWhen() === null) {
                $this->fail('obstacles', __('Obstacle :n: :type never flags a visitor as automated, so it can\'t escalate.', ['n' => $number, 'type' => $obstacle->key()]));
            }

            if ($registry->find($escalate) === null) {
                $this->fail('obstacles', __('Obstacle :n: escalate must be an obstacle type from list-obstacle-types.', ['n' => $number]));
            }

            if (in_array($escalate, $routineTypes, true)) {
                $this->fail('obstacles', __('Obstacle :n: :escalate is already in the gym, so escalating can\'t add it.', ['n' => $number, 'escalate' => $escalate]));
            }
        }

        return ['type' => $obstacle->key(), 'config' => $obstacle->configure($config), 'escalate' => $escalate];
    }

    /**
     * A routine as the tools show it.
     *
     * @return array<string, mixed>
     */
    protected function routineData(GymRoutine $routine): array
    {
        $registry = app(ObstacleRegistry::class);

        return [
            'id' => $routine->id,
            'name' => $routine->name,
            'enabled' => $routine->enabled,
            'position' => $routine->position,
            'who_enters' => $routine->entry()->describe($this->team()),
            'match_all' => $routine->match_all,
            'conditions' => $routine->conditions,
            'obstacles' => array_map(function (array $entry) use ($registry) {
                $obstacle = $registry->find($entry['type']);
                $config = $obstacle?->configure($entry['config']) ?? $entry['config'];

                return [
                    'id' => $entry['id'],
                    'type' => $entry['type'],
                    'name' => $obstacle?->name(),
                    'installed' => $obstacle !== null,
                    'config' => $config,
                    'summary' => $obstacle?->summarize($config),
                    'escalate' => $entry['escalate'] ?? null,
                ];
            }, $routine->obstacles),
            'exit_action' => $this->exitName($routine->exit_action),
            'pass_seconds' => $routine->pass_seconds,
            'pass' => $routine->passLabel(),
            'one_try' => $routine->one_try,
        ];
    }

    /**
     * Say which values a picked field takes.
     *
     * @param  array<string, string>  $options
     */
    private function optionsHint(GymField $field, array $options): string
    {
        return match ($field) {
            GymField::Country => __('Use a two-letter ISO 3166 code, like NL for the Netherlands.'),
            GymField::Verdict => __('Use “any”, or a rule id from list-rules.'),
            GymField::Domain => $options === [] ? __('The team has no domains yet.') : __('Use one of the team\'s domains: :options.', ['options' => implode(', ', array_keys($options))]),
            default => __('Use one of :options.', ['options' => implode(', ', array_keys($options))]),
        };
    }

    /**
     * Stop with a message saying what to fix.
     *
     * @throws ValidationException
     */
    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }

    private function newObstacleId(): string
    {
        return Str::lower(Str::random(10));
    }
}
