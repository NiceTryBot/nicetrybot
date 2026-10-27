<?php

use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use NiceTry\RulePacks\Actions\InstallRulePack;
use NiceTry\RulePacks\Actions\UninstallRulePack;
use NiceTry\RulePacks\Data\PackChanges;
use NiceTry\RulePacks\Models\RulePackInstall;
use NiceTry\RulePacks\RulePack;
use NiceTry\RulePacks\RulePacks;

new #[Title('Rule packs')] class extends Component {
    /**
     * What the last install, update or uninstall left for the team to know: the items it kept because
     * the team had changed them, those it couldn't install, and whether the rules started switched off.
     *
     * @var array{pack: string, kept: list<string>, unavailable: list<string>, rules_start_off: bool}|null
     */
    public ?array $notice = null;

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Whether the user can install and uninstall packs: they write rules and gym routines.
     */
    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('create', [EdgeRule::class, $this->team]) && Gate::allows('create', [GymRoutine::class, $this->team]);
    }

    /**
     * @return array<string, RulePack>
     */
    #[Computed]
    public function packs(): array
    {
        return app(RulePacks::class)->all();
    }

    /**
     * The team's installs, by pack, then by item.
     *
     * @return SupportCollection<string, Collection<string, RulePackInstall>>
     */
    #[Computed]
    public function installs(): SupportCollection
    {
        return RulePackInstall::query()
            ->where('team_id', $this->team->id)
            ->with('rule', 'routine')
            ->get()
            ->groupBy('pack')
            ->map(fn (Collection $installs) => $installs->keyBy('item'));
    }

    /**
     * The team's switched-on routines that take every rule's verdicts, which make packs install their
     * rules switched off.
     *
     * @return Collection<int, GymRoutine>
     */
    #[Computed]
    public function everyVerdictRoutines(): Collection
    {
        return app(InstallRulePack::class)->routinesTakingEveryVerdict($this->team);
    }

    public function install(string $key, InstallRulePack $installRulePack): void
    {
        $pack = $this->pack($key);
        $this->authorizeManaging();

        $changes = $installRulePack->handle($this->team, $pack);
        $this->notice = ['pack' => $pack->key(), 'kept' => $changes->kept, 'unavailable' => $changes->unavailable, 'rules_start_off' => $changes->rulesStartOff];
        unset($this->installs);

        Flux::toast(variant: 'success', text: $this->summary($pack, $changes));
    }

    public function uninstall(string $key, UninstallRulePack $uninstallRulePack): void
    {
        $pack = $this->pack($key);
        $this->authorizeManaging();

        $changes = $uninstallRulePack->handle($this->team, $pack);
        $this->notice = ['pack' => $pack->key(), 'kept' => $changes->kept, 'unavailable' => [], 'rules_start_off' => false];
        unset($this->installs);

        Flux::toast(text: $this->summary($pack, $changes));
    }

    private function pack(string $key): RulePack
    {
        return $this->packs[$key] ?? abort(404);
    }

    private function authorizeManaging(): void
    {
        Gate::authorize('create', [EdgeRule::class, $this->team]);
        Gate::authorize('create', [GymRoutine::class, $this->team]);
    }

    /**
     * Say what a change did in a few words, e.g. "Scanners and 404 probes: 5 added."
     */
    private function summary(RulePack $pack, PackChanges $changes): string
    {
        $parts = array_filter([
            $changes->added === [] ? null : __(':count added', ['count' => count($changes->added)]),
            $changes->updated === [] ? null : __(':count updated', ['count' => count($changes->updated)]),
            $changes->removed === [] ? null : __(':count removed', ['count' => count($changes->removed)]),
            $changes->kept === [] ? null : __(':count kept as you changed them', ['count' => count($changes->kept)]),
        ]);

        return __(':pack: :changes.', ['pack' => __($pack->name()), 'changes' => $parts === [] ? __('nothing to change') : implode(', ', $parts)]);
    }
}; ?>

<div>
    <a href="{{ route('rules.index') }}" wire:navigate class="mb-2 inline-flex items-center gap-1 font-mono text-xs tracking-[0.14em] text-zinc-500 uppercase hover:text-coral-600 dark:text-zinc-400">
        <flux:icon.arrow-left variant="micro" class="size-3" />{{ __('Rules') }}
    </a>
    <x-ntb.page-heading
        :title="__('Rule packs')"
        :description="__('Curated sets of rules, with gym routines for the clients they tag. Installing one never sends anyone anywhere: its rules only tag, so you can watch what they catch under Tags & bans, and its routines start switched off until you trust them.')"
    />

    @if ($this->canManage && $this->everyVerdictRoutines->isNotEmpty())
        <flux:callout class="mb-6" variant="warning" icon="scale" data-test="rule-packs-every-verdict">
            <flux:callout.text>
                {{ trans_choice(':routines takes every rule’s verdicts, so packs install their rules switched off: a client one of them tags would go straight through it. Switch a rule on when that’s what you want.|:routines take every rule’s verdicts, so packs install their rules switched off: a client one of them tags would go straight through them. Switch a rule on when that’s what you want.', $this->everyVerdictRoutines->count(), ['routines' => $this->everyVerdictRoutines->pluck('name')->join(', ', ' '.__('and').' ')]) }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="space-y-6">
        @foreach ($this->packs as $pack)
            @php($installs = $this->installs->get($pack->key(), new Collection))
            @php($installedVersion = $installs->min('version'))
            @php($missingCount = collect(array_keys($pack->rules()))->filter(fn ($rule) => $pack->unavailableBecause($rule) === null && ! $installs->has($rule))->count() + collect(array_keys($pack->routines()))->filter(fn ($routine) => $pack->routineIsAvailable($routine) && ! $installs->has($routine))->count())
            <x-ntb.panel wire:key="pack-{{ $pack->key() }}" data-test="rule-pack" data-pack="{{ $pack->key() }}">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex min-w-64 flex-1 items-start gap-3">
                        <flux:icon :icon="$pack->icon()" class="mt-0.5 size-6 shrink-0 text-coral-500" />
                        <div class="min-w-0">
                            <h2 class="flex flex-wrap items-center gap-2 text-base font-semibold tracking-tight text-ink dark:text-white">
                                {{ __($pack->name()) }}
                                <span class="font-mono text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('version :version', ['version' => $pack->version()]) }}</span>
                                @if ($installedVersion === null)
                                    <flux:badge size="sm" data-test="rule-pack-status">{{ __('Not installed') }}</flux:badge>
                                @elseif ($installedVersion < $pack->version())
                                    <flux:badge size="sm" color="amber" data-test="rule-pack-status">{{ __('Update available') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="emerald" data-test="rule-pack-status">{{ __('Installed') }}</flux:badge>
                                @endif
                            </h2>
                            <p class="mt-1 max-w-3xl text-sm text-zinc-600 dark:text-zinc-300">{{ __($pack->description()) }}</p>
                        </div>
                    </div>

                    @if ($this->canManage)
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($installedVersion === null)
                                <flux:button variant="primary" size="sm" icon="arrow-down-tray" wire:click="install('{{ $pack->key() }}')" data-test="rule-pack-install">{{ __('Install') }}</flux:button>
                            @elseif ($installedVersion < $pack->version())
                                <flux:button variant="primary" size="sm" icon="arrow-path" wire:click="install('{{ $pack->key() }}')" data-test="rule-pack-update">{{ __('Update to version :version', ['version' => $pack->version()]) }}</flux:button>
                            @elseif ($missingCount > 0)
                                <flux:button size="sm" icon="arrow-down-tray" wire:click="install('{{ $pack->key() }}')" data-test="rule-pack-install-missing">{{ __('Install the :count missing', ['count' => $missingCount]) }}</flux:button>
                            @endif
                            @if ($installedVersion !== null)
                                <flux:button variant="ghost" size="sm" icon="trash" wire:click="uninstall('{{ $pack->key() }}')" wire:confirm="{{ __('Uninstall :pack? Its rules and routines are deleted, except any you’ve changed, which stay as your own. Verdicts its rules reached stay until they expire.', ['pack' => __($pack->name())]) }}" data-test="rule-pack-uninstall">{{ __('Uninstall') }}</flux:button>
                            @endif
                        </div>
                    @endif
                </div>

                @if (($notice['pack'] ?? null) === $pack->key() && ($notice['kept'] !== [] || $notice['unavailable'] !== [] || $notice['rules_start_off']))
                    <div class="mt-4 space-y-1 rounded-lg border border-amber-200 bg-amber-50/60 px-3 py-2.5 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/5 dark:text-amber-100" data-test="rule-pack-notice">
                        @if ($notice['kept'] !== [])
                            <p>{{ __('Left as you changed them: :items.', ['items' => implode(', ', $notice['kept'])]) }}</p>
                        @endif
                        @if ($notice['unavailable'] !== [])
                            <p>{{ __('Not installed, because an add-on they need isn’t: :items.', ['items' => implode(', ', $notice['unavailable'])]) }}</p>
                        @endif
                        @if ($notice['rules_start_off'])
                            <p>{{ __('The new rules are switched off, because a routine of yours takes every rule’s verdicts.') }}</p>
                        @endif
                    </div>
                @endif

                <div class="mt-5 grid gap-5 lg:grid-cols-2">
                    <div>
                        <p class="mb-2 font-mono text-[0.65rem] tracking-[0.14em] text-zinc-500 uppercase dark:text-zinc-400">{{ trans_choice(':count rule, tagging only|:count rules, tagging only', count($pack->rules())) }}</p>
                        <ul class="divide-y divide-line rounded-lg border border-line dark:divide-zinc-800 dark:border-zinc-800">
                            @foreach ($pack->rules() as $key => $rule)
                                @php($install = $installs->get($key))
                                @php($unavailable = $pack->unavailableBecause($key))
                                <li wire:key="pack-{{ $pack->key() }}-rule-{{ $key }}" class="px-3 py-2.5" data-test="rule-pack-rule" data-item="{{ $key }}">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @if ($install?->rule)
                                            <a href="{{ route('rules.index', ['rule' => $install->rule->id]) }}" wire:navigate class="text-sm font-medium hover:underline">{{ $install->rule->name }}</a>
                                            <flux:badge size="sm" :color="$install->rule->enabled ? 'sky' : 'zinc'">{{ $install->rule->enabled ? __('Tagging') : __('Off') }}</flux:badge>
                                            @if ($install->isEdited())
                                                <flux:badge size="sm" color="amber" data-test="rule-pack-item-edited">{{ __('Changed by you') }}</flux:badge>
                                            @endif
                                        @else
                                            <span class="text-sm font-medium">{{ __($rule['name']) }}</span>
                                            @if ($unavailable !== null)
                                                <flux:badge size="sm" color="rose" data-test="rule-pack-item-unavailable">{{ __('Needs an add-on') }}</flux:badge>
                                            @elseif ($installedVersion !== null)
                                                <flux:badge size="sm">{{ __('Not installed') }}</flux:badge>
                                            @endif
                                        @endif
                                    </div>
                                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __($rule['blurb']) }}</p>
                                    @if ($unavailable !== null && ! $install?->rule)
                                        <p class="mt-1 text-xs text-rose-700 dark:text-rose-300">{{ $unavailable }}</p>
                                    @endif
                                    <details class="mt-1.5 text-xs">
                                        <summary class="cursor-pointer text-zinc-500 select-none hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">{{ __('Show the rule') }}</summary>
                                        <pre class="mt-2 overflow-x-auto rounded-md bg-zinc-950 px-3 py-2 font-mono text-[12px] leading-5 text-zinc-100">{{ $rule['source'] }}</pre>
                                    </details>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    @if ($pack->routines() !== [])
                        <div>
                            <p class="mb-2 font-mono text-[0.65rem] tracking-[0.14em] text-zinc-500 uppercase dark:text-zinc-400">{{ trans_choice(':count gym routine, off at first|:count gym routines, off at first', count($pack->routines())) }}</p>
                            <ul class="divide-y divide-line rounded-lg border border-line dark:divide-zinc-800 dark:border-zinc-800">
                                @foreach ($pack->routines() as $key => $routine)
                                    @php($install = $installs->get($key))
                                    <li wire:key="pack-{{ $pack->key() }}-routine-{{ $key }}" class="flex items-start gap-3 px-3 py-2.5" data-test="rule-pack-routine" data-item="{{ $key }}">
                                        <flux:icon :icon="$routine['icon']" variant="mini" class="mt-0.5 size-4 shrink-0 text-coral-500" />
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                @if ($install?->routine)
                                                    <a href="{{ route('gym.routines.edit', $install->routine) }}" wire:navigate class="text-sm font-medium hover:underline">{{ $install->routine->name }}</a>
                                                    <flux:badge size="sm" :color="$install->routine->enabled ? 'orange' : 'zinc'">{{ $install->routine->enabled ? __('On') : __('Off') }}</flux:badge>
                                                    @if ($install->isEdited())
                                                        <flux:badge size="sm" color="amber" data-test="rule-pack-item-edited">{{ __('Changed by you') }}</flux:badge>
                                                    @endif
                                                @else
                                                    <span class="text-sm font-medium">{{ __($routine['name']) }}</span>
                                                    @if (! $pack->routineIsAvailable($key))
                                                        <flux:badge size="sm" color="rose" data-test="rule-pack-item-unavailable">{{ __('Needs an add-on') }}</flux:badge>
                                                    @elseif ($installedVersion !== null)
                                                        <flux:badge size="sm">{{ __('Not installed') }}</flux:badge>
                                                    @endif
                                                @endif
                                            </div>
                                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __($routine['blurb']) }}</p>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </x-ntb.panel>
        @endforeach
    </div>
</div>
