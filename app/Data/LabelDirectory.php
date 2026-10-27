<?php

namespace App\Data;

use App\Enums\LabelKind;
use App\Models\ClientLabel;
use Illuminate\Database\Eloquent\Collection;

/**
 * A team's labels, loaded once per request so every value on a page can be named without a query
 * each. Bound as a scoped singleton.
 */
class LabelDirectory
{
    /**
     * @var array<int, Collection<int, ClientLabel>>
     */
    private array $labels = [];

    /**
     * Get the label on one value, if the team gave it one.
     */
    public function labelFor(int $teamId, LabelKind $kind, ?string $value): ?ClientLabel
    {
        if ($value === null || $value === '') {
            return null;
        }

        $hash = md5($value);

        return $this->labels($teamId)->first(fn (ClientLabel $label) => $label->kind === $kind && $label->value_hash === $hash);
    }

    /**
     * Get the team's labels whose name contains the search, for finding values by name.
     *
     * @return Collection<int, ClientLabel>
     */
    public function search(int $teamId, string $search): Collection
    {
        $search = mb_strtolower(trim($search));

        return $search === '' ? new Collection : $this->labels($teamId)->filter(fn (ClientLabel $label) => str_contains(mb_strtolower($label->name), $search))->values();
    }

    /**
     * Get the labels the team marked as its own traffic.
     *
     * @return Collection<int, ClientLabel>
     */
    public function mine(int $teamId): Collection
    {
        return $this->labels($teamId)->where('is_mine', true)->values();
    }

    /**
     * Drop a team's loaded labels, after one changed, so the next lookup reads them afresh.
     */
    public function forget(int $teamId): void
    {
        unset($this->labels[$teamId]);
    }

    /**
     * @return Collection<int, ClientLabel>
     */
    private function labels(int $teamId): Collection
    {
        return $this->labels[$teamId] ??= ClientLabel::query()->where('team_id', $teamId)->get();
    }
}
