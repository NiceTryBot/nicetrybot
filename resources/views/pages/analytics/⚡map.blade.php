<?php

use App\Http\Controllers\Edge\ListLiveTailsController;
use App\Models\EdgeRequest;
use App\Models\LiveTail;
use App\Models\Team;
use App\Support\TrafficMap;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Live map')] class extends Component {
    /**
     * How far back the first load reaches, so the map isn't empty while the edge connects.
     */
    public const int BACKFILL_SECONDS = 180;

    /**
     * The most requests one poll returns. A backlog (say, an S3 import) arrives over a few polls.
     */
    public const int BATCH = 500;

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    public function mount(): void
    {
        $this->heartbeat();
    }

    /**
     * Keep the edge sending this team's requests as they happen, for as long as the map is open. The
     * page calls this every 10 seconds while it's visible; once it stops, the tail goes idle within 30.
     * Renderless, like recent(): a re-render would wipe what the page has drawn on the map.
     */
    #[Renderless]
    public function heartbeat(): void
    {
        $this->team->liveTails()->updateOrCreate(['user_id' => Auth::id()], [
            'domain_id' => null,
            'expires_at' => now()->addMinutes(LiveTail::LIFETIME_MINUTES),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * The team's requests after the given id (or the last few minutes on first load), placed on the map.
     *
     * @return array{now: int, live: bool, last: int, requests: list<array<string, mixed>>}
     */
    #[Renderless]
    public function recent(int $after = 0): array
    {
        $query = $this->team->edgeRequests()->with(['location', 'domain:id,hostname']);

        $rows = $after === 0
            ? $query->where('occurred_at', '>=', now()->subSeconds(self::BACKFILL_SECONDS))->latest('edge_requests.id')->limit(self::BATCH)->get()->reverse()
            : $query->where('edge_requests.id', '>', $after)->where('occurred_at', '>=', now()->subMinutes(15))->orderBy('edge_requests.id')->limit(self::BATCH)->get();

        return [
            'now' => now()->getTimestampMs(),
            'live' => (int) Cache::get(ListLiveTailsController::POLLED_AT_CACHE_KEY, 0) >= now()->subSeconds(20)->getTimestamp(),
            'last' => (int) ($rows->max('id') ?? $after),
            'requests' => $rows
                ->map(function (EdgeRequest $request) {
                    $coordinates = $request->location?->coordinates();

                    if ($coordinates === null) {
                        return null;
                    }

                    [$x, $y] = TrafficMap::project(...$coordinates);
                    $category = $request->requestPath?->category;

                    return [
                        'id' => $request->id,
                        't' => $request->occurred_at->getTimestampMs(),
                        'x' => $x,
                        'y' => $y,
                        'icon' => TrafficMap::iconFor($category),
                        'family' => TrafficMap::familyFor($category),
                        'action' => $request->action->slug(),
                        'status' => $request->status_code,
                        'host' => $request->domain->hostname,
                        'path' => Str::limit((string) $request->path, 80),
                        'place' => $request->location->label(),
                    ];
                })
                ->filter()
                ->values()
                ->all(),
        ];
    }
}; ?>

<div>
    <x-ntb.page-heading
        :overline="__('Who is knocking')"
        :title="__('Analytics')"
        :description="__('Where requests come from and what software sent them: networks, countries, user agents, and TLS fingerprints.')"
    />

    <x-ntb.analytics-tabs />

    <x-ntb.traffic-map-icons />

    <div
        x-data="trafficMap(@js(['edge' => TrafficMap::EDGE, 'width' => TrafficMap::WIDTH]))"
        class="space-y-4"
        data-test="traffic-map"
    >
        <div class="flex flex-wrap items-end gap-x-8 gap-y-3">
            <div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Requests per second') }}</p>
                <p class="font-mono text-3xl font-semibold tabular-nums" x-text="perSecond.toFixed(1)">0.0</p>
            </div>
            <div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Last minute') }}</p>
                <p class="font-mono text-3xl font-semibold tabular-nums" x-text="minuteTotal">0</p>
            </div>
            <div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Probes, last minute') }}</p>
                <p class="font-mono text-3xl font-semibold tabular-nums text-(--chart-suspicious)" x-text="minuteProbes">0</p>
            </div>
            <div class="ms-auto flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300" data-test="traffic-map-status">
                <span class="relative flex size-2">
                    <span class="absolute inline-flex size-full rounded-full opacity-75" :class="live ? 'animate-ping bg-emerald-400' : 'bg-amber-400'"></span>
                    <span class="relative inline-flex size-2 rounded-full" :class="live ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                </span>
                <span x-text="statusText">{{ __('Connecting to the edge…') }}</span>
            </div>
        </div>

        <x-ntb.panel :padded="false" wire:ignore>
            <svg
                viewBox="0 0 {{ TrafficMap::WIDTH }} {{ TrafficMap::HEIGHT }}"
                class="block h-auto w-full"
                role="img"
                aria-label="{{ __('World map of requests flying to the edge as they arrive, each marked with an icon for its kind of path.') }}"
            >
                <x-ntb.world-land class="fill-zinc-200 dark:fill-zinc-800" />

                <line x1="24" x2="{{ TrafficMap::WIDTH - 24 }}" y1="{{ TrafficMap::MAP_HEIGHT + 10 }}" y2="{{ TrafficMap::MAP_HEIGHT + 10 }}" class="stroke-zinc-200 dark:stroke-zinc-800" stroke-dasharray="3 5" />

                {{-- The last minute, one bar per second. --}}
                <text x="40" y="{{ TrafficMap::MAP_HEIGHT + 36 }}" class="fill-zinc-500 text-[11px] dark:fill-zinc-400">{{ __('Requests per second, last 60s') }}</text>
                <g x-ref="bars" transform="translate(40 {{ TrafficMap::MAP_HEIGHT + 48 }})"></g>

                <g x-ref="trails"></g>
                <g x-ref="pings"></g>
                <g x-ref="flights"></g>

                {{-- The edge sits below the map, where every request lands. --}}
                <g transform="translate({{ TrafficMap::EDGE[0] }} {{ TrafficMap::EDGE[1] }})">
                    <circle r="30" class="tm-edge-halo" x-ref="halo" />
                    <circle r="22" class="fill-ink dark:fill-zinc-100" />
                    <use href="#tm-icon-edge" x="-13" y="-13" width="26" height="26" class="fill-white dark:fill-ink" />
                </g>
                <text x="{{ TrafficMap::EDGE[0] + 36 }}" y="{{ TrafficMap::EDGE[1] - 3 }}" class="fill-ink text-[13px] font-semibold dark:fill-white">{{ __('Edge') }}</text>
                @if (config('edge.label'))
                    <text x="{{ TrafficMap::EDGE[0] + 36 }}" y="{{ TrafficMap::EDGE[1] + 13 }}" class="fill-zinc-500 text-[11px] dark:fill-zinc-400">{{ config('edge.label') }}</text>
                @endif
            </svg>
        </x-ntb.panel>

        <div class="grid gap-4 lg:grid-cols-[1fr_1.4fr]">
            <x-ntb.panel :heading="__('By kind of path')" :subheading="__('Last minute. Color shows the family: pages, assets, probes.')">
                <ul class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    @foreach (TrafficMap::legend() as $entry)
                        <li class="flex items-center gap-2" title="{{ $entry['description'] }}" wire:key="legend-{{ $entry['icon'] }}">
                            <span class="tm-{{ $entry['family'] }} flex size-6 shrink-0 items-center justify-center rounded-full bg-(--tm-family)">
                                <svg class="size-3.5 fill-white" aria-hidden="true"><use href="#tm-icon-{{ $entry['icon'] }}" /></svg>
                            </span>
                            <span class="min-w-0 flex-1 truncate font-mono text-xs">{{ $entry['label'] }}</span>
                            <span class="font-mono text-xs tabular-nums text-zinc-500 dark:text-zinc-400" x-text="counts['{{ $entry['icon'] }}'] ?? 0">0</span>
                        </li>
                    @endforeach
                </ul>
            </x-ntb.panel>

            <x-ntb.panel :heading="__('Just arrived')" :padded="false">
                <ul class="divide-y divide-line text-xs dark:divide-zinc-800" data-test="traffic-map-ticker">
                    <template x-for="item in ticker" :key="item.id">
                        <li class="flex items-center gap-3 px-5 py-2">
                            <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-(--tm-family)" :class="'tm-' + item.family">
                                <svg class="size-3.5 fill-white" aria-hidden="true"><use :href="'#tm-icon-' + item.icon" /></svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-mono text-ink dark:text-zinc-100" x-text="item.path"></span>
                                <span class="block truncate text-zinc-500 dark:text-zinc-400" x-text="item.host + ' · ' + item.place"></span>
                            </span>
                            <span class="font-mono tabular-nums text-zinc-500 dark:text-zinc-400" x-text="item.status"></span>
                            <span class="w-16 text-end text-zinc-500 dark:text-zinc-400" x-text="item.action"></span>
                        </li>
                    </template>
                    <li x-show="ticker.length === 0" class="px-5 py-6 text-center text-zinc-500 dark:text-zinc-400">{{ __('Waiting for the first request…') }}</li>
                </ul>
            </x-ntb.panel>
        </div>
    </div>

    <div class="mt-4 flex gap-3 rounded-xl border border-dashed border-line px-4 py-3 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
        <flux:icon.information-circle variant="mini" class="size-4 shrink-0" />
        <p>
            {{ __('Each request is replayed at the moment it reached the edge. While this page is open the edge streams your requests within seconds; otherwise they arrive about a minute late, and the map runs that far behind. Locations are city-level; older ones sit at their country\'s centre.') }}
            <a href="https://db-ip.com" target="_blank" rel="noopener" class="underline">{{ __('IP geolocation by DB-IP') }}</a> ({{ __('CC BY 4.0') }}).
            {{ __('Icons: Wikimedia Codex and Astro UXDS.') }}
        </p>
    </div>
</div>

<script>
    // Livewire ships this script as a static module, so it must not contain Blade: the edge's position
    // arrives as the component's argument from x-data instead.
    Alpine.data('trafficMap', (config) => ({
        live: false,
        perSecond: 0,
        minuteTotal: 0,
        minuteProbes: 0,
        counts: {},
        ticker: [],
        statusText: 'Connecting to the edge…',

        init() {
            this.lastId = 0
            this.queue = []
            this.flights = []
            this.minute = []
            this.seconds = new Array(60).fill(0)
            this.thisSecond = 0
            this.skew = 0
            this.delay = 4000
            this.lags = []
            this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
            this.drawBars()

            this.poll(true)
            this.timers = [
                setInterval(() => this.poll(false), 2000),
                setInterval(() => document.hidden || this.$wire.heartbeat(), 10000),
                setInterval(() => this.everySecond(), 1000),
            ]
            this.tick = this.tick.bind(this)
            this.frame = requestAnimationFrame(this.tick)
        },

        destroy() {
            this.timers.forEach(clearInterval)
            cancelAnimationFrame(this.frame)
        },

        now() {
            return Date.now() + this.skew
        },

        async poll(first) {
            if (this.polling) return
            this.polling = true

            try {
                const data = await this.$wire.recent(this.lastId)
                this.skew = data.now - Date.now()
                this.live = data.live
                this.lastId = Math.max(this.lastId, data.last)

                if (first) {
                    // The backfill fills the counters, the list and the bars (each in the second it
                    // happened) without flying every request at once.
                    const now = this.now()
                    data.requests.forEach((request) => {
                        this.record(request, request.t)
                        const age = Math.floor((now - request.t) / 1000)
                        if (age >= 0 && age < 60) this.seconds[59 - age]++
                        // Routes from the last 30 seconds, already as faded as they'd be by now.
                        if (age >= 0 && age < 30) this.drawTrail(request).style.animationDelay = `-${(now - request.t) / 1000}s`
                    })
                    this.thisSecond = 0
                    this.drawBars()
                } else {
                    const now = this.now()
                    data.requests.forEach((request) => {
                        this.lags.push(now - request.t)
                        this.queue.push(request)
                    })
                    this.queue.sort((a, b) => a.t - b.t)
                    this.adjustDelay()
                }
            } catch (error) {
                // A missed poll is caught up by the next one.
            } finally {
                this.polling = false
                this.updateStatus()
            }
        },

        // Replay far enough behind that late arrivals still play in order: the 90th percentile of how late
        // requests reach the page, plus a second. Jump straight up when they slow down; ease back down.
        adjustDelay() {
            this.lags = this.lags.slice(-60)
            if (this.lags.length === 0) return
            const sorted = [...this.lags].sort((a, b) => a - b)
            const target = Math.min(Math.max(sorted[Math.floor(sorted.length * 0.9)] + 1000, 2000), 15 * 60 * 1000)
            this.delay = target > this.delay ? target : this.delay + (target - this.delay) * 0.2
        },

        updateStatus() {
            const behind = Math.round(this.delay / 1000)
            this.statusText = this.live
                ? (behind <= 10 ? `Live · about ${behind}s behind` : `Catching up · about ${behind}s behind`)
                : `Edge not streaming yet · about ${behind}s behind`
        },

        tick() {
            const now = this.now()

            while (this.queue.length && this.queue[0].t + this.delay <= now) {
                const request = this.queue.shift()
                // Far overdue (the tab was hidden, or a backlog arrived): count it, don't animate it.
                this.record(request, now, now - (request.t + this.delay) < 8000)
            }

            const clock = performance.now()
            this.flights = this.flights.filter((flight) => {
                const progress = Math.min(1, (clock - flight.start) / flight.duration)
                const eased = progress < 0.5 ? 2 * progress * progress : 1 - Math.pow(-2 * progress + 2, 2) / 2
                const [x, y] = this.bezier(flight.from, flight.via, config.edge, eased)
                flight.el.setAttribute('transform', `translate(${x.toFixed(1)} ${y.toFixed(1)}) scale(${(1 - progress * 0.35).toFixed(2)})`)

                if (progress < 1) return true

                flight.el.remove()
                this.$refs.halo.classList.remove('tm-hit')
                void this.$refs.halo.getBBox()
                this.$refs.halo.classList.add('tm-hit')
                return false
            })

            this.frame = requestAnimationFrame(this.tick)
        },

        record(request, at, animate = false) {
            this.thisSecond++
            this.minute.push({ at, icon: request.icon, family: request.family })
            this.counts[request.icon] = (this.counts[request.icon] ?? 0) + 1
            this.minuteTotal = this.minute.length
            if (request.family === 'probe') this.minuteProbes++
            this.ticker.unshift(request)
            this.ticker.splice(8)

            if (animate) this.launch(request)
        },

        launch(request) {
            const svg = 'http://www.w3.org/2000/svg'
            const from = [request.x, request.y]
            const [ex, ey] = config.edge
            const via = this.via(from)

            const ping = document.createElementNS(svg, 'circle')
            ping.setAttribute('cx', from[0])
            ping.setAttribute('cy', from[1])
            ping.setAttribute('r', 5)
            ping.setAttribute('class', `tm-ping tm-${request.family}`)
            ping.addEventListener('animationend', () => ping.remove())
            this.$refs.pings.appendChild(ping)

            const distance = Math.hypot(ex - from[0], ey - from[1])
            const duration = 1100 + distance * 1.4
            const trail = this.drawTrail(request)

            if (this.reducedMotion || this.flights.length >= 250) return

            const length = trail.getTotalLength()
            trail.style.strokeDasharray = length
            trail.animate([{ strokeDashoffset: length }, { strokeDashoffset: 0 }], { duration, easing: 'ease-in-out' })

            const badge = document.createElementNS(svg, 'g')
            badge.setAttribute('class', `tm-${request.family}`)
            badge.setAttribute('transform', `translate(${from[0]} ${from[1]})`)
            const disc = document.createElementNS(svg, 'circle')
            disc.setAttribute('r', 8)
            disc.setAttribute('class', 'tm-badge')
            const icon = document.createElementNS(svg, 'use')
            icon.setAttribute('href', `#tm-icon-${request.icon}`)
            icon.setAttribute('x', -5.5)
            icon.setAttribute('y', -5.5)
            icon.setAttribute('width', 11)
            icon.setAttribute('height', 11)
            icon.setAttribute('class', 'fill-white')
            badge.append(disc, icon)
            this.$refs.flights.appendChild(badge)

            this.flights.push({ el: badge, from, via, start: performance.now(), duration })
        },

        // A request's route to the edge stays up for 30 seconds, fading as it goes (CSS), so busy sources
        // stand out. Only the newest few hundred are kept.
        drawTrail(request) {
            const [ex, ey] = config.edge
            const via = this.via([request.x, request.y])
            const trail = document.createElementNS('http://www.w3.org/2000/svg', 'path')
            trail.setAttribute('d', `M${request.x} ${request.y} Q${via[0]} ${via[1]} ${ex} ${ey}`)
            trail.setAttribute('class', `tm-trail tm-${request.family}`)
            trail.addEventListener('animationend', () => trail.remove())
            this.$refs.trails.appendChild(trail)
            while (this.$refs.trails.childElementCount > 400) this.$refs.trails.firstChild.remove()

            return trail
        },

        // The bend in a route: partway to the edge, lifted above the higher of the two ends.
        via(from) {
            const [ex, ey] = config.edge
            return [from[0] * 0.55 + ex * 0.45, Math.min(from[1], ey) - 50]
        },

        bezier(from, via, to, t) {
            const u = 1 - t
            return [
                u * u * from[0] + 2 * u * t * via[0] + t * t * to[0],
                u * u * from[1] + 2 * u * t * via[1] + t * t * to[1],
            ]
        },

        everySecond() {
            this.seconds.push(this.thisSecond)
            this.seconds.shift()
            this.thisSecond = 0
            this.perSecond = this.seconds.slice(-5).reduce((sum, count) => sum + count, 0) / 5

            const cutoff = this.now() - 60000
            const expired = this.minute.findIndex((entry) => entry.at >= cutoff)
            const gone = expired === -1 ? this.minute.splice(0) : this.minute.splice(0, expired)
            gone.forEach((entry) => {
                this.counts[entry.icon] = Math.max(0, (this.counts[entry.icon] ?? 1) - 1)
                if (entry.family === 'probe') this.minuteProbes = Math.max(0, this.minuteProbes - 1)
            })
            this.minuteTotal = this.minute.length

            this.drawBars()
            this.updateStatus()
        },

        drawBars() {
            const bars = this.$refs.bars
            const peak = Math.max(1, ...this.seconds)
            const width = 5
            const height = 44

            if (bars.childElementCount === 0) {
                this.seconds.forEach((_, index) => {
                    const bar = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
                    bar.setAttribute('x', index * (width + 1))
                    bar.setAttribute('width', width)
                    bar.setAttribute('rx', 1)
                    bar.setAttribute('class', 'tm-bar')
                    bars.appendChild(bar)
                })
            }

            this.seconds.forEach((count, index) => {
                const barHeight = count === 0 ? 1 : Math.max(2, (count / peak) * height)
                bars.children[index].setAttribute('y', height - barHeight)
                bars.children[index].setAttribute('height', barHeight)
            })
        },
    }))
</script>
