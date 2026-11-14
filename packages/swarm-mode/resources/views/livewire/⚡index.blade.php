<?php

use App\Concerns\SearchesDomains;
use App\Enums\TeamPermission;
use App\Models\Domain;
use App\Models\RequestPath;
use App\Models\ScopeVerdict;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use NiceTry\SwarmMode\Models\ManualSwarm;
use NiceTry\SwarmMode\Models\SwarmRule;

new #[Title('Swarm Mode')] class extends Component
{
    use SearchesDomains;

    /**
     * How much a press of "+5 min" adds, in minutes.
     */
    public const int EXTEND_MINUTES = 5;

    /**
     * How long Swarm Mode can be turned on by hand for, in minutes.
     *
     * @var list<int>
     */
    public const array DURATIONS = [5, 15, 30, 60, 240];

    /**
     * The site to turn Swarm Mode on for by hand.
     */
    public string $domain = '';

    /**
     * One path on that site, or blank for the whole site.
     */
    public string $path = '';

    public int $minutes = 15;

    public function mount(): void
    {
        abort_unless(Auth::user()->belongsToTeam($this->team), 403);
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->hasTeamPermission($this->team, TeamPermission::ManageGym);
    }

    /**
     * What's in Swarm Mode right now, soonest to end first: what a swarm rule turned on, and what someone
     * turned on by hand.
     *
     * @return list<array{key: string, scope: string, cause: string, rule_id: int|null, started_at: Carbon, ends_at: Carbon|null, requests: int|null}>
     */
    #[Computed]
    public function active(): array
    {
        return $this->rows(
            ScopeVerdict::query()->active()->where('team_id', $this->team->id)->whereIn('edge_rule_id', SwarmRule::query()->select('edge_rule_id'))->with('rule:id,name', 'domain:id,hostname')->get(),
            ManualSwarm::query()->active()->where('team_id', $this->team->id)->with('domain:id,hostname', 'createdBy:id,name')->get(),
        );
    }

    /**
     * What was in Swarm Mode over the last day and has ended, latest first.
     *
     * @return list<array{key: string, scope: string, cause: string, rule_id: int|null, started_at: Carbon, ends_at: Carbon|null, requests: int|null}>
     */
    #[Computed]
    public function recent(): array
    {
        $since = now()->subDay();

        $rows = $this->rows(
            ScopeVerdict::query()
                ->where('team_id', $this->team->id)
                ->whereIn('edge_rule_id', SwarmRule::query()->select('edge_rule_id'))
                ->where('first_matched_at', '>=', $since)
                ->where(fn ($query) => $query->whereNotNull('lifted_at')->orWhere('expires_at', '<=', now()))
                ->with('rule:id,name', 'domain:id,hostname')
                ->latest('first_matched_at')
                ->limit(20)
                ->get(),
            ManualSwarm::query()
                ->where('team_id', $this->team->id)
                ->where('started_at', '>=', $since)
                ->where(fn ($query) => $query->whereNotNull('ended_at')->orWhere('ends_at', '<=', now()))
                ->with('domain:id,hostname', 'createdBy:id,name')
                ->latest('started_at')
                ->limit(20)
                ->get(),
        );

        usort($rows, fn (array $a, array $b) => $b['started_at'] <=> $a['started_at']);

        return array_slice($rows, 0, 20);
    }

    /**
     * Lay out swarm rules' verdicts and hand-set swarms as one list, soonest to end first.
     *
     * @param  \Illuminate\Support\Collection<int, ScopeVerdict>  $verdicts
     * @param  \Illuminate\Support\Collection<int, ManualSwarm>  $manual
     * @return list<array{key: string, scope: string, cause: string, rule_id: int|null, started_at: Carbon, ends_at: Carbon|null, requests: int|null}>
     */
    private function rows($verdicts, $manual): array
    {
        $paths = DB::table('request_paths')
            ->whereIn('id', $verdicts->pluck('path_id')->merge($manual->pluck('path_id'))->filter()->unique()->values())
            ->pluck('path', 'id');

        $rows = [
            ...$verdicts->map(fn (ScopeVerdict $verdict) => [
                'key' => 'rule-'.$verdict->id,
                'scope' => $this->scope($verdict->domain?->hostname, $paths[$verdict->path_id] ?? null),
                'cause' => $verdict->rule?->name ?? __('A rule since deleted'),
                'rule_id' => $verdict->edge_rule_id,
                'started_at' => $verdict->first_matched_at,
                'ends_at' => $verdict->lifted_at ?? $verdict->expires_at,
                'requests' => $verdict->matched_requests,
            ])->all(),
            ...$manual->map(fn (ManualSwarm $swarm) => [
                'key' => 'manual-'.$swarm->id,
                'scope' => $this->scope($swarm->domain?->hostname, $paths[$swarm->path_id] ?? null),
                'cause' => __('Turned on by :name', ['name' => $swarm->createdBy?->name ?? __('someone')]),
                'rule_id' => null,
                'started_at' => $swarm->started_at,
                'ends_at' => $swarm->ended_at ?? $swarm->ends_at,
                'requests' => null,
            ])->all(),
        ];

        usort($rows, fn (array $a, array $b) => ($a['ends_at']?->getTimestamp() ?? PHP_INT_MAX) <=> ($b['ends_at']?->getTimestamp() ?? PHP_INT_MAX));

        return $rows;
    }

    /**
     * Name a site, a path on it, or a path on every site.
     */
    private function scope(?string $host, ?string $path): string
    {
        return match (true) {
            $host !== null && $path !== null => $host.$path,
            $host !== null => __(':host (whole site)', ['host' => $host]),
            default => __(':path on every site', ['path' => $path ?? '/']),
        };
    }

    /**
     * Turn Swarm Mode on by hand for a site, or one path on it, for a while.
     */
    public function turnOn(): void
    {
        abort_unless($this->canManage, 403);

        $this->path = trim($this->path);
        $this->validate([
            'domain' => ['required', 'string'],
            'path' => ['nullable', 'string', 'max:2048', 'starts_with:/'],
            'minutes' => ['required', 'integer', 'in:'.implode(',', self::DURATIONS)],
        ], [
            'domain.required' => __('Pick a site.'),
            'path.starts_with' => __('A path starts with /, like /login.'),
        ]);

        $domain = $this->team->domains()->whereRaw('lower(hostname) = ?', [Str::lower(trim($this->domain))])->first();

        if (! $domain instanceof Domain) {
            $this->addError('domain', __('That isn\'t one of your sites.'));

            return;
        }

        $path = (string) parse_url($this->path, PHP_URL_PATH);

        ManualSwarm::create([
            'team_id' => $this->team->id,
            'domain_id' => $domain->id,
            'path_id' => $path === '' ? null : RequestPath::idFor($path),
            'created_by_id' => Auth::id(),
            'started_at' => now(),
            'ends_at' => now()->addMinutes($this->minutes),
        ]);

        $this->reset('domain', 'path');
        unset($this->active);
        Flux::toast(variant: 'success', text: __('Swarm Mode is on.'));
    }

    /**
     * End one now, whatever turned it on. A swarm rule turns it back on if what tripped it carries on.
     */
    public function end(string $key): void
    {
        abort_unless($this->canManage, 403);

        $this->find($key)?->update(str_starts_with($key, 'rule-') ? ['lifted_at' => now()] : ['ended_at' => now()]);
        unset($this->active, $this->recent);
    }

    /**
     * Keep one on EXTEND_MINUTES longer than it was going to run.
     */
    public function extend(string $key): void
    {
        abort_unless($this->canManage, 403);

        $item = $this->find($key);
        $column = $item instanceof ScopeVerdict ? 'expires_at' : 'ends_at';

        if ($item !== null && $item->{$column} !== null) {
            $item->update([$column => $item->{$column}->max(now())->addMinutes(self::EXTEND_MINUTES)]);
        }

        unset($this->active);
    }

    /**
     * Find one of the team's swarms on now by its key.
     */
    private function find(string $key): ScopeVerdict|ManualSwarm|null
    {
        [$kind, $id] = array_pad(explode('-', $key, 2), 2, null);

        return match ($kind) {
            'rule' => ScopeVerdict::query()->active()->where('team_id', $this->team->id)->whereIn('edge_rule_id', SwarmRule::query()->select('edge_rule_id'))->find((int) $id),
            'manual' => ManualSwarm::query()->active()->where('team_id', $this->team->id)->find((int) $id),
            default => null,
        };
    }
}; ?>

<div>
    <x-ntb.page-heading
        :overline="__('Modes')"
        :title="__('Swarm Mode')"
        :description="__('A site or URL goes into Swarm Mode when your swarm rules see many IPs hit errors there at once, or when you turn it on here. While it\'s on, rules reading swarm act on everyone there: the Swarm Mode pack\'s Bouncer rule has each visitor tick one box.')"
    >
        <x-slot:actions>
            <flux:button size="sm" icon="scale" :href="route('rules.index', ['tab' => 'modes'])" wire:navigate>{{ __('Swarm rules') }}</flux:button>
        </x-slot:actions>
    </x-ntb.page-heading>

    <div wire:poll.15s.visible>
        <x-ntb.panel class="mb-6" :heading="__('On right now')" :subheading="__('Soonest to end first. A swarm rule keeps one going for as long as what tripped it carries on.')" :padded="false" data-test="swarm-active">
            @if ($this->active === [])
                <x-ntb.empty-state :heading="__('Nothing is in Swarm Mode.')">
                    {{ __('Your swarm rules turn it on when 3 or more IPs get an error from the same URL within a minute.') }}
                </x-ntb.empty-state>
            @else
                <div class="px-4 pb-2">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Where') }}</flux:table.column>
                            <flux:table.column>{{ __('Why') }}</flux:table.column>
                            <flux:table.column class="max-md:hidden">{{ __('Since') }}</flux:table.column>
                            <flux:table.column>{{ __('Left') }}</flux:table.column>
                            @if ($this->canManage)
                                <flux:table.column></flux:table.column>
                            @endif
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->active as $row)
                                <flux:table.row :key="'active-'.$row['key']" data-test="swarm-row">
                                    <flux:table.cell class="font-mono text-xs [overflow-wrap:anywhere]">{{ $row['scope'] }}</flux:table.cell>
                                    <flux:table.cell class="text-xs">
                                        @if ($row['rule_id'])
                                            <a href="{{ route('rules.index', ['rule' => $row['rule_id']]) }}" wire:navigate class="font-medium hover:underline">{{ $row['cause'] }}</a>
                                        @else
                                            {{ $row['cause'] }}
                                        @endif
                                        @if ($row['requests'])
                                            <span class="block text-[0.65rem] text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count error in its busiest minute|:count errors in its busiest minute', $row['requests']) }}</span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-xs whitespace-nowrap max-md:hidden">{{ $row['started_at']->diffForHumans(short: true) }}</flux:table.cell>
                                    <flux:table.cell class="font-mono text-xs tabular-nums whitespace-nowrap" data-test="swarm-left">
                                        @if ($row['ends_at'])
                                            <span x-data="{ left: {{ max(0, (int) now()->diffInSeconds($row['ends_at'])) }} }" x-init="setInterval(() => left = Math.max(0, left - 1), 1000)" x-text="Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0')">{{ intdiv(max(0, (int) now()->diffInSeconds($row['ends_at'])), 60) }}:{{ str_pad((string) (max(0, (int) now()->diffInSeconds($row['ends_at'])) % 60), 2, '0', STR_PAD_LEFT) }}</span>
                                        @else
                                            {{ __('until lifted') }}
                                        @endif
                                    </flux:table.cell>
                                    @if ($this->canManage)
                                        <flux:table.cell align="end" class="whitespace-nowrap">
                                            <flux:button size="xs" wire:click="extend('{{ $row['key'] }}')" data-test="swarm-extend">{{ __('+:n min', ['n' => $this::EXTEND_MINUTES]) }}</flux:button>
                                            <flux:button size="xs" variant="danger" wire:click="end('{{ $row['key'] }}')" data-test="swarm-end">{{ __('End now') }}</flux:button>
                                        </flux:table.cell>
                                    @endif
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </x-ntb.panel>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        @if ($this->canManage)
            <x-ntb.panel :heading="__('Turn on by hand')" :subheading="__('For a whole site, or one URL on it. Rules reading swarm act on it at once.')" data-test="swarm-turn-on">
                <form wire:submit="turnOn" class="space-y-4">
                    <flux:field>
                        <flux:label>{{ __('Site') }}</flux:label>
                        <x-ntb.domain-picker wire:model="domain" :placeholder="__('Pick a site')" :allow-all="false" />
                        <flux:error name="domain" />
                    </flux:field>
                    <flux:input wire:model="path" :label="__('Path')" :description="__('Blank for the whole site.')" placeholder="/login" class="font-mono" />
                    <flux:select wire:model="minutes" :label="__('For')">
                        @foreach ($this::DURATIONS as $duration)
                            <flux:select.option :value="$duration">{{ $duration < 60 ? __(':n minutes', ['n' => $duration]) : trans_choice(':count hour|:count hours', intdiv($duration, 60)) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button type="submit" variant="primary" icon="bolt" data-test="swarm-turn-on-button">{{ __('Turn on Swarm Mode') }}</flux:button>
                </form>
            </x-ntb.panel>
        @endif

        <x-ntb.panel :heading="__('Last 24 hours')" :subheading="__('Swarms that have ended, latest first.')" data-test="swarm-recent">
            @forelse ($this->recent as $row)
                <div wire:key="recent-{{ $row['key'] }}" class="flex items-start justify-between gap-3 border-b border-line py-2 text-xs last:border-0 dark:border-zinc-800">
                    <div class="min-w-0">
                        <p class="font-mono [overflow-wrap:anywhere]">{{ $row['scope'] }}</p>
                        <p class="text-zinc-500 dark:text-zinc-400">{{ $row['cause'] }}</p>
                    </div>
                    <p class="shrink-0 text-end text-zinc-500 tabular-nums dark:text-zinc-400">
                        {{ $row['started_at']->diffForHumans(short: true) }}
                        @if ($row['ends_at'])
                            <span class="block">{{ __('for :span', ['span' => $row['started_at']->diffForHumans($row['ends_at'], syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true)]) }}</span>
                        @endif
                    </p>
                </div>
            @empty
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('None in the last day.') }}</p>
            @endforelse
        </x-ntb.panel>
    </div>
</div>
