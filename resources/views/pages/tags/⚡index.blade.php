<?php

use App\Data\LabelDirectory;
use App\Enums\GymSessionStatus;
use App\Enums\LabelKind;
use App\Enums\RuleAction;
use App\Enums\TlsFingerprintType;
use App\Enums\TrafficPeriod;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\GymRoutine;
use App\Models\Team;
use App\Models\TlsFingerprint;
use App\Models\Verdict;
use App\Support\IpRange;
use App\Support\ViewerIp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Tags & bans')] class extends Component {
    /**
     * The periods the clients' recent requests can be counted over.
     *
     * @var list<string>
     */
    public const array PERIODS = ['1h', '24h', '7d'];

    /**
     * How many clients the table shows at first, and how many more each "Show more" adds.
     */
    public const int PAGE_SIZE = 50;

    /**
     * The tag being looked at; empty shows only the overview.
     */
    #[Url(except: '')]
    public string $tag = '';

    /**
     * Look at bans (BAN rules' verdicts, which carry no tag) instead of a tag.
     */
    #[Url(except: false)]
    public bool $bans = false;

    /**
     * An IP (or the start of one), a CIDR range, a fingerprint or a name you gave. While it's set, every
     * tag and ban is searched, not just the one open.
     */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * How far back each listed client's requests are counted: one of PERIODS.
     */
    #[Url(except: '1h')]
    public string $period = '1h';

    /**
     * The IP the person using the app browses from, read when the page opens, or null when it can't be told.
     */
    #[Locked]
    public ?string $viewerIp = null;

    /**
     * @var list<string>
     */
    public array $selected = [];

    /**
     * How many of the matching clients the table shows.
     */
    public int $limit = self::PAGE_SIZE;

    public function show(string $tag): void
    {
        $this->fill(['tag' => $tag, 'bans' => false, 'search' => '', 'selected' => [], 'limit' => self::PAGE_SIZE]);
    }

    public function showBans(): void
    {
        $this->fill(['tag' => '', 'bans' => true, 'search' => '', 'selected' => [], 'limit' => self::PAGE_SIZE]);
    }

    public function showMore(): void
    {
        $this->limit = min($this->limit + self::PAGE_SIZE * 2, 1000);
    }

    public function mount(): void
    {
        $this->normalizePeriod();
        $this->viewerIp = ViewerIp::of(request());
    }

    public function updatedSearch(): void
    {
        $this->selected = [];
        $this->limit = self::PAGE_SIZE;

        if ($this->isSearching()) {
            $this->fill(['tag' => '', 'bans' => false]);
        }
    }

    public function updatedPeriod(): void
    {
        $this->normalizePeriod();
    }

    /**
     * Let the person using the app go: lift every tag and ban on the IP they're browsing from, and forget
     * the exit of the gym visits that turned it away, so the gym's cookie stops showing them the 403.
     */
    public function liftMine(): void
    {
        Gate::authorize('create', [EdgeRule::class, $this->team]);

        if (($ip = $this->viewerIp) === null) {
            return;
        }

        $lifted = $this->team->verdicts()->active()->where('client_ip', $ip)->update(['lifted_at' => now()]);
        $this->team->gymSessions()->where('client_ip', $ip)->where('status', GymSessionStatus::TurnedAway)->whereNotNull('exit_action')->update(['exit_action' => null]);

        unset($this->groups, $this->verdicts, $this->verdictCount, $this->viewerVerdicts);

        Flux\Flux::toast(variant: 'success', text: trans_choice('Lifted :count tag on your IP. Rules can tag it again if it keeps matching.|Lifted :count tags on your IP. Rules can tag it again if it keeps matching.', $lifted, ['count' => Number::format($lifted)]));
    }

    public function lift(int $verdictId): void
    {
        $this->liftWhere(fn (Builder $query) => $query->whereKey($verdictId));
    }

    public function liftSelected(): void
    {
        $this->liftWhere(fn (Builder $query) => $query->whereKey(array_map('intval', $this->selected)));
    }

    /**
     * Lift every client the current tag and search show.
     */
    public function liftAll(): void
    {
        $this->liftWhere(fn (Builder $query) => $query);
    }

    /**
     * Re-render when a client is named, so the new name shows.
     */
    #[On('label-changed')]
    public function refreshClientNames(): void
    {
        //
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('create', [EdgeRule::class, $this->team]);
    }

    /**
     * The tags and bans held on the IP the person using the app is browsing from.
     *
     * @return Collection<int, Verdict>
     */
    #[Computed]
    public function viewerVerdicts(): Collection
    {
        if ($this->viewerIp === null) {
            return new Collection;
        }

        return $this->team->verdicts()->active()->where('client_ip', $this->viewerIp)->with('rule:id,name')->get();
    }

    /**
     * Every tag (and bans) clients hold right now, with the rules that set it, the gym routines that act
     * on it and the requests its clients sent over the period, most clients first.
     *
     * @return SupportCollection<int, array{tag: ?string, clients: int, requests: int, last_matched_at: mixed, rules: SupportCollection<int, EdgeRule>, routines: SupportCollection<int, GymRoutine>}>
     */
    #[Computed]
    public function groups(): SupportCollection
    {
        $rules = $this->team->edgeRules()->get(['id', 'name', 'enabled'])->keyBy('id');
        $routines = $this->team->gymRoutines()->where('enabled', true)->orderBy('position')->get();
        $requests = $this->requestsByGroup();
        $groupKey = fn (object $row): string => (int) $row->action === RuleAction::Ban->value ? '' : (string) $row->tag;

        // Counted by the database: a busy team holds tens of thousands of verdicts.
        $ruleIdsByGroup = $this->team->verdicts()->active()->toBase()
            ->select('action', 'tag', 'edge_rule_id')
            ->distinct()
            ->get()
            ->groupBy($groupKey)
            ->map(fn (SupportCollection $rows) => $rows->pluck('edge_rule_id')->filter()->map(fn ($id) => (int) $id)->unique()->values());

        return $this->team->verdicts()->active()->toBase()
            ->selectRaw('action, tag, count(*) as clients, max(last_matched_at) as last_matched_at')
            ->groupBy('action', 'tag')
            ->get()
            ->groupBy($groupKey)
            ->map(function (SupportCollection $rows, string $tag) use ($rules, $routines, $requests, $ruleIdsByGroup) {
                $ruleIds = $ruleIdsByGroup->get($tag, collect());

                return [
                    'tag' => $tag === '' ? null : $tag,
                    'clients' => (int) $rows->sum('clients'),
                    'requests' => $requests->get($tag, 0),
                    'last_matched_at' => $rows->max('last_matched_at'),
                    'rules' => $ruleIds->map(fn (int $id) => $rules->get($id))->filter()->values(),
                    'routines' => $routines->filter(function (GymRoutine $routine) use ($ruleIds) {
                        $required = $routine->entry()->edgeVerdictRuleIds();

                        return $required !== null && ($required === [] || array_intersect($required, $ruleIds->all()) !== []);
                    })->values(),
                ];
            })
            ->sortByDesc('clients')
            ->values();
    }

    /**
     * The clients holding the tag (or ban) being looked at, most recently matched first.
     *
     * @return Collection<int, Verdict>
     */
    #[Computed]
    public function verdicts(): Collection
    {
        if ($this->tag === '' && ! $this->bans && ! $this->isSearching()) {
            return new Collection;
        }

        return $this->filteredVerdicts()->with(['rule:id,name', 'ja4Fingerprint'])->latest('last_matched_at')->latest('id')->limit($this->limit)->get();
    }

    /**
     * How many clients the tag (or ban, or search) holds in all, of which the table shows the first few.
     */
    #[Computed]
    public function verdictCount(): int
    {
        if ($this->tag === '' && ! $this->bans && ! $this->isSearching()) {
            return 0;
        }

        return $this->filteredVerdicts()->count();
    }

    /**
     * The requests each listed client sent the team's sites over the period, by IP and fingerprint
     * ("*" totals an IP's requests whatever the fingerprint).
     *
     * @return array<string, array<int|string, int>>
     */
    #[Computed]
    public function recentRequests(): array
    {
        $ips = $this->verdicts->pluck('client_ip')->filter()->unique()->values();

        if ($ips->isEmpty()) {
            return [];
        }

        $counts = [];

        EdgeRequest::query()
            ->toBase()
            ->whereIn('domain_id', $this->team->domains()->select('id'))
            ->where('occurred_at', '>=', now()->subSeconds(TrafficPeriod::from($this->period)->seconds()))
            ->whereIn('client_ip', $ips)
            ->groupBy('client_ip', 'ja4_id')
            ->selectRaw('client_ip, ja4_id, count(*) as requests')
            ->get()
            ->each(function (object $row) use (&$counts) {
                $counts[$row->client_ip]['*'] = ($counts[$row->client_ip]['*'] ?? 0) + (int) $row->requests;
                $counts[$row->client_ip][(int) $row->ja4_id] = (int) $row->requests;
            });

        return $counts;
    }

    /**
     * The latest request from each listed IP to the team's sites, for where it comes from: its network
     * and location. Looked for back to the earliest last match among them (a day before, at most 30 days
     * back), since edge_requests has no index on the IP.
     *
     * @return SupportCollection<string, EdgeRequest>
     */
    #[Computed]
    public function clientOrigins(): SupportCollection
    {
        $ips = $this->verdicts->pluck('client_ip')->filter()->unique()->values();

        if ($ips->isEmpty()) {
            return collect();
        }

        $since = max($this->verdicts->min('last_matched_at')->copy()->subDay(), now()->subDays(30));

        $latestIds = EdgeRequest::query()
            ->toBase()
            ->whereIn('domain_id', $this->team->domains()->select('id'))
            ->where('occurred_at', '>=', $since)
            ->whereIn('client_ip', $ips)
            ->groupBy('client_ip')
            ->selectRaw('max(id) as id')
            ->pluck('id');

        return EdgeRequest::query()->whereKey($latestIds)->with(['network', 'location'])->get(['id', 'client_ip', 'asn', 'location_id'])->keyBy('client_ip');
    }

    /**
     * Count a client's requests over the period: those from its IP and fingerprint, or from its IP alone
     * when it was tagged by IP. Null for a client tagged by fingerprint alone, which isn't counted.
     */
    public function requestsFrom(Verdict $verdict): ?int
    {
        if ($verdict->client_ip === null) {
            return null;
        }

        return $this->recentRequests[$verdict->client_ip][$verdict->ja4_id ?? '*'] ?? 0;
    }

    /**
     * Count the requests each tag's clients (and the banned ones) sent the team's sites over the period,
     * keyed like the groups: by tag, "" for bans. A request counts once per tag, however many of its
     * holders it matches; clients held by fingerprint alone aren't counted.
     *
     * @return SupportCollection<string, int>
     */
    protected function requestsByGroup(): SupportCollection
    {
        return collect(Cache::remember("tags:requests:{$this->team->id}:{$this->period}", 60, fn (): array => $this->countRequestsByGroup()->all()));
    }

    /**
     * @return SupportCollection<string, int>
     */
    protected function countRequestsByGroup(): SupportCollection
    {
        return $this->team->verdicts()->active()->toBase()
            ->join('edge_requests', 'edge_requests.client_ip', '=', 'verdicts.client_ip')
            ->whereRaw('(verdicts.ja4_id is null or verdicts.ja4_id = edge_requests.ja4_id)')
            ->whereIn('edge_requests.domain_id', $this->team->domains()->select('id'))
            ->where('edge_requests.occurred_at', '>=', now()->subSeconds(TrafficPeriod::from($this->period)->seconds()))
            ->groupBy('verdicts.action', 'verdicts.tag')
            ->selectRaw('verdicts.action, verdicts.tag, count(distinct edge_requests.id) as requests')
            ->get()
            ->groupBy(fn (object $row) => (int) $row->action === RuleAction::Ban->value ? '' : (string) $row->tag)
            ->map(fn (SupportCollection $rows) => (int) $rows->sum('requests'));
    }

    protected function isSearching(): bool
    {
        return trim($this->search) !== '';
    }

    /**
     * Forget a period the page doesn't offer.
     */
    protected function normalizePeriod(): void
    {
        if (! in_array($this->period, self::PERIODS, true)) {
            $this->period = '1h';
        }
    }

    /**
     * Lift the matching verdicts among the ones the page shows.
     *
     * @param  Closure(Builder<Verdict>): Builder<Verdict>  $scope
     */
    protected function liftWhere(Closure $scope): void
    {
        Gate::authorize('create', [EdgeRule::class, $this->team]);

        $lifted = $scope($this->filteredVerdicts()->getQuery())->update(['lifted_at' => now()]);

        $this->selected = [];
        unset($this->groups, $this->verdicts, $this->verdictCount, $this->viewerVerdicts);

        Flux\Flux::toast(variant: 'success', text: trans_choice('Lifted :count client.|Lifted :count clients.', $lifted, ['count' => Number::format($lifted)]));
    }

    /**
     * @return HasMany<Verdict, Team>
     */
    protected function filteredVerdicts(): HasMany
    {
        $search = trim($this->search);

        return $this->team->verdicts()
            ->active()
            ->when($search === '' && $this->bans, fn (Builder $query) => $query->where('action', RuleAction::Ban))
            ->when($search === '' && ! $this->bans, fn (Builder $query) => $query->where('action', RuleAction::Tag)->where('tag', $this->tag))
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $query) use ($search) {
                    if (IpRange::isRange($search)) {
                        IpRange::constrain($query->getQuery(), 'client_ip', $search);
                    } else {
                        $query->whereRaw('CAST(client_ip AS TEXT) LIKE ?', ["{$search}%"]);
                    }

                    $query->orWhereIn('ja4_id', TlsFingerprint::query()->where('type', TlsFingerprintType::Ja4)->where('value', 'like', "%{$search}%")->select('id'));

                    foreach (app(LabelDirectory::class)->search($this->team->id, $search) as $clientLabel) {
                        match ($clientLabel->kind) {
                            LabelKind::Ip => $query->orWhere('client_ip', $clientLabel->value),
                            LabelKind::Ja4 => $query->orWhereIn('ja4_id', TlsFingerprint::query()->where('type', TlsFingerprintType::Ja4)->where('value', $clientLabel->value)->select('id')),
                            default => null,
                        };
                    }
                });
            });
    }
}; ?>

<div>
    <x-ntb.page-heading
        :overline="__('Who your rules have marked')"
        :title="__('Tags & bans')"
        :description="__('Every client a rule has tagged or banned, until the tag expires or you lift it. Lifting lets the client go right away; the rule can tag it again if it keeps matching.')"
    />

    @if ($viewerIp)
        @if ($this->viewerVerdicts->isNotEmpty())
            <div class="mb-6 flex flex-col gap-3 rounded-xl border border-rose-300 bg-rose-50 p-4 text-sm sm:flex-row sm:items-center dark:border-rose-900 dark:bg-rose-950/40" data-test="viewer-held">
                <flux:icon.no-symbol class="size-5 shrink-0 text-rose-600 dark:text-rose-400" />
                <p class="flex-1 text-rose-900 dark:text-rose-100">
                    {{ __('You’re browsing from :ip, and your rules hold it:', ['ip' => $viewerIp]) }}
                    <span class="font-medium">{{ $this->viewerVerdicts->map(fn ($verdict) => $verdict->rule?->name ?? __('a deleted rule'))->unique()->join(', ') }}</span>.
                    {{ __('Sites in the gym may be turning you away.') }}
                </p>
                @if ($this->canManage)
                    <flux:button size="sm" variant="danger" wire:click="liftMine" data-test="lift-mine">{{ __('Lift mine') }}</flux:button>
                @endif
            </div>
        @else
            <p class="-mt-4 mb-6 text-xs text-zinc-500 dark:text-zinc-400" data-test="viewer-free">{{ __('You’re browsing from :ip. No rule holds it.', ['ip' => $viewerIp]) }}</p>
        @endif
    @endif

    @if ($this->groups->isEmpty())
        <x-ntb.panel>
            <x-ntb.empty-state :heading="__('Nobody is tagged right now.')">
                {{ __('When an enabled rule tags or bans a client, it shows up here.') }}
            </x-ntb.empty-state>
        </x-ntb.panel>
    @else
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="flex-1">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" size="sm" :placeholder="__('Search every tag and ban: an IP, a range like 198.51.100.0/24, a fingerprint or a name you gave…')" clearable data-test="tag-search" />
            </div>
            <flux:select wire:model.live="period" size="sm" class="sm:w-52" :aria-label="__('Count requests over')" data-test="tag-period">
                @foreach ($this::PERIODS as $periodOption)
                    <flux:select.option :value="$periodOption">{{ __('Requests: :period', ['period' => mb_strtolower(TrafficPeriod::from($periodOption)->label())]) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-test="tag-groups">
            @foreach ($this->groups as $group)
                @php($isOpen = $group['tag'] === null ? $bans : $tag === $group['tag'])
                <button
                    type="button"
                    wire:key="tag-group-{{ $group['tag'] ?? '@ban' }}"
                    @if ($group['tag'] === null) wire:click="showBans" @else wire:click="show(@js($group['tag']))" @endif
                    @class([
                        'rounded-xl border bg-white p-4 text-start transition hover:border-zinc-400 dark:bg-zinc-900 dark:hover:border-zinc-600',
                        'border-zinc-900 ring-1 ring-zinc-900 dark:border-zinc-200 dark:ring-zinc-200' => $isOpen,
                        'border-line dark:border-zinc-800' => ! $isOpen,
                    ])
                    data-test="tag-group"
                >
                    <div class="flex items-center justify-between gap-2">
                        <flux:badge size="sm" :color="$group['tag'] === null ? 'red' : 'amber'" icon="tag">{{ $group['tag'] ?? __('Banned') }}</flux:badge>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('last :time', ['time' => \Illuminate\Support\Carbon::parse($group['last_matched_at'])->diffForHumans(short: true)]) }}</span>
                    </div>
                    <div class="mt-3 flex items-baseline gap-4">
                        <div class="text-2xl font-semibold tabular-nums">{{ Number::format($group['clients']) }} <span class="text-sm font-normal text-zinc-500 dark:text-zinc-400">{{ trans_choice('client|clients', $group['clients']) }}</span></div>
                        <div class="text-2xl font-semibold tabular-nums" data-test="tag-group-requests">{{ Number::format($group['requests']) }} <span class="text-sm font-normal text-zinc-500 dark:text-zinc-400">{{ trans_choice('request|requests', $group['requests']) }}, {{ mb_strtolower(TrafficPeriod::from($period)->label()) }}</span></div>
                    </div>
                    <dl class="mt-3 space-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                        <div class="flex gap-1"><dt class="shrink-0">{{ __('Set by') }}</dt><dd class="truncate text-zinc-700 dark:text-zinc-300">{{ $group['rules']->pluck('name')->join(', ') ?: __('a deleted rule') }}</dd></div>
                        <div class="flex gap-1"><dt class="shrink-0">{{ __('Gym') }}</dt><dd class="truncate text-zinc-700 dark:text-zinc-300">{{ $group['routines']->pluck('name')->join(', ') ?: __('none, just a label') }}</dd></div>
                    </dl>
                </button>
            @endforeach
        </div>

        @php($searching = trim($search) !== '')
        @if ($tag !== '' || $bans || $searching)
            @php($group = $searching ? null : $this->groups->first(fn (array $group) => $bans ? $group['tag'] === null : $group['tag'] === $tag))
            <x-ntb.panel class="mt-6" :heading="match (true) { $searching => __('Clients matching “:search” in every tag and ban', ['search' => trim($search)]), $bans => __('Banned clients'), default => __('Clients tagged “:tag”', ['tag' => $tag]) }" :padded="false">
                @if ($this->canManage && $this->verdicts->isNotEmpty())
                    <div class="flex items-center justify-end gap-2 border-b border-line p-4 dark:border-zinc-800">
                        @if ($selected !== [])
                            <flux:button size="sm" variant="primary" wire:click="liftSelected" data-test="lift-selected">{{ __('Lift :count selected', ['count' => count($selected)]) }}</flux:button>
                        @endif
                        <flux:button size="sm" wire:click="liftAll" wire:confirm="{{ __('Lift all :count? Rules can tag them again if they keep matching.', ['count' => Number::format($this->verdictCount)]) }}" data-test="lift-all">{{ __('Lift all :count', ['count' => Number::format($this->verdictCount)]) }}</flux:button>
                    </div>
                @endif

                @if ($group && $group['routines']->isNotEmpty())
                    <div class="border-b border-line px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                        {{ __('These clients go through the gym:') }}
                        @foreach ($group['routines'] as $routine)
                            <a href="{{ route('gym.routines.edit', $routine) }}" wire:navigate class="font-medium text-zinc-700 hover:underline dark:text-zinc-300">{{ $routine->name }}</a>@if (! $loop->last), @endif
                        @endforeach
                    </div>
                @endif

                @if ($this->verdicts->isEmpty())
                    <x-ntb.empty-state :heading="__('No clients match.')">
                        {{ $searching ? __('Try a different search.') : __('Nobody holds this tag any more.') }}
                    </x-ntb.empty-state>
                @else
                    <div class="px-4 pb-2">
                        <flux:table>
                            <flux:table.columns>
                                @if ($this->canManage)
                                    <flux:table.column class="w-8"></flux:table.column>
                                @endif
                                <flux:table.column>{{ __('Client') }}</flux:table.column>
                                @if ($searching)
                                    <flux:table.column>{{ __('Tag') }}</flux:table.column>
                                @endif
                                <flux:table.column>{{ __('Rule') }}</flux:table.column>
                                <flux:table.column align="end" class="max-md:hidden">{{ __('Matched') }}</flux:table.column>
                                <flux:table.column align="end">{{ __('Requests, :period', ['period' => mb_strtolower(TrafficPeriod::from($period)->label())]) }}</flux:table.column>
                                <flux:table.column class="max-md:hidden">{{ __('Tagged') }}</flux:table.column>
                                <flux:table.column class="max-md:hidden">{{ __('Last matched') }}</flux:table.column>
                                <flux:table.column>{{ __('Expires') }}</flux:table.column>
                                <flux:table.column></flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($this->verdicts as $verdict)
                                    <flux:table.row :key="'tagged-'.$verdict->id" data-test="tagged-client">
                                        @if ($this->canManage)
                                            <flux:table.cell>
                                                <flux:checkbox wire:model.live="selected" value="{{ $verdict->id }}" :aria-label="__('Select')" />
                                            </flux:table.cell>
                                        @endif
                                        <flux:table.cell class="font-mono text-xs">
                                            @if ($verdict->client_ip)
                                                @php($origin = $this->clientOrigins->get($verdict->client_ip))
                                                <span class="group/label flex items-center gap-1">
                                                    <a href="{{ route('activity.index', ['ip' => $verdict->client_ip, 'period' => '7d']) }}" wire:navigate class="hover:underline"><x-ntb.label kind="ip" :value="$verdict->client_ip" /></a>
                                                    <x-ntb.country :code="$origin?->location?->country_code" class="ms-1 text-zinc-400" />
                                                    @if (filled($origin?->location?->region))
                                                        <span class="truncate font-sans text-[0.65rem] text-zinc-400" data-test="client-region">{{ $origin->location->region }}</span>
                                                    @endif
                                                    <x-ntb.label-button kind="ip" :value="$verdict->client_ip" />
                                                </span>
                                                @if ($origin?->network)
                                                    <span class="mt-0.5 flex max-w-64 items-center gap-1 font-sans text-[0.65rem] text-zinc-500 dark:text-zinc-400" title="AS{{ $origin->asn }} · {{ $origin->network->name }}" data-test="client-network">
                                                        <flux:icon.server-stack variant="micro" class="size-3 shrink-0" />
                                                        <span class="truncate">{{ $origin->network->name }}</span>
                                                    </span>
                                                @endif
                                            @endif
                                            @if ($verdict->ja4Fingerprint)
                                                <span class="group/label mt-0.5 flex items-center gap-1 text-[0.65rem] text-zinc-500 dark:text-zinc-400">
                                                    <a href="{{ route('activity.index', ['ja4' => $verdict->ja4Fingerprint->value, 'period' => '7d']) }}" wire:navigate class="flex items-center gap-1 hover:underline">
                                                        <flux:icon.finger-print variant="micro" class="size-3" /><x-ntb.label kind="ja4" :value="$verdict->ja4Fingerprint->value" />
                                                    </a>
                                                    <x-ntb.label-button kind="ja4" :value="$verdict->ja4Fingerprint->value" />
                                                </span>
                                            @endif
                                        </flux:table.cell>
                                        @if ($searching)
                                            <flux:table.cell>
                                                <flux:badge size="sm" :color="$verdict->action === RuleAction::Ban ? 'red' : 'amber'">{{ $verdict->action === RuleAction::Ban ? __('Banned') : $verdict->tag }}</flux:badge>
                                            </flux:table.cell>
                                        @endif
                                        <flux:table.cell class="text-xs">
                                            @if ($verdict->rule)
                                                <a href="{{ route('rules.index', ['rule' => $verdict->rule->id]) }}" wire:navigate class="hover:underline">{{ $verdict->rule->name }}</a>
                                            @else
                                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Deleted rule') }}</span>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell align="end" class="font-mono text-xs tabular-nums max-md:hidden">{{ Number::format($verdict->matched_requests) }}</flux:table.cell>
                                        <flux:table.cell align="end" class="font-mono text-xs tabular-nums" data-test="client-requests">{{ ($requests = $this->requestsFrom($verdict)) === null ? '—' : Number::format($requests) }}</flux:table.cell>
                                        <flux:table.cell class="text-xs whitespace-nowrap max-md:hidden">{{ $verdict->created_at?->diffForHumans(short: true) }}</flux:table.cell>
                                        <flux:table.cell class="text-xs whitespace-nowrap max-md:hidden">{{ $verdict->last_matched_at->diffForHumans(short: true) }}</flux:table.cell>
                                        <flux:table.cell class="text-xs whitespace-nowrap">{{ $verdict->expires_at ? __('in :time', ['time' => $verdict->expires_at->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true)]) : __('never') }}</flux:table.cell>
                                        <flux:table.cell align="end">
                                            @if ($this->canManage)
                                                <flux:button size="xs" variant="ghost" wire:click="lift({{ $verdict->id }})" data-test="lift-tag">{{ __('Lift') }}</flux:button>
                                            @endif
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>

                        @if ($this->verdictCount > $this->verdicts->count())
                            <div class="flex items-center justify-center gap-3 py-3 text-xs text-zinc-500 dark:text-zinc-400" data-test="tagged-more">
                                {{ __('Showing :shown of :total, most recently matched first.', ['shown' => Number::format($this->verdicts->count()), 'total' => Number::format($this->verdictCount)]) }}
                                @if ($limit < 1000)
                                    <flux:button size="xs" wire:click="showMore" data-test="tagged-show-more">{{ __('Show more') }}</flux:button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            </x-ntb.panel>
        @endif
    @endif

    <livewire:pages::clients.label-modal />
</div>
