<?php

use App\Data\RuleScript;
use App\Models\EdgeRule;
use Carbon\CarbonInterval;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use NiceTry\Tarpit\Models\Tarpit;

new class extends Component
{
    #[Locked]
    public int $ruleId;

    public ?int $tarpitId = null;

    public function mount(EdgeRule $rule): void
    {
        abort_unless($rule->team_id === $this->team->id, 404);

        $this->ruleId = $rule->id;
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Whether the edge can judge the rule on the request itself (a single-request rule), so a tarpit
     * holds a matching request before the verdict lands.
     */
    #[Computed]
    public function isLive(): bool
    {
        $rule = $this->team->edgeRules()->find($this->ruleId, ['id', 'source']);
        $script = $rule === null ? null : rescue(fn () => RuleScript::parse($rule->source), report: false);

        return $script?->isLive() ?? false;
    }

    /**
     * How long the rule's verdicts last, as people say it, or null for ever.
     */
    #[Computed]
    public function verdictLasts(): ?string
    {
        $seconds = $this->team->edgeRules()->whereKey($this->ruleId)->value('duration_seconds');

        return $seconds === null ? null : CarbonInterval::seconds((int) $seconds)->cascade()->forHumans();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('create', [Tarpit::class, $this->team]);
    }

    /**
     * The team's tarpits, those that take this rule's clients first.
     *
     * @return array{connected: Collection<int, Tarpit>, available: Collection<int, Tarpit>}
     */
    #[Computed]
    public function tarpits(): array
    {
        $tarpits = $this->team->tarpits()
            ->withExists(['rules as takes_rule' => fn ($query) => $query->whereKey($this->ruleId)])
            ->orderBy('name')
            ->get();

        return [
            'connected' => $tarpits->where('takes_rule', true)->values(),
            'available' => $tarpits->where('takes_rule', false)->values(),
        ];
    }

    /**
     * Send the rule's clients to the picked tarpit.
     */
    public function send(): void
    {
        $tarpit = $this->team->tarpits()->find($this->tarpitId);

        if ($tarpit === null) {
            $this->addError('tarpitId', __('Pick a tarpit.'));

            return;
        }

        Gate::authorize('update', $tarpit);

        $tarpit->rules()->syncWithoutDetaching([$this->ruleId]);
        Tarpit::forgetFeed();

        $this->tarpitId = null;
        unset($this->tarpits);
        Flux::toast(variant: 'success', text: __('Its clients go to :name from now on.', ['name' => $tarpit->name]));
    }

    /**
     * Stop sending the rule's clients to a tarpit.
     */
    public function release(int $tarpitId): void
    {
        $tarpit = $this->team->tarpits()->findOrFail($tarpitId);
        Gate::authorize('update', $tarpit);

        $tarpit->rules()->detach($this->ruleId);
        Tarpit::forgetFeed();

        unset($this->tarpits);
    }
}; ?>

<x-ntb.panel class="mt-6" :heading="__('Send its clients to a tarpit')" :subheading="__('A rule on its own only tags. Send its clients to a tarpit and they wait at the edge: a free try, then longer each time.')" data-test="rule-tarpit">
    @php($lasts = $this->verdictLasts === null ? __('for good') : __('for :time', ['time' => $this->verdictLasts]))
    @if ($this->tarpits['connected']->isEmpty())
        <flux:callout class="mb-4" icon="information-circle" data-test="rule-tarpit-only-tags">
            <flux:callout.text>{{ __('No tarpit takes this rule\'s clients, so nobody waits: it only tags them.') }}</flux:callout.text>
        </flux:callout>
    @else
        <flux:callout class="mb-4" color="violet" icon="clock" data-test="rule-tarpit-what-waits">
            <flux:callout.text>
                @if ($this->isLive)
                    {{ __('Every request this rule matches waits, from the first one. Once its client is tagged (a minute or two later), every request from that client waits too, :lasts.', ['lasts' => $lasts]) }}
                @else
                    {{ __('Once a client is tagged (a minute or two after the requests that earn it), every request from that client waits, :lasts.', ['lasts' => $lasts]) }}
                @endif
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->tarpits['connected']->isNotEmpty())
        <ul class="mb-4 divide-y divide-line rounded-lg border border-line dark:divide-zinc-800 dark:border-zinc-800">
            @foreach ($this->tarpits['connected'] as $tarpit)
                <li wire:key="rule-tarpit-{{ $tarpit->id }}" class="flex items-start gap-3 px-3 py-2.5" data-test="rule-tarpit-connected">
                    <flux:icon.clock variant="mini" class="mt-0.5 size-4 shrink-0 text-violet-600 dark:text-violet-400" />
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('tarpits.index') }}" wire:navigate class="text-sm font-medium hover:underline">{{ $tarpit->name }}</a>
                        @unless ($tarpit->enabled)
                            <flux:badge size="sm" class="ms-1">{{ __('Off') }}</flux:badge>
                        @endunless
                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">{{ $tarpit->describeWaits() }}</span>
                    </div>
                    @if ($this->canManage)
                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="release({{ $tarpit->id }})" data-test="rule-tarpit-release">{{ __('Stop') }}</flux:button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($this->canManage)
        <div class="flex flex-wrap items-start gap-2">
            @if ($this->tarpits['available']->isNotEmpty())
                <div class="min-w-48 flex-1 sm:max-w-xs">
                    <flux:select wire:model="tarpitId" size="sm" :placeholder="__('Pick a tarpit…')" :aria-label="__('Tarpit')" data-test="rule-tarpit-pick">
                        @foreach ($this->tarpits['available'] as $tarpit)
                            <flux:select.option :value="$tarpit->id">{{ $tarpit->name }}{{ $tarpit->enabled ? '' : ' ('.__('off').')' }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:button variant="primary" size="sm" icon="arrow-right" wire:click="send" data-test="rule-tarpit-send">{{ __('Send') }}</flux:button>
            @endif
            <flux:button
                :variant="$this->tarpits['available']->isEmpty() && $this->tarpits['connected']->isEmpty() ? 'primary' : 'ghost'"
                size="sm"
                icon="plus"
                :href="route('tarpits.index', ['rule' => $ruleId])"
                wire:navigate
                data-test="rule-tarpit-new"
            >{{ __('New tarpit') }}</flux:button>
        </div>
        @error('tarpitId') <p class="mt-2 text-sm text-rose-700 dark:text-rose-300">{{ $message }}</p> @enderror
    @elseif ($this->tarpits['connected']->isEmpty())
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No tarpit takes this rule\'s clients yet.') }}</p>
    @endif
</x-ntb.panel>
