<?php

namespace NiceTry\Tarpit\Rules;

use App\Models\EdgeRule;
use App\Models\Team;
use App\Support\RuleDestinationError;
use App\Support\RuleDestinationHandler;
use Illuminate\Support\Facades\Gate;
use NiceTry\Tarpit\Enums\TarpitAfterWait;
use NiceTry\Tarpit\Enums\TarpitWhenFull;
use NiceTry\Tarpit\Models\Tarpit;

/**
 * The TARPIT destination: TARPIT 'name' holds the rule's clients in a tarpit, making one by that name
 * (with the usual starting waits) when the team doesn't have it yet.
 */
class TarpitRuleDestinationHandler implements RuleDestinationHandler
{
    public function keyword(): string
    {
        return 'TARPIT';
    }

    public function takesTarget(): bool
    {
        return true;
    }

    public function managedBy(Team $team): bool
    {
        return Gate::allows('create', [Tarpit::class, $team]);
    }

    public function apply(EdgeRule $rule, ?string $target): void
    {
        $name = trim((string) $target);

        if ($name === '') {
            throw new RuleDestinationError(__('TARPIT needs a name, e.g. TARPIT \'curl-probes\'.'));
        }

        $tarpit = $rule->team->tarpits()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? $rule->team->tarpits()->create([...$this->defaults(), 'name' => $name]);

        $tarpit->rules()->syncWithoutDetaching([$rule->id]);
        Tarpit::forgetFeed();
    }

    public function remove(EdgeRule $rule): void
    {
        $detached = 0;

        foreach ($rule->team->tarpits()->whereHas('rules', fn ($query) => $query->whereKey($rule->id))->get() as $tarpit) {
            $detached += $tarpit->rules()->detach($rule->id);
        }

        if ($detached > 0) {
            Tarpit::forgetFeed();
        }
    }

    public function phrase(?string $target): string
    {
        return __('holds its clients in the tarpit “:name”', ['name' => trim((string) $target)]);
    }

    /**
     * The settings a new tarpit starts with, matching the "New tarpit" form's defaults.
     *
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'enabled' => true,
            'all_domains' => true,
            'free_tries' => 1,
            'delays' => Tarpit::DEFAULT_DELAYS,
            'max_delay_seconds' => 30,
            'max_held' => 100,
            'when_full' => TarpitWhenFull::Reject,
            'after_wait' => TarpitAfterWait::Continue,
            'forget_after_seconds' => 900,
            'takes_gym_no_shows' => false,
        ];
    }
}
