<?php

namespace App\Gym;

/**
 * One tunable setting of an obstacle. The routine editor draws a control for each setting from this
 * description alone, so a new obstacle needs no UI code of its own.
 */
readonly class ObstacleSetting
{
    /**
     * @param  'range'|'choice'|'choices'|'toggle'|'text'  $type
     * @param  int|string|bool|list<string>  $default
     * @param  array<string, string>  $options  For a choice or choices: value => label.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public int|string|bool|array $default,
        public array $options = [],
        public int $min = 0,
        public int $max = 0,
        public int $step = 1,
        public ?string $unit = null,
        public ?string $help = null,
    ) {
        //
    }

    /**
     * A whole number picked with a slider.
     */
    public static function range(string $key, string $label, int $default, int $min, int $max, int $step = 1, ?string $unit = null, ?string $help = null): self
    {
        return new self($key, $label, 'range', $default, min: $min, max: $max, step: $step, unit: $unit, help: $help);
    }

    /**
     * One of a few named options.
     *
     * @param  array<string, string>  $options
     */
    public static function choice(string $key, string $label, string $default, array $options, ?string $help = null): self
    {
        return new self($key, $label, 'choice', $default, options: $options, help: $help);
    }

    /**
     * Any number of a few named options, picked inline. The value is the picked options' values, in the
     * options' order; nothing picked is the default.
     *
     * @param  list<string>  $default
     * @param  array<string, string>  $options
     */
    public static function choices(string $key, string $label, array $default, array $options, ?string $help = null): self
    {
        return new self($key, $label, 'choices', $default, options: $options, help: $help);
    }

    /**
     * On or off.
     */
    public static function toggle(string $key, string $label, bool $default, ?string $help = null): self
    {
        return new self($key, $label, 'toggle', $default, help: $help);
    }

    /**
     * A short line of text, such as a headline.
     */
    public static function text(string $key, string $label, string $default, int $max = 80, ?string $help = null): self
    {
        return new self($key, $label, 'text', $default, max: $max, help: $help);
    }

    /**
     * Bring a stored or edited value back within the setting's bounds, or to its default. A choices
     * setting also takes a single option's value (as a choice stores it).
     *
     * @return int|string|bool|list<string>
     */
    public function normalize(mixed $value): int|string|bool|array
    {
        return match ($this->type) {
            'choices' => $this->picked($value) ?: $this->default,
            'range' => is_numeric($value)
                ? max($this->min, min($this->max, (int) round(((int) $value - $this->min) / $this->step) * $this->step + $this->min))
                : $this->default,
            'choice' => is_string($value) && array_key_exists($value, $this->options) ? $value : $this->default,
            'toggle' => is_bool($value) ? $value : (is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $this->default),
            'text' => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $this->max) : $this->default,
        };
    }

    /**
     * Format a value for display, e.g. "18 bits".
     *
     * @param  int|string|bool|list<string>  $value
     */
    public function format(int|string|bool|array $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn (string $picked) => $this->options[$picked] ?? $picked, $value));
        }

        return match ($this->type) {
            'choice' => $this->options[$value] ?? (string) $value,
            'toggle' => $value ? __('On') : __('Off'),
            default => trim($value.' '.($this->unit ?? '')),
        };
    }

    /**
     * The options a value picks, in the options' order.
     *
     * @return list<string>
     */
    private function picked(mixed $value): array
    {
        $values = array_map('strval', is_array($value) ? array_filter($value, 'is_scalar') : (is_string($value) ? [$value] : []));

        return array_values(array_filter(array_map('strval', array_keys($this->options)), fn (string $option) => in_array($option, $values, true)));
    }
}
