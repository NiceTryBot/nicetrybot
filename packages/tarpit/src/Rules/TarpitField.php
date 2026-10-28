<?php

namespace NiceTry\Tarpit\Rules;

use App\Support\RuleFieldDefinition;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use NiceTry\Tarpit\Enums\TarpitOutcome;

/**
 * The fields tarpits bring: which tarpit touched a request (by its name) and what came of it there.
 * Only requests a tarpit touched have them; queries join tarpit_holds (and tarpits) only when a search
 * or script reads them.
 */
enum TarpitField: string implements RuleFieldDefinition
{
    case Tarpit = 'tarpit';
    case Outcome = 'tarpit.outcome';

    public function column(): string
    {
        return match ($this) {
            self::Tarpit => 'tarpits.name',
            self::Outcome => 'tarpit_holds.outcome',
        };
    }

    public function likeColumn(): string
    {
        return 'lower('.$this->column().')';
    }

    public function isText(): bool
    {
        return $this === self::Tarpit;
    }

    /**
     * What a tarpit did is only known once the edge has logged the request.
     */
    public function isLive(): bool
    {
        return false;
    }

    public function isNumeric(): bool
    {
        return false;
    }

    public function storedValues(int|string $literal): array
    {
        if ($this === self::Tarpit) {
            return [(string) $literal];
        }

        $outcome = TarpitOutcome::fromSlug(strtolower(trim((string) $literal)));

        if ($outcome === null) {
            throw new InvalidArgumentException(__('Use free, held, full or left.'));
        }

        return [$outcome->value];
    }

    public function englishName(): string
    {
        return match ($this) {
            self::Tarpit => __('tarpit'),
            self::Outcome => __('what the tarpit did'),
        };
    }

    public function group(): string
    {
        return __('Tarpit');
    }

    public function hint(): string
    {
        return match ($this) {
            self::Tarpit => __("the tarpit's name, e.g. tarpit = 'curl probes', or tarpit IS NOT NULL for any"),
            self::Outcome => __('free (a free try), held, full (answered at once) or left (gave up while held)'),
        };
    }

    public function ruleNote(): string
    {
        return 'only on requests a tarpit touched (held, or counted as a free try); "in the tarpit" or "tarpitted" means tarpit IS NOT NULL.';
    }

    public function joins(): array
    {
        $holds = fn (Builder $query) => $query->leftJoin('tarpit_holds', 'tarpit_holds.edge_request_id', '=', 'edge_requests.id');

        return match ($this) {
            self::Tarpit => [
                'tarpit_holds' => $holds,
                'tarpits' => fn (Builder $query) => $query->leftJoin('tarpits', 'tarpits.id', '=', 'tarpit_holds.tarpit_id'),
            ],
            self::Outcome => ['tarpit_holds' => $holds],
        };
    }
}
