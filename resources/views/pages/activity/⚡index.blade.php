<?php

use App\Actions\Ai\TranslateSearch;
use App\Actions\Analytics\SummarizeTraffic;
use App\Actions\Clients\FindCurrentChromeVersion;
use App\Actions\Clients\FindCurrentFirefoxReleases;
use App\Actions\Clients\MeasureIpNeighborhoods;
use App\Actions\Paths\CategorizePath;
use App\Actions\Rules\BuildRuleQuery;
use App\Concerns\SearchesDomains;
use App\Data\BrowserReleases;
use App\Data\ClientProfile;
use App\Data\IpNeighborhood;
use App\Data\Ja4Fingerprint;
use App\Data\LabelDirectory;
use App\Data\RequestQuery;
use App\Data\RuleScriptParser;
use App\Data\RuleScriptSyntaxError;
use App\Enums\ActivityNoise;
use App\Enums\AiProvider;
use App\Enums\ChartInterval;
use App\Enums\ClientTrait;
use App\Enums\GymSessionStatus;
use App\Enums\LabelKind;
use App\Enums\PathCategory;
use App\Enums\RequestAction;
use App\Enums\RuleField;
use App\Enums\RuleSubject;
use App\Enums\TrafficPeriod;
use App\Gym\HashPuzzle;
use App\Http\Controllers\Edge\ListLiveTailsController;
use App\Models\ClientLabel;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\GymSession;
use App\Models\LiveTail;
use App\Models\RequestPath;
use App\Models\SavedSearch;
use App\Models\Team;
use App\Models\UserAgent;
use App\Models\Verdict;
use App\Support\AnthropicException;
use App\Support\ClientAddons;
use App\Support\RequestAddons;
use App\Support\RuleFieldDefinition;
use App\Support\RuleFields;
use App\Support\WhereCondition;
use App\Support\WholePeriodQuery;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Activity')] class extends Component {
    use SearchesDomains;
    use WithPagination;

    /**
     * The most clients a HAVING search lists out for the page's queries to share. Past it, each query
     * finds them again in the database.
     */
    private const int MAX_LISTED_CLIENTS = 5000;

    /**
     * How many rows a page of the list can show.
     */
    public const array PAGE_SIZES = [25, 100, 1000];

    /**
     * The search: a WHERE expression in the rule language, like "status = 404 AND path LIKE '/wp-%'".
     * The filters below it switch its clauses on and off.
     */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * The plain-English question the search was asked as, like "404s from hosting networks". Claude writes
     * the WHERE for it with the user's own key. Editing the WHERE by hand lets the question go.
     */
    #[Url(as: 'ask', except: '')]
    public string $question = '';

    /**
     * What Claude left out of the question, or guessed, when it wrote the search.
     */
    public ?string $askNote = null;

    /**
     * Why the last question didn't become a search.
     */
    public ?string $askError = null;

    /**
     * The domain, status and action the search picks, mirrored for the filter selects. Picking one
     * rewrites the search; editing the search moves them.
     */
    public string $domain = '';

    public string $status = '';

    public string $action = '';

    #[Url(except: '24h')]
    public string $period = '24h';

    /**
     * How much time each bar of the chart covers ("1m", "1h", "1d", …), or "" for the period's default:
     * one unit finer than the period, so a day shows its hours.
     */
    #[Url(as: 'by', except: '')]
    public string $chartInterval = '';

    /**
     * The stretch of the chart picked by clicking a bar or dragging across bars, as "start-end" Unix
     * seconds (the end left out), or "" for the whole period. It narrows the list, not the search: the
     * chart keeps showing the whole period, with the bars outside the stretch muted.
     */
    #[Url(except: '')]
    public string $range = '';

    /**
     * Requests from clients showing a trait family: "spoofed" or "outdated".
     */
    #[Url(except: '')]
    public string $client = '';

    /**
     * "mine" while the team's own traffic is hidden. Remembered for the next visit. The other kinds of
     * noise are clauses in the search.
     */
    #[Url(except: '')]
    public string $hide = '';

    /**
     * What the results list: "requests"; "clients" (as the search groups them, the IP and TLS
     * fingerprint together unless it says GROUP BY) with their requests counted; or "paths", each
     * distinct path once with its requests counted.
     */
    #[Url(except: 'requests')]
    public string $view = 'requests';

    /**
     * How many rows each page of the list shows: one of the page sizes.
     */
    #[Url(as: 'per', except: 25)]
    public int $perPage = 25;

    public ?int $inspectedRequestId = null;

    /**
     * The name the search is being saved under.
     */
    public string $savedSearchName = '';

    /**
     * Whether the page is tailing live: the edge sends requests as they happen and the list refreshes.
     */
    public bool $live = false;

    /**
     * The search (see countSignature) the chart, totals and tab counts were last loaded for. A page
     * opens without them, and a changed search or period drops them, so the list shows at once and
     * the chart's placeholder asks for them right after.
     */
    #[Locked]
    public ?string $countedSearch = null;

    /**
     * The search whose counts ran out of time: its chart says so instead of asking again.
     */
    #[Locked]
    public ?string $tooBigSearch = null;

    /**
     * How long, in seconds, a request may spend counting before giving up, within the gateway's 29.
     */
    private const float COUNTING_SECONDS = 22.0;

    /**
     * When this request has to stop counting (set by the first count it runs).
     */
    protected ?float $countingDeadline = null;

    public function mount(): void
    {
        $this->live = $this->liveTail?->isRunning() ?? false;
        $this->normalizePerPage();

        $search = new RequestQuery($this->search);
        $hidden = ActivityNoise::fromList(match (true) {
            request()->has('hide') => $this->hide,
            request()->has('q') => '',
            default => (string) session('activity.hide', ''),
        });

        foreach ([...array_filter(array_map(fn (ActivityNoise $noise) => $noise->clause(), $hidden)), ...$this->linkedClauses()] as $clause) {
            $search = $search->with($clause);
        }

        // Your own traffic stays hidden unless you chose to show it.
        $hidesMine = request()->has('hide')
            ? in_array(ActivityNoise::Mine, $hidden, true)
            : $this->hasMyTraffic && ! session('activity.show_mine', false);

        $this->hide = $hidesMine ? ActivityNoise::Mine->value : '';
        $this->search = $search->source;
        $this->syncFilters();
    }

    /**
     * Show or hide one kind of request, and remember the choice for next time.
     */
    public function toggleHide(string $kind): void
    {
        $noise = ActivityNoise::tryFrom($kind);

        if ($noise === null) {
            return;
        }

        if ($clause = $noise->clause()) {
            $this->search = (new RequestQuery($this->search))->toggled($clause)->source;
            $this->syncFilters();
        } else {
            $this->hide = $this->hide === ActivityNoise::Mine->value ? '' : ActivityNoise::Mine->value;
            session(['activity.show_mine' => $this->hide === '']);
        }

        $this->rememberHidden();
        $this->resetPage();
    }

    /**
     * Search for what the question asks. Claude writes the WHERE, and picks the period and client filter
     * the WHERE can't say, when the user connected a key. A question that's already a search (a WHERE, an
     * IP or a fingerprint) is used as it is, and without a key the words are matched as text. A new
     * question replaces the search, except for the noise it hides.
     *
     * It doesn't render: the page asks again once the search is set, so waiting on Claude and running the
     * search each get a whole request's time.
     */
    #[Renderless]
    public function ask(TranslateSearch $translateSearch): void
    {
        $this->reset('askNote', 'askError');

        $question = trim($this->question);
        $written = $question === '' ? '' : $this->writtenSearch($question);
        $credential = $written === null ? Auth::user()->aiCredential(AiProvider::Anthropic) : null;

        if ($written !== null || $credential === null) {
            $this->useSearch($written ?? RequestQuery::quote($question));

            return;
        }

        try {
            $translated = $translateSearch->handle(
                $credential,
                $question,
                (new RequestQuery($this->search))->without($this->hiddenClauses())->source,
                $this->domains->pluck('hostname')->all(),
                $this->client,
            );
        } catch (AnthropicException $exception) {
            $this->askError = $exception->getMessage();

            return;
        }

        if ($translated->search === '') {
            $this->askError = $translated->note ?? __('Claude couldn’t make a search of that. Try naming what to match, like a status, a path or a network.');

            return;
        }

        $this->useSearch($translated->search);
        $this->period = $translated->period?->value ?? $this->period;
        $this->normalizeChartInterval();
        $this->client = $translated->client;
        $this->view = $translated->view;
        $this->askNote = $translated->note;
    }

    /**
     * Search for the question's words as typed, instead of what Claude made of them.
     */
    public function searchWords(): void
    {
        $this->reset('askNote', 'askError');

        if (($question = trim($this->question)) !== '') {
            $this->useSearch(RequestQuery::quote($question));
        }
    }

    /**
     * Let the question go, and with it every clause but the noise it hides.
     */
    public function clearQuestion(): void
    {
        $this->reset('question', 'askNote', 'askError');
        $this->useSearch('');
    }

    /**
     * Remember whether the search is asked in plain English or written as a WHERE, for the next visit.
     */
    #[Renderless]
    public function rememberSearchMode(string $mode): void
    {
        session(['activity.search_mode' => $mode === 'where' ? 'where' : 'ask']);
    }

    /**
     * Open the form that saves the search, named as it was saved before, or after the question it was
     * asked as.
     */
    public function startSavingSearch(): void
    {
        $this->resetValidation('savedSearchName');
        $this->savedSearchName = $this->currentSavedSearch?->name ?? Str::limit(trim($this->question), 80, '');

        Flux::modal('save-search')->show();
    }

    /**
     * Save the search under its name, for the whole team. Saving under a name the team already uses
     * replaces that search, when the user may change it.
     */
    public function saveSearch(): void
    {
        Gate::authorize('create', [SavedSearch::class, $this->team]);

        $name = trim($this->validate(['savedSearchName' => ['required', 'string', 'max:255']])['savedSearchName']);
        $savedSearch = $this->team->savedSearches()->whereRaw('lower(name) = ?', [Str::lower($name)])->first();

        if ($savedSearch) {
            Gate::authorize('update', $savedSearch);
            $savedSearch->update(['name' => $name, 'parameters' => $this->searchParameters()]);
        } else {
            $this->team->savedSearches()->create(['user_id' => Auth::id(), 'name' => $name, 'parameters' => $this->searchParameters()]);
        }

        unset($this->savedSearches, $this->currentSavedSearch);
        Flux::modal('save-search')->close();
        Flux::toast(variant: 'success', text: __('Saved as “:name”.', ['name' => $name]));
    }

    /**
     * Remove a saved search.
     */
    public function deleteSavedSearch(int $savedSearchId): void
    {
        $savedSearch = $this->team->savedSearches()->findOrFail($savedSearchId);
        Gate::authorize('delete', $savedSearch);

        $savedSearch->delete();
        unset($this->savedSearches, $this->currentSavedSearch);
        Flux::toast(text: __('Removed “:name”.', ['name' => $savedSearch->name]));
    }

    /**
     * Get the query string that brings the page back to this search: the WHERE, the question it was
     * asked as and every filter the WHERE can't say. Whether your own traffic is hidden is always
     * kept, so the search doesn't fall back to hiding it. The picked stretch of the chart is left out:
     * it names moments that slide out of the period.
     *
     * @return array<string, string|int>
     */
    protected function searchParameters(): array
    {
        return array_filter([
            'q' => $this->search,
            'ask' => trim($this->question),
            'period' => $this->period === '24h' ? '' : $this->period,
            'by' => $this->chartInterval,
            'client' => $this->client,
            'view' => $this->view === 'requests' ? '' : $this->view,
            'per' => $this->perPage === 25 ? '' : $this->perPage,
        ], fn (string|int $value) => $value !== '') + ['hide' => $this->hide];
    }

    /**
     * Get the team's saved searches, by name.
     *
     * @return Collection<int, SavedSearch>
     */
    #[Computed]
    public function savedSearches(): Collection
    {
        return $this->team->savedSearches()->get();
    }

    /**
     * Get the saved search the page is showing, if it's showing one as saved.
     */
    #[Computed]
    public function currentSavedSearch(): ?SavedSearch
    {
        $parameters = $this->searchParameters();
        ksort($parameters);

        return $this->savedSearches->first(function (SavedSearch $savedSearch) use ($parameters) {
            $saved = $savedSearch->parameters;
            ksort($saved);

            return $saved == $parameters;
        });
    }

    /**
     * The search a question already is, when it needs no translating: a JA4 fingerprint, an IP or range,
     * or a WHERE written out.
     */
    protected function writtenSearch(string $question): ?string
    {
        if ($fingerprint = Ja4Fingerprint::tryFrom($question)) {
            return 'ja4 = '.RequestQuery::quote($fingerprint->value);
        }

        if (filter_var($question, FILTER_VALIDATE_IP) !== false || RuleField::isIpRange($question)) {
            return 'ip = '.RequestQuery::quote($question);
        }

        return (new RequestQuery($question))->parses() ? $question : null;
    }

    /**
     * Forget a chart interval the period doesn't offer, or that's its default anyway, so the chart and
     * its picker start on the period's default.
     */
    protected function normalizeChartInterval(): void
    {
        $period = TrafficPeriod::tryFrom($this->period) ?? TrafficPeriod::Day;

        if (ChartInterval::resolve($this->chartInterval, $period) === ChartInterval::default($period)) {
            $this->chartInterval = '';
        }
    }

    /**
     * Forget a page size the list doesn't offer, so a hand-edited URL can't ask for every row at once.
     */
    protected function normalizePerPage(): void
    {
        if (! in_array($this->perPage, self::PAGE_SIZES, true)) {
            $this->perPage = self::PAGE_SIZES[0];
        }
    }

    /**
     * Make a WHERE the page's search, keeping the noise that's hidden.
     */
    protected function useSearch(string $where): void
    {
        $search = new RequestQuery($where);

        foreach ($this->hiddenClauses() as $clause) {
            $search = $search->with($clause);
        }

        $this->search = $search->source;
        $this->syncFilters();
        $this->rememberHidden();
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if ($property === 'search') {
            // A WHERE edited by hand no longer says what the question asked.
            $this->reset('question', 'askNote', 'askError');

            // A JA4 pasted into search filters by that exact fingerprint instead of a text match.
            if ($fingerprint = Ja4Fingerprint::tryFrom($this->search)) {
                $this->search = 'ja4 = '.RequestQuery::quote($fingerprint->value);
            }

            $this->syncFilters();
            $this->rememberHidden();
        }

        if (in_array($property, ['domain', 'status', 'action'], true)) {
            $choices = $this->filterChoices()[$property];

            $this->search = (new RequestQuery($this->search))->choosing(array_values($choices), $choices[$this->{$property}] ?? null)->source;
            $this->syncFilters();
        }

        if (in_array($property, ['period', 'chartInterval'], true)) {
            $this->normalizeChartInterval();

            // The bars move, so a stretch picked on the old ones goes.
            $this->range = '';
        }

        if ($property === 'range' && $this->selectedRange === null) {
            $this->range = '';
        }

        if ($property === 'view' && ! in_array($this->view, ['requests', 'clients', 'paths'], true)) {
            $this->view = 'requests';
        }

        if ($property === 'perPage') {
            $this->normalizePerPage();
        }

        if (in_array($property, ['search', 'domain', 'status', 'action', 'period', 'chartInterval', 'range', 'client', 'view', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Take every clause out of the search but the noise it hides.
     */
    public function clearFilters(): void
    {
        $this->search = RequestQuery::fromClauses($this->hiddenClauses())->source;

        $this->reset('period', 'range', 'client');
        $this->syncFilters();
        $this->resetPage();
    }

    public function inspect(int $requestId): void
    {
        $this->inspectedRequestId = $this->team->edgeRequests()->whereKey($requestId)->value('edge_requests.id');

        if ($this->inspectedRequestId !== null) {
            Flux::modal('request-detail')->show();
        }
    }

    /**
     * Categorize the path of a request by hand ("probe/config", "none" for an ordinary page), or hand it
     * back to the patterns ("auto"). Applies to every request for that path.
     */
    public function categorizePath(int $requestId, string $choice, CategorizePath $categorizePath): void
    {
        abort_unless($this->canManageRules, 403);

        $requestPath = $this->team->edgeRequests()->whereKey($requestId)->first()?->requestPath;

        if ($requestPath === null) {
            return;
        }

        $category = PathCategory::fromSlug($choice);

        match (true) {
            $choice === 'auto' => $categorizePath->automatic($requestPath),
            $choice === 'none' || $category !== null => $categorizePath->handle($requestPath, $category, Auth::user()),
            default => abort(422),
        };

        unset($this->requests, $this->paths, $this->totals);

        Flux::toast(text: $requestPath->category
            ? __(':path is now :category.', ['path' => Str::limit($requestPath->path, 60), 'category' => $requestPath->category->slug()])
            : __(':path is now an ordinary page.', ['path' => Str::limit($requestPath->path, 60)]));
    }

    /**
     * Leave one client (an IP or a TLS fingerprint) out of this view.
     */
    public function excludeClient(string $kind, string $value): void
    {
        if ($clause = $this->clientClause($kind, $value, exclude: true)) {
            $this->search = (new RequestQuery($this->search))->with($clause)->source;
            $this->resetPage();
        }
    }

    /**
     * Narrow the view to one client (an IP or a TLS fingerprint).
     */
    public function onlyClient(string $kind, string $value): void
    {
        if ($clause = $this->clientClause($kind, $value, exclude: false)) {
            $this->search = (new RequestQuery($this->search))->with($clause)->source;
            $this->resetPage();
        }
    }

    /**
     * List one client's requests, from the Clients view: the search narrowed to the IP and TLS
     * fingerprint that make the client, as the search groups it.
     */
    public function showClient(?string $ip = null, ?string $ja4 = null): void
    {
        $search = new RequestQuery($this->search);

        foreach (['ip' => $ip, 'ja4' => $ja4] as $kind => $value) {
            if ((string) $value !== '' && ($clause = $this->clientClause($kind, (string) $value, exclude: false))) {
                $search = $search->with($clause);
            }
        }

        $this->search = $search->source;
        $this->view = 'requests';
        $this->syncFilters();
        $this->resetPage();
    }

    /**
     * List one path's requests, from the Paths view: the search narrowed to that exact path. Paths are
     * shared across teams, so only one the team has requests for is taken.
     */
    public function showPath(int $pathId): void
    {
        if (($path = $this->team->edgeRequests()->where('path_id', $pathId)->first()?->path) === null) {
            return;
        }

        $this->search = (new RequestQuery($this->search))->with('path = '.RequestQuery::quote($path))->source;
        $this->view = 'requests';
        $this->syncFilters();
        $this->resetPage();
    }

    /**
     * Mark a client as the team's own traffic (naming it "Me" if it has no name yet) and hide it.
     */
    public function markMine(string $kind, string $value): void
    {
        $labelKind = LabelKind::tryFrom($kind);

        abort_unless($labelKind !== null && $labelKind->accepts($value), 422);

        $existing = $this->team->clientLabels()->where('kind', $labelKind)->where('value_hash', md5($value))->first();

        if ($existing === null) {
            Gate::authorize('create', [ClientLabel::class, $this->team]);
            $existing = $this->team->clientLabels()->create(['kind' => $labelKind, 'value' => $value, 'name' => __('Me'), 'is_mine' => true]);
        } else {
            Gate::authorize('update', $existing);
            $existing->update(['is_mine' => true]);
        }

        app(LabelDirectory::class)->forget($this->team->id);
        unset($this->hasMyTraffic);

        if ($clause = $this->clientClause($kind, $value, exclude: true)) {
            $this->search = (new RequestQuery($this->search))->without([$clause])->source;
        }

        $this->hide = ActivityNoise::Mine->value;
        session(['activity.show_mine' => false]);
        $this->rememberHidden();
        $this->resetPage();

        Flux::toast(variant: 'success', text: __(':name is your traffic now, and hidden.', ['name' => $existing->name]));
    }

    /**
     * Show how busy a client's IP and its neighbors (its /24, /16 and /8) were, above each octet of it
     * wherever it appears; or stop, if it already shows. Remembered for the team in this session.
     */
    public function enrichClient(string $ip): void
    {
        abort_unless(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false, 422);

        $enriched = in_array($ip, $this->enrichedIps, true)
            ? array_diff($this->enrichedIps, [$ip])
            : [...$this->enrichedIps, $ip];

        session(["activity.enriched.{$this->team->id}" => array_values(array_slice($enriched, -200))]);
        unset($this->enrichedIps, $this->neighborhoods, $this->neighborhoodScale);
    }

    /**
     * The clause that narrows the search to one client, or leaves it out. A request without a
     * fingerprint isn't one to leave out, so excluding a JA4 keeps those.
     */
    protected function clientClause(string $kind, string $value, bool $exclude): ?string
    {
        $labelKind = LabelKind::tryFrom($kind);

        if (! in_array($labelKind, [LabelKind::Ip, LabelKind::Ja4], true) || ! $labelKind->accepts($value)) {
            return null;
        }

        $quoted = RequestQuery::quote($value);

        return match (true) {
            $labelKind === LabelKind::Ip => $exclude ? "ip != {$quoted}" : "ip = {$quoted}",
            $exclude => "(ja4 IS NULL OR ja4 != {$quoted})",
            default => "ja4 = {$quoted}",
        };
    }

    /**
     * The clause each choice of the domain, status and action selects stands for.
     *
     * @return array{domain: array<string, string>, status: array<string, string>, action: array<string, string>}
     */
    protected function filterChoices(): array
    {
        $actionList = fn (array $actions) => 'action IN ('.implode(', ', array_map(fn (RequestAction $action) => RequestQuery::quote($action->slug()), $actions)).')';
        $statusRange = fn (int $hundred) => '(status >= '.($hundred * 100).' AND status < '.(($hundred + 1) * 100).')';

        return [
            'domain' => $this->domains->mapWithKeys(fn (Domain $domain) => [$domain->hostname => 'host = '.RequestQuery::quote($domain->hostname)])->all(),
            'status' => ['2xx' => $statusRange(2), '3xx' => $statusRange(3), '4xx' => $statusRange(4), '404' => 'status = 404', '5xx' => $statusRange(5)],
            'action' => [
                'suspicious' => $actionList(RequestAction::suspicious()),
                'kept-off' => $actionList(RequestAction::keptOffOrigin()),
                ...collect(RequestAction::cases())->mapWithKeys(fn (RequestAction $action) => [$action->slug() => 'action = '.RequestQuery::quote($action->slug())])->all(),
            ],
        ];
    }

    /**
     * Point the domain, status and action selects at what the search picks.
     */
    protected function syncFilters(): void
    {
        $search = new RequestQuery($this->search);

        foreach ($this->filterChoices() as $property => $choices) {
            $this->{$property} = $search->chosen($choices) ?? '';
        }
    }

    /**
     * The kinds of request hidden right now: by a clause in the search, or (your own traffic) by the toggle.
     *
     * @return list<ActivityNoise>
     */
    protected function hiddenNoise(): array
    {
        $search = new RequestQuery($this->search);

        return array_values(array_filter(ActivityNoise::cases(), fn (ActivityNoise $noise) => $noise->clause() === null
            ? $this->hide === ActivityNoise::Mine->value
            : $search->has($noise->clause())));
    }

    /**
     * The search clauses hiding noise right now.
     *
     * @return list<string>
     */
    protected function hiddenClauses(): array
    {
        return array_values(array_filter(array_map(fn (ActivityNoise $noise) => $noise->clause(), $this->hiddenNoise())));
    }

    /**
     * Every search clause a Hide toggle can add.
     *
     * @return list<string>
     */
    protected function noiseClauses(): array
    {
        return array_values(array_filter(array_map(fn (ActivityNoise $noise) => $noise->clause(), ActivityNoise::cases())));
    }

    /**
     * Remember what's hidden, so the next visit starts the same way.
     */
    protected function rememberHidden(): void
    {
        session(['activity.hide' => ActivityNoise::toList($this->hiddenNoise())]);
    }

    /**
     * The clauses older links ask for with their own parameters (?domain=, ?status=, ?ip=, ?ja4=, ?asn=, …),
     * so they keep landing on the same requests.
     *
     * @return list<string>
     */
    protected function linkedClauses(): array
    {
        $linked = fn (string $key) => is_string($value = request()->query($key)) ? trim($value) : '';
        $choices = $this->filterChoices();
        $clauses = [
            $choices['domain'][$linked('domain')] ?? null,
            $choices['status'][$linked('status')] ?? null,
            $choices['action'][$linked('action')] ?? null,
        ];

        if (($ip = $linked('ip')) !== '' && ($clause = $this->clientClause('ip', $ip, exclude: false))) {
            $clauses[] = $clause;
        }

        if (($ja4 = $linked('ja4')) !== '') {
            $clauses[] = 'ja4 = '.RequestQuery::quote(Ja4Fingerprint::tryFrom($ja4)?->value ?? $ja4);
        }

        if (ctype_digit($asn = $linked('asn'))) {
            $clauses[] = 'asn = '.(int) $asn;
        }

        if (strlen($country = $linked('country')) === 2) {
            $clauses[] = 'country = '.RequestQuery::quote(strtoupper($country));
        }

        if (ctype_digit($userAgentId = $linked('ua')) && ($userAgent = UserAgent::find((int) $userAgentId))) {
            $clauses[] = 'ua = '.RequestQuery::quote($userAgent->value);
        }

        if (ctype_digit($pathId = $linked('path')) && ($requestPath = RequestPath::find((int) $pathId))) {
            $clauses[] = 'path = '.RequestQuery::quote($requestPath->path);
        }

        if ($category = PathCategory::fromSlug($linked('category'))) {
            $clauses[] = 'category = '.RequestQuery::quote($category->slug());
        }

        foreach (array_filter(explode(',', $linked('exclude'))) as $entry) {
            [$kind, $value] = array_pad(explode(':', $entry, 2), 2, '');
            $clauses[] = $this->clientClause($kind, $value, exclude: true);
        }

        return array_values(array_filter($clauses));
    }

    /**
     * The traits a client shows, grouped by family ("spoofed", then "outdated") for its badges.
     *
     * @return array<string, list<ClientTrait>>
     */
    protected function clientTraits(ClientProfile $client): array
    {
        $grouped = [];

        foreach ($client->traits($this->browserReleases) as $trait) {
            $grouped[$trait->family()][] = $trait;
        }

        return $grouped;
    }

    /**
     * Re-render when a client is named, so the new name shows.
     */
    #[On('label-changed')]
    public function refreshClientNames(): void
    {
        //
    }

    /**
     * Start a live tail for ten minutes (of the filtered domain, or all of the team's), or stop it.
     */
    public function toggleLive(): void
    {
        if ($this->live) {
            $this->liveTail?->delete();
            $this->live = false;
            unset($this->liveTail);

            return;
        }

        $this->team->liveTails()->updateOrCreate(['user_id' => Auth::id()], [
            'domain_id' => $this->filteredDomainId(),
            'expires_at' => now()->addMinutes(LiveTail::LIFETIME_MINUTES),
            'last_seen_at' => now(),
        ]);

        $this->live = true;
        unset($this->liveTail);
        $this->resetPage();
    }

    /**
     * Polled while live: keep the tail going (following the domain filter) and refresh the list, or
     * stop once its ten minutes are up or it was ended elsewhere (e.g. logging out in another tab).
     */
    public function heartbeat(): void
    {
        $tail = $this->liveTail;

        if ($tail === null || ! $tail->isRunning()) {
            $tail?->delete();
            $this->live = false;
            unset($this->liveTail);
            Flux::toast(text: __('Live tail ended. New requests now show up within a couple of minutes.'));

            return;
        }

        $tail->update(['last_seen_at' => now(), 'domain_id' => $this->filteredDomainId()]);
    }

    /**
     * Lift one of the inspected client's tags (or bans) from the request details.
     */
    public function liftVerdict(int $verdictId): void
    {
        Gate::authorize('create', [EdgeRule::class, $this->team]);

        $this->team->verdicts()->active()->findOrFail($verdictId)->update(['lifted_at' => now()]);

        unset($this->inspectedVerdicts);
        Flux::toast(variant: 'success', text: __('Lifted. Rules can tag this client again if it keeps matching.'));
    }

    public function export(): StreamedResponse
    {
        if ($this->view === 'paths') {
            return $this->exportPaths();
        }

        $query = $this->filteredQuery()->with('domain:id,hostname')->latest('occurred_at')->latest('id')->limit(5000);

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['occurred_at', 'domain', 'client_ip', 'asn', 'network', 'country', 'region', 'city', 'method', 'path', 'status', 'duration_ms', 'action', 'bot_tag', 'user_agent', 'ja4', 'ja3']);

            foreach ($query->cursor() as $request) {
                fputcsv($handle, [
                    $request->occurred_at->toIso8601String(),
                    $request->domain->hostname,
                    $request->client_ip,
                    $request->asn,
                    $request->network?->name,
                    $request->location?->country_code,
                    $request->location?->region,
                    $request->location?->city,
                    $request->method->label(),
                    $request->path,
                    $request->status_code,
                    $request->duration_ms,
                    $request->action->slug(),
                    $request->bot_tag,
                    $request->user_agent,
                    $request->ja4,
                    $request->ja3,
                ]);
            }

            fclose($handle);
        }, 'nicetrybot-activity-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * The Paths view as a CSV: each distinct path the search finds, busiest first, with its counts.
     */
    protected function exportPaths(): StreamedResponse
    {
        $groups = $this->pathGroups()->limit(5000)->get();
        $paths = RequestPath::query()->findMany($groups->pluck('path_id'))->keyBy('id');

        return response()->streamDownload(function () use ($groups, $paths) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['path', 'category', 'requests', 'sites', 'ips', 'first_seen', 'last_seen']);

            foreach ($groups as $group) {
                fputcsv($handle, [
                    $paths->get($group->path_id)?->path,
                    $paths->get($group->path_id)?->category?->slug(),
                    $group->requests,
                    $group->hosts,
                    $group->ips,
                    CarbonImmutable::parse($group->first_seen)->toIso8601String(),
                    CarbonImmutable::parse($group->last_seen)->toIso8601String(),
                ]);
            }

            fclose($handle);
        }, 'nicetrybot-activity-paths-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Whether the team marked any IP, fingerprint or device as its own traffic.
     */
    #[Computed]
    public function hasMyTraffic(): bool
    {
        return app(LabelDirectory::class)->mine($this->team->id)->isNotEmpty();
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function liveTail(): ?LiveTail
    {
        return $this->team->liveTails()->where('user_id', Auth::id())->first();
    }

    /**
     * Whether the edge has asked for the live tail list since this tail started (it asks every ten
     * seconds), so its requests are on their way.
     */
    #[Computed]
    public function liveTailConnected(): bool
    {
        $tail = $this->liveTail;

        return $tail !== null && (int) Cache::get(ListLiveTailsController::POLLED_AT_CACHE_KEY, 0) >= $tail->startedAt()->getTimestamp();
    }

    /**
     * @return Collection<int, Domain>
     */
    /**
     * The IPs enriched to show their neighborhoods.
     *
     * @return list<string>
     */
    #[Computed]
    public function enrichedIps(): array
    {
        return session("activity.enriched.{$this->team->id}", []);
    }

    /**
     * How busy the enriched IPs on this page, and their neighbors, were.
     *
     * @return array<string, IpNeighborhood>
     */
    #[Computed]
    public function neighborhoods(): array
    {
        if ($this->enrichedIps === []) {
            return [];
        }

        $shown = match ($this->view) {
            'clients' => $this->clients->getCollection()->map(fn (object $client) => $client->latest?->client_ip),
            'paths' => collect(),
            default => $this->requests->getCollection()->pluck('client_ip'),
        };

        return app(MeasureIpNeighborhoods::class)->handle($this->team, array_values(array_intersect($shown->filter()->unique()->all(), $this->enrichedIps)));
    }

    /**
     * The busiest prefix among the page's enriched IPs: the top of every row's bars, so rows compare.
     */
    #[Computed]
    public function neighborhoodScale(): int
    {
        return (int) max([0, ...array_map(fn (IpNeighborhood $neighborhood) => $neighborhood->count8, $this->neighborhoods)]);
    }

    #[Computed]
    public function domains(): Collection
    {
        return $this->team->domains()->orderBy('hostname')->get(['id', 'hostname']);
    }

    /**
     * Show the chart, totals and tab counts: asked for by the chart's placeholder once the page is up.
     */
    public function loadCounts(): void
    {
        $this->countedSearch = $this->countSignature();
    }

    /**
     * Whether the counts are shown: once loaded for this search, and always for the client and path
     * lists, which page by them.
     */
    public function countsShown(): bool
    {
        return $this->view !== 'requests' || $this->countedSearch === $this->countSignature();
    }

    /**
     * What the counts depend on: the search (filters and hidden noise are clauses in it), the period,
     * the client filter and whether your own traffic is hidden. A picked stretch of the chart and the
     * bar size aren't in it: they don't move the chart's counts away.
     */
    public function countSignature(): string
    {
        return md5(json_encode([$this->search, $this->period, $this->client, $this->hide]));
    }

    /**
     * Count before rendering, so a period too big to count in time shows as that, not as a timed-out
     * page: the chart's place says so, and the list still shows.
     */
    public function rendering(): void
    {
        if (! $this->countsShown() || $this->view !== 'requests') {
            return;
        }

        try {
            $this->totals;
            $this->chartBuckets;
            $this->clientCount;
        } catch (QueryException $exception) {
            if (! WholePeriodQuery::timedOut($exception)) {
                throw $exception;
            }

            unset($this->totals, $this->chartBuckets, $this->clientCount, $this->requests);
            $this->countedSearch = null;
            $this->tooBigSearch = $this->countSignature();
        }
    }

    /**
     * The page of requests: paged by the total the totals counted once the counts are shown, and
     * simply (previous / next) before, so the list never waits on them.
     */
    #[Computed]
    public function requests(): LengthAwarePaginator|Paginator
    {
        $query = $this->filteredQuery()
            ->select('edge_requests.*')
            ->with('domain:id,hostname')
            ->with('clientHintRecord')
            ->latest('occurred_at')
            ->latest('id')
            ->getQuery();

        // Paged on the relation's builder, which takes the total the totals already counted.
        return $this->countsShown()
            ? $query->paginate($this->perPage, total: $this->totals['requests'])
            : $query->simplePaginate($this->perPage);
    }

    /**
     * The search's counts, found in one pass over its requests rather than a query each: how many
     * requests, to how many websites, for how many distinct paths, and how many of them were suspicious
     * or came from clients that aren't the browser they claim to be.
     *
     * @return array{requests: int, websites: int, paths: int, suspicious: int, spoofed: int}
     */
    #[Computed]
    public function totals(): array
    {
        $suspicious = WhereCondition::of(EdgeRequest::query()->whereIn('edge_requests.action', RequestAction::suspicious()));
        $spoofed = WhereCondition::of(ClientTrait::constrainAny(EdgeRequest::query(), ClientTrait::spoofed(), $this->browserReleases));

        $query = $this->filteredQuery()->toBase()
            ->selectRaw(implode(', ', [
                'count(*) as requests',
                'count(distinct edge_requests.domain_id) as websites',
                'count(distinct edge_requests.path_id) as paths',
                $suspicious->countAs('suspicious'),
                $spoofed->countAs('spoofed'),
            ]), [...$suspicious->bindings, ...$spoofed->bindings]);

        $row = $this->rememberCounts('totals', $query->toRawSql(), fn (): array => (array) $query->first());

        return array_map(fn (string $count) => (int) ($row[$count] ?? 0), [
            'requests' => 'requests',
            'websites' => 'websites',
            'paths' => 'paths',
            'suspicious' => 'suspicious',
            'spoofed' => 'spoofed',
        ]);
    }

    #[Computed]
    public function suspiciousCount(): int
    {
        return $this->totals['suspicious'];
    }

    /**
     * How many of the requests came from clients that aren't the browser they claim to be.
     */
    #[Computed]
    public function spoofedCount(): int
    {
        return $this->totals['spoofed'];
    }

    /**
     * The Chrome and Firefox versions people run now, which "outdated" is measured against.
     */
    #[Computed]
    public function browserReleases(): BrowserReleases
    {
        $firefox = app(FindCurrentFirefoxReleases::class)->handle();

        return new BrowserReleases(app(FindCurrentChromeVersion::class)->handle(), $firefox['current'], $firefox['esr']);
    }

    #[Computed]
    public function inspectedRequest(): ?EdgeRequest
    {
        return $this->inspectedRequestId
            ? $this->team->edgeRequests()->with('domain')->find($this->inspectedRequestId)
            : null;
    }

    /**
     * The tags and bans the inspected request's client holds right now: verdicts on its IP, its TLS
     * fingerprint, or both.
     *
     * @return Collection<int, Verdict>
     */
    #[Computed]
    public function inspectedVerdicts(): Collection
    {
        $request = $this->inspectedRequest;

        if ($request === null) {
            return new Collection;
        }

        return $this->team->verdicts()
            ->active()
            ->with('rule:id,name')
            ->where(fn (Builder $query) => $query->whereNotNull('client_ip')->orWhereNotNull('ja4_id'))
            ->where(fn (Builder $query) => $query->whereNull('client_ip')->orWhere('client_ip', $request->client_ip))
            ->where(fn (Builder $query) => $request->ja4_id === null ? $query->whereNull('ja4_id') : $query->whereNull('ja4_id')->orWhere('ja4_id', $request->ja4_id))
            ->latest('last_matched_at')
            ->get();
    }

    /**
     * Whether the user may name clients (and so mark one as their own traffic).
     */
    #[Computed]
    public function canLabelClients(): bool
    {
        return Gate::allows('create', [ClientLabel::class, $this->team]);
    }

    #[Computed]
    public function canManageRules(): bool
    {
        return Gate::allows('create', [EdgeRule::class, $this->team]);
    }

    /**
     * The gym visit a diverted request started (or resumed): the same visitor on the same domain,
     * whose session began shortly before the request was logged.
     */
    #[Computed]
    public function inspectedGymSession(): ?GymSession
    {
        $request = $this->inspectedRequest;

        return $request === null ? null : ($this->gymSessionsFor(collect([$request]))[$request->id] ?? null);
    }

    /**
     * The gym visit each diverted request on the page started or resumed, by request id, so a row
     * can say how it ended.
     *
     * @return array<int, GymSession>
     */
    #[Computed]
    public function gymSessionsByRequest(): array
    {
        return $this->gymSessionsFor($this->requests->getCollection());
    }

    /**
     * Match diverted requests to their gym visits in one query: the same visitor on the same domain,
     * whose session began within the idle limit before the request was logged (or just after, as a
     * background request's reload starts it), the latest such.
     *
     * @param  SupportCollection<int, EdgeRequest>  $requests
     * @return array<int, GymSession>
     */
    protected function gymSessionsFor(SupportCollection $requests): array
    {
        $diverted = $requests->filter(fn (EdgeRequest $request) => $request->action === RequestAction::Diverted);

        if ($diverted->isEmpty()) {
            return [];
        }

        $idleMinutes = (int) config('gym.idle_minutes');
        // Looked up by visitor, so a page of a thousand requests doesn't scan every session for each.
        $sessionsByVisitor = $this->team->gymSessions()
            ->real()
            ->whereIn('domain_id', $diverted->pluck('domain_id')->unique()->values())
            ->whereIn('client_ip', $diverted->pluck('client_ip')->unique()->values())
            ->whereBetween('started_at', [$diverted->min('occurred_at')->subMinutes($idleMinutes), $diverted->max('occurred_at')->addSeconds(10)])
            ->latest('started_at')
            ->get()
            ->groupBy(fn (GymSession $session) => $session->domain_id.'|'.$session->client_ip);

        $matched = $diverted
            ->mapWithKeys(fn (EdgeRequest $request) => [$request->id => $sessionsByVisitor->get($request->domain_id.'|'.$request->client_ip)?->first(
                fn (GymSession $session) => $session->started_at->between($request->occurred_at->subMinutes($idleMinutes), $request->occurred_at->addSeconds(10)),
            )])
            ->filter();

        // Only the sessions a row shows need their routines.
        Collection::make($matched->unique('id')->values())->load('routines:id,name');

        return $matched->all();
    }

    #[Computed]
    public function hasFilters(): bool
    {
        $search = (new RequestQuery($this->search))->without($this->noiseClauses());

        return $this->period !== '24h' || $this->range !== '' || $this->client !== '' || $search->clauses() !== [] || $search->tail() !== '';
    }

    /**
     * The stretch of the chart the list is narrowed to, start and end (left out), or null for the whole
     * period. A stretch that's malformed, backwards, or wholly before the period counts as none.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    #[Computed]
    public function selectedRange(): ?array
    {
        if (preg_match('/^(\d{1,12})-(\d{1,12})$/', $this->range, $bounds) !== 1 || (int) $bounds[1] >= (int) $bounds[2]) {
            return null;
        }

        $since = (TrafficPeriod::tryFrom($this->period) ?? TrafficPeriod::Day)->start();
        [$start, $end] = [CarbonImmutable::createFromTimestamp((int) $bounds[1]), CarbonImmutable::createFromTimestamp((int) $bounds[2])];

        return $end->lessThanOrEqualTo($since) ? null : [$start, $end];
    }

    /**
     * Say the picked stretch the way the chart labels its bars, e.g. "17:00–18:00" or "Oct 12–Oct 14".
     */
    #[Computed]
    public function rangeLabel(): ?string
    {
        if (($range = $this->selectedRange) === null) {
            return null;
        }

        [$start, $end] = $range;
        $period = TrafficPeriod::tryFrom($this->period) ?? TrafficPeriod::Day;
        $format = ChartInterval::resolve($this->chartInterval, $period)->labelFormat($period);

        // Whole days read by the days they cover, so the last one is the day before the end.
        if ($format === 'M j') {
            $last = $end->subSecond();

            return $start->isSameDay($last) ? $start->format($format) : $start->format($format).'–'.$last->format($format);
        }

        return $start->format($format).'–'.$end->format($format);
    }

    /**
     * Whether the user connected an Anthropic key, so questions in plain English become searches.
     */
    #[Computed]
    public function hasAnthropicKey(): bool
    {
        return Auth::user()->aiCredentials()->where('provider', AiProvider::Anthropic)->exists();
    }

    /**
     * The search, parsed: its WHERE expression, the subjects that make a client and its HAVING, what it
     * reads as, or the syntax error that stops it. HAVING counts over the WHERE without the noise the
     * Hide toggles leave out, since hiding GET / from the list shouldn't make every client one that
     * never visited the homepage.
     *
     * @return array{where: ?array<string, mixed>, subjects: list<RuleSubject>, having: ?array<string, mixed>, counted: ?array<string, mixed>, readsAs: ?string, error: ?array{message: string, line: int, column: int}}
     */
    #[Computed]
    public function parsedSearch(): array
    {
        $search = new RequestQuery($this->search);

        try {
            $parsed = $search->parse();

            return [
                'where' => $parsed['where'],
                'subjects' => $search->subjects(),
                'having' => $parsed['having'],
                'counted' => $parsed['having'] === null ? null : $search->without($this->noiseClauses())->where(),
                'readsAs' => $search->isBlank() ? null : $search->describe(),
                'error' => null,
            ];
        } catch (RuleScriptSyntaxError $error) {
            return ['where' => null, 'subjects' => [RuleSubject::Ip, RuleSubject::Ja4], 'having' => null, 'counted' => null, 'readsAs' => null, 'error' => ['message' => $error->getMessage(), 'line' => $error->scriptLine, 'column' => $error->scriptColumn]];
        }
    }

    /**
     * The clients the search finds, busiest first: one row per client as the search groups them, with
     * how many of its requests match, on how many sites, and its latest request to show it by.
     */
    #[Computed]
    public function clients(): LengthAwarePaginator
    {
        $clients = $this->clientGroups()
            ->selectRaw(implode(', ', [
                'count(*) as requests',
                'count(distinct edge_requests.domain_id) as hosts',
                'count(distinct edge_requests.client_ip) as ips',
                'count(distinct edge_requests.ja4_id) as fingerprints',
                'min(edge_requests.occurred_at) as first_seen',
                'max(edge_requests.occurred_at) as last_seen',
                'max(edge_requests.id) as last_request_id',
            ]))
            ->orderByDesc('requests')
            ->orderByDesc('last_request_id')
            ->paginate($this->perPage);

        $latest = EdgeRequest::query()->with(['domain:id,hostname', 'clientHintRecord'])->findMany($clients->pluck('last_request_id'))->keyBy('id');

        $clients->getCollection()->each(function (object $client) use ($latest) {
            $client->latest = $latest->get($client->last_request_id);
        });

        return $clients;
    }

    /**
     * The distinct paths the search finds, busiest first: one row per path, with how many of its requests
     * match, on how many sites, from how many IPs, and its latest request to show it by.
     */
    #[Computed]
    public function paths(): LengthAwarePaginator
    {
        $paths = $this->pathGroups()->paginate($this->perPage, total: $this->totals['paths']);

        $latest = EdgeRequest::query()->with(['domain:id,hostname', 'requestPath'])->findMany($paths->pluck('last_request_id'))->keyBy('id');

        $paths->getCollection()->each(function (object $path) use ($latest) {
            $path->latest = $latest->get($path->last_request_id);
        });

        return $paths;
    }

    /**
     * How many distinct paths the search finds, whichever view lists them.
     */
    #[Computed]
    public function pathCount(): int
    {
        return $this->totals['paths'];
    }

    /**
     * The search's requests grouped by path, busiest first, with their counts.
     */
    protected function pathGroups(): QueryBuilder
    {
        return $this->filteredQuery()->toBase()
            ->whereNotNull('edge_requests.path_id')
            ->select('edge_requests.path_id')
            ->selectRaw(implode(', ', [
                'count(*) as requests',
                'count(distinct edge_requests.domain_id) as hosts',
                'count(distinct edge_requests.client_ip) as ips',
                'min(edge_requests.occurred_at) as first_seen',
                'max(edge_requests.occurred_at) as last_seen',
                'max(edge_requests.id) as last_request_id',
            ]))
            ->groupBy('edge_requests.path_id')
            ->orderByDesc('requests')
            ->orderByDesc('last_request_id');
    }

    /**
     * The search's requests over the period in bars of the chosen interval, for the chart above the list:
     * suspicious, and those that passed split by what their clients gave away. Each request counts once,
     * at its worst (spoofed before outdated), so a bar's stacks add up to it: humans is every request that
     * passed, spoofed and outdated the parts of it.
     *
     * @return array<int, array{key: string, label: string, start: int, end: int, humans: int, suspicious: int, notFound: int, spoofed: int, outdated: int}>
     */
    #[Computed]
    public function chartBuckets(): array
    {
        $period = TrafficPeriod::tryFrom($this->period) ?? TrafficPeriod::Day;
        $passedFrom = fn (array $traits) => WhereCondition::of(ClientTrait::constrainAny(EdgeRequest::query()->where('edge_requests.action', RequestAction::Passed), $traits, $this->browserReleases));

        $query = $this->filteredQuery(withinRange: false);
        $interval = ChartInterval::resolve($this->chartInterval, $period);

        // Counted in the same pass as the bars, rather than a query each.
        $buckets = $this->rememberCounts('chart:'.$period->value.':'.$interval->value.':'.intdiv(now()->getTimestamp(), $interval->seconds()), $query->toRawSql(), fn (): array => app(SummarizeTraffic::class)->bucketsBy(
            $query,
            $period,
            $interval,
            CarbonImmutable::now(),
            counted: [
                'spoofed' => $passedFrom(ClientTrait::spoofed()),
                'flagged' => $passedFrom([...ClientTrait::spoofed(), ...ClientTrait::forFilter('outdated')]),
            ],
        ));

        return array_map(fn (array $bucket) => [
            ...Arr::except($bucket, 'flagged'),
            'outdated' => max(0, $bucket['flagged'] - $bucket['spoofed']),
        ], $buckets);
    }

    /**
     * What packages mark each request on the page with (App\Support\RequestAddons), by row.
     *
     * @return array<int, \Illuminate\Support\HtmlString>
     */
    #[Computed]
    public function requestMarks(): array
    {
        return $this->view === 'requests'
            ? app(RequestAddons::class)->marks($this->team, $this->requests->getCollection()->pluck('id')->map(fn (mixed $id) => (int) $id)->all())
            : [];
    }

    /**
     * What packages add to the client cell of each row on the page (App\Support\ClientAddons), by row.
     *
     * @return array<int, \Illuminate\Support\HtmlString>
     */
    #[Computed]
    public function clientAddons(): array
    {
        $rows = match ($this->view) {
            'clients' => $this->clients->getCollection(),
            'paths' => collect(),
            default => $this->requests->getCollection(),
        };

        return app(ClientAddons::class)->render($this->team, $rows->values()->map(fn (object $row) => [
            'ip' => $row->client_ip ?? null,
            'ja4' => isset($row->ja4_id) ? (int) $row->ja4_id : null,
        ])->all());
    }

    /**
     * How many clients the search finds, as it groups them, whichever view lists them.
     */
    #[Computed]
    public function clientCount(): int
    {
        if ($this->view === 'clients') {
            return $this->clients->total();
        }

        $clientGroups = $this->clientGroups();

        return $this->rememberCounts('clients', $clientGroups->toRawSql(), fn (): int => DB::query()->fromSub($clientGroups, 'client_groups')->count());
    }

    /**
     * Keep one of the search's counts (its totals, chart or clients) for half a minute (five for periods
     * over a day, whose counts take far longer and move far less), keyed by the SQL
     * that counts it: the period starts on a bucket boundary, so the same search asks the same SQL
     * until the next bucket, and paging, opening a request or saving the search reuse the counts.
     * Live tail always counts afresh. Only plain values go in: the cache unserializes no classes.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $count
     * @return TValue
     */
    protected function rememberCounts(string $name, string $sql, Closure $count): mixed
    {
        // All of a request's counting shares one budget, so together they stop before the gateway does.
        $this->countingDeadline ??= microtime(true) + self::COUNTING_SECONDS;
        $counted = fn () => WholePeriodQuery::run($count, timeoutSeconds: $this->countingDeadline - microtime(true));

        if ($this->live) {
            return $counted();
        }

        $period = TrafficPeriod::tryFrom($this->period) ?? TrafficPeriod::Day;

        return Cache::remember("activity:{$name}:{$this->team->id}:".md5($sql), $period->seconds() > 86_400 ? $period->cacheSeconds()[0] : 30, $counted);
    }

    /**
     * The search's requests grouped into clients, one row per client as the search groups them (by IP
     * and fingerprint unless it says GROUP BY), with the subject columns selected.
     */
    protected function clientGroups(): QueryBuilder
    {
        $subjects = $this->parsedSearch['subjects'];
        $columns = array_map(fn (RuleSubject $subject) => $subject->column(), $subjects);

        return $this->filteredQuery()->toBase()
            ->when($subjects === [RuleSubject::Ja4], fn ($query) => $query->whereNotNull('edge_requests.ja4_id'))
            ->select($columns)
            ->groupBy($columns);
    }

    /**
     * How many requests the search finds, whichever view lists them.
     */
    #[Computed]
    public function requestCount(): int
    {
        return $this->totals['requests'];
    }

    /**
     * How many websites the search's requests went to.
     */
    #[Computed]
    public function websiteCount(): int
    {
        return $this->totals['websites'];
    }

    /**
     * A link to the Rules editor, starting a rule that tags each client this search finds, or null when
     * the search can't become one. The Hide toggles only shape this view, so the rule leaves them out.
     */
    #[Computed]
    public function ruleDraftUrl(): ?string
    {
        $parsed = $this->parsedSearch;
        $search = (new RequestQuery($this->search))->without($this->noiseClauses());

        if (! $this->canManageRules || $parsed['error'] !== null || ($search->clauses() === [] && $parsed['having'] === null)) {
            return null;
        }

        $period = TrafficPeriod::tryFrom($this->period) ?? TrafficPeriod::Day;
        $question = trim($this->question);
        $comments = array_values(array_filter([
            $question !== '' ? __('From the Activity search “:question”.', ['question' => Str::limit($question, 120)]) : __('From an Activity search.'),
            $this->client !== '' ? __('The search also kept only :clients, which a rule can’t test.', ['clients' => $this->client === 'spoofed' ? __('spoofed clients') : __('outdated browsers')]) : null,
            $parsed['having'] !== null && $period->seconds() > RuleScriptParser::MAX_WINDOW_SECONDS ? __('A rule counts over at most a day; the search counted over the :period.', ['period' => Str::lower($period->label())]) : null,
            str_contains(json_encode($parsed['where']) ?: '', '"kind":"text"') ? __('Rules can’t match free text in quotes; name the field it’s in (path, ua, network…).') : null,
        ]));

        return route('rules.index', array_filter([
            'draft' => $search->toRule(Str::slug(Str::limit($question !== '' ? $question : 'activity search', 48, '')) ?: 'activity-search', $period->seconds(), $comments),
            'name' => $question !== '' ? Str::limit($question, 80, '') : null,
        ]));
    }

    /**
     * The clients the search's HAVING keeps, found once so the list and each of its counts don't group
     * every request again. Null when there's no HAVING, or more clients than are worth listing out.
     *
     * @return list<string>|null
     */
    #[Computed]
    public function havingClientKeys(): ?array
    {
        if ($this->parsedSearch['having'] === null) {
            return null;
        }

        $keys = $this->havingClients()
            ->selectRaw(BuildRuleQuery::clientKey($this->parsedSearch['subjects']).' as client_key')
            ->limit(self::MAX_LISTED_CLIENTS + 1)
            ->pluck('client_key')
            ->all();

        return count($keys) > self::MAX_LISTED_CLIENTS ? null : $keys;
    }

    /**
     * Start the query for the clients the search's HAVING keeps, over the period.
     */
    protected function havingClients(): QueryBuilder
    {
        $parsed = $this->parsedSearch;

        return app(BuildRuleQuery::class)
            ->clients($this->team, $parsed['counted'], $parsed['subjects'], $parsed['having'])
            ->where('edge_requests.occurred_at', '>=', (TrafficPeriod::tryFrom($this->period) ?? TrafficPeriod::Day)->start());
    }

    /**
     * The domain the page is filtered to, which is all a live tail needs from the edge.
     */
    protected function filteredDomainId(): ?int
    {
        return $this->domain === '' ? null : $this->domains->firstWhere('hostname', $this->domain)?->id;
    }

    /**
     * The search's requests over the period, narrowed to the stretch picked on the chart unless the
     * chart itself is asking: it shows the whole period.
     *
     * @return HasManyThrough<EdgeRequest, Domain, Team>
     */
    protected function filteredQuery(bool $withinRange = true): HasManyThrough
    {
        $since = (TrafficPeriod::tryFrom($this->period) ?? TrafficPeriod::Day)->start();
        $parsed = $this->parsedSearch;
        $range = $withinRange ? $this->selectedRange : null;

        return $this->team->edgeRequests()
            ->where('occurred_at', '>=', $since)
            ->when($range, fn (Builder $query, array $range) => $query->where('occurred_at', '>=', $range[0])->where('occurred_at', '<', $range[1]))
            // A search that doesn't parse matches nothing, rather than everything.
            ->when($parsed['error'] !== null, fn (Builder $query) => $query->whereRaw('1 = 0'))
            // Searched in place: matching ids in a subquery gathered the whole period before narrowing it.
            ->when($parsed['where'], function (Builder $query, array $where) {
                app(BuildRuleQuery::class)->constrain($query->getQuery(), $this->team, $where);
            })
            // HAVING keeps the requests of the clients whose (unhidden) requests pass it.
            ->when($parsed['having'] !== null, fn (Builder $query) => $query->whereIn(
                DB::raw(BuildRuleQuery::clientKey($parsed['subjects'])),
                $this->havingClientKeys ?? $this->havingClients()->selectRaw(BuildRuleQuery::clientKey($parsed['subjects'])),
            ))
            ->when($this->hide === ActivityNoise::Mine->value, fn (Builder $query) => ActivityNoise::Mine->exclude($query, $this->team->id))
            ->when(ClientTrait::forFilter($this->client), fn (Builder $query, array $traits) => ClientTrait::constrainAny($query, $traits, $this->browserReleases));
    }
}; ?>

<div>
    <x-ntb.page-heading
        :overline="__('Every request, every verdict')"
        :title="__('Activity')"
        :description="__('What reached the edge, what it asked for, and what NiceTryBot did about it.')"
    >
        <x-slot:actions>
            @if ($live && $this->liveTail)
                <div
                    wire:poll.2s="heartbeat"
                    x-data="{ ends: {{ $this->liveTail->expires_at->getTimestampMs() }}, left: '', timer: null, init() { this.tick(); this.timer = setInterval(() => this.tick(), 1000) }, destroy() { clearInterval(this.timer) }, tick() { const seconds = Math.max(0, Math.round((this.ends - Date.now()) / 1000)); this.left = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0') } }"
                    class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300"
                    data-test="live-tail-status"
                >
                    <span class="relative flex size-2">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span>
                    </span>
                    {{ $this->liveTailConnected ? __('Live') : __('Connecting to the edge…') }}
                    <span class="tabular-nums text-zinc-400" x-text="left" title="{{ __('Time left. The live tail ends on its own.') }}"></span>
                </div>
            @endif
            {{-- Its own island: saving, removing or naming a search re-renders just this, not the page's chart and tables. --}}
            @island(name: 'saved-searches', always: true)
            @php($savedSearches = $this->savedSearches)
            @php($currentSavedSearch = $this->currentSavedSearch)
            <div
                x-data="{ open: false, filter: '' }"
                x-on:keydown.escape.window="open = false"
                x-on:click.outside="open = false"
                class="relative"
                data-test="saved-searches"
            >
                <flux:button size="sm" icon="bookmark" icon:trailing="chevron-down" x-on:click="open = ! open; $nextTick(() => $refs.filter?.focus())" x-bind:aria-expanded="open">
                    {{ $currentSavedSearch ? Str::limit($currentSavedSearch->name, 32) : __('Saved searches') }}
                    @if ($savedSearches->isNotEmpty() && ! $currentSavedSearch)
                        <span class="ml-0.5 rounded-full bg-zinc-100 px-1.5 text-xs tabular-nums text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">{{ $savedSearches->count() }}</span>
                    @endif
                </flux:button>

                <div
                    x-show="open"
                    x-transition.origin.top.right
                    x-cloak
                    class="absolute right-0 z-30 mt-2 w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-line bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
                >
                    @if ($savedSearches->count() > 6)
                        <div class="border-b border-line p-2 dark:border-zinc-800">
                            <flux:input x-ref="filter" x-model="filter" size="sm" icon="magnifying-glass" :placeholder="__('Find a saved search')" :aria-label="__('Find a saved search')" />
                        </div>
                    @endif

                    <ul class="max-h-96 overflow-y-auto py-1" data-test="saved-search-list">
                        @forelse ($savedSearches as $savedSearch)
                            @php($savedPeriod = TrafficPeriod::tryFrom((string) ($savedSearch->parameters['period'] ?? '24h')))
                            <li
                                wire:key="saved-search-{{ $savedSearch->id }}"
                                x-show="filter === '' || @js(Str::lower($savedSearch->name.' '.($savedSearch->parameters['ask'] ?? '').' '.($savedSearch->parameters['q'] ?? ''))).includes(filter.toLowerCase())"
                                class="group flex items-start gap-1 px-1"
                            >
                                <a
                                    href="{{ $savedSearch->url() }}"
                                    wire:navigate
                                    @class([
                                        'min-w-0 flex-1 rounded-lg px-2.5 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-800',
                                        'bg-coral-50 dark:bg-coral-500/10' => $currentSavedSearch?->is($savedSearch),
                                    ])
                                    data-test="saved-search-link"
                                >
                                    <span class="block truncate text-sm font-medium text-ink dark:text-white">{{ $savedSearch->name }}</span>
                                    <span class="mt-0.5 block truncate text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $savedPeriod?->label() }}@if (($savedSearch->parameters['q'] ?? '') !== '') · <span class="font-mono">{{ $savedSearch->parameters['q'] ?? '' }}</span>@endif
                                    </span>
                                </a>
                                @can('delete', $savedSearch)
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="trash"
                                        class="mt-1.5 opacity-0 group-hover:opacity-100 focus:opacity-100"
                                        wire:click="deleteSavedSearch({{ $savedSearch->id }})"
                                        wire:confirm="{{ __('Remove “:name” for everyone on the team?', ['name' => $savedSearch->name]) }}"
                                        :aria-label="__('Remove :name', ['name' => $savedSearch->name])"
                                    />
                                @endcan
                            </li>
                        @empty
                            <li class="px-3.5 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Nothing saved yet. Save a search to come back to it in one click, and share it with your team.') }}</li>
                        @endforelse
                    </ul>

                    <div class="border-t border-line p-2 dark:border-zinc-800">
                        <flux:button size="sm" variant="primary" icon="bookmark" class="w-full" x-on:click="open = false" wire:click="startSavingSearch" data-test="save-search">
                            {{ $currentSavedSearch ? __('Rename or save again') : __('Save this search') }}
                        </flux:button>
                    </div>
                </div>
            </div>
            <flux:modal name="save-search" class="md:w-md">
                <form wire:submit="saveSearch" class="space-y-5">
                    <div>
                        <flux:heading size="lg">{{ __('Save this search') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('The search, its question and its filters are saved for everyone on the team. Saving under a name that’s taken replaces that search.') }}</flux:text>
                    </div>

                    <flux:input wire:model="savedSearchName" :label="__('Name')" :placeholder="__('Vesync public forms')" autofocus data-test="saved-search-name" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
            @endisland
            <flux:button size="sm" icon="signal" :variant="$live ? 'primary' : 'outline'" wire:click="toggleLive" data-test="live-tail">{{ $live ? __('Stop live tail') : __('Live tail') }}</flux:button>
            <flux:button size="sm" icon="arrow-down-tray" wire:click="export" data-test="export-activity">{{ __('Export CSV') }}</flux:button>
        </x-slot:actions>
    </x-ntb.page-heading>

    <x-ntb.panel :padded="false">
        @php($parsedSearch = $this->parsedSearch)
        <div class="space-y-3 border-b border-line p-4 dark:border-zinc-800">
            {{--
                Ask in plain English and Claude writes the WHERE, or write the WHERE in the rule language. Either
                way the search is the WHERE, and the filters below switch its clauses on and off.
            --}}
            @php($hasAnthropicKey = $this->hasAnthropicKey)
            @php($ownClauses = (new RequestQuery($search))->without($this->noiseClauses())->clauses())
            <div
                x-data="{
                    mode: '{{ session('activity.search_mode') === 'where' ? 'where' : 'ask' }}',
                    privacy: document.documentElement.classList.contains('privacy-mode'),
                    fields: @js(app(RuleFields::class)->names()),
                    use(mode) {
                        this.mode = mode;
                        $wire.rememberSearchMode(mode);
                        this.$nextTick(() => document.querySelector(mode === 'ask' ? '[data-test=activity-question]' : '[data-test=activity-search]')?.focus());
                    },
                }"
                x-on:privacy-mode-changed.window="privacy = $event.detail.enabled"
                data-test="activity-search-box"
            >
                {{-- Redacted for screen sharing (privacy mode), the box keeps just the question: no WHERE, no reading of it, no filters. --}}
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2" data-privacy-hide>
                    <div class="inline-flex rounded-lg border border-line bg-canvas p-0.5 text-xs font-medium dark:border-zinc-700 dark:bg-zinc-900" role="tablist" aria-label="{{ __('Search with') }}">
                        <button
                            type="button"
                            role="tab"
                            x-on:click="use('ask')"
                            x-bind:aria-selected="mode === 'ask'"
                            x-bind:class="mode === 'ask' ? 'bg-white text-ink shadow-xs dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white'"
                            class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 transition"
                            title="{{ __('Ask in plain English') }}"
                            data-test="search-mode-ask"
                        >
                            <flux:icon.sparkles variant="micro" class="size-3.5 text-coral-500" />{{ __('Ask') }}
                        </button>
                        <button
                            type="button"
                            role="tab"
                            x-on:click="use('where')"
                            x-bind:aria-selected="mode === 'where'"
                            x-bind:class="mode === 'where' ? 'bg-white text-ink shadow-xs dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white'"
                            class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 font-mono transition"
                            title="{{ __('Write it in the rule language') }}"
                            data-test="search-mode-where"
                        >
                            <flux:icon.code-bracket variant="micro" class="size-3.5 text-sky-500" />WHERE
                        </button>
                    </div>

                    <p class="text-xs text-zinc-500 dark:text-zinc-400" x-show="mode === 'ask'">
                        @if ($hasAnthropicKey)
                            <span class="inline-flex items-center gap-1" title="{{ __('Your question, the current WHERE and your hostnames go to Anthropic with your key. Request records don’t.') }}">{{ __('Claude writes the WHERE, and you can edit it. Your question goes to Anthropic.') }}</span>
                        @else
                            <a href="{{ route('ai.edit') }}" wire:navigate class="inline-flex items-center gap-1 hover:text-ink dark:hover:text-white" data-test="connect-claude">
                                {{ __('Connect Claude to ask in plain English') }}<flux:icon.arrow-right variant="micro" class="size-3" />
                            </a>
                        @endif
                    </p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400" x-show="mode === 'where'" x-cloak>{{ __('Write it like a rule\'s WHERE, or pick filters below to build it.') }}</p>
                </div>

                {{-- Ask: a question in plain English, then the WHERE it became. --}}
                <div x-show="mode === 'ask' || privacy" @if (session('activity.search_mode') === 'where') x-cloak @endif>
                    <form x-on:submit.prevent="$wire.ask().then(() => $wire.$refresh())" class="flex items-center gap-2 rounded-xl border border-line bg-white py-1 ps-3 pe-1 shadow-xs transition focus-within:border-coral-400 focus-within:ring-4 focus-within:ring-coral-500/10 dark:border-zinc-700 dark:bg-zinc-900 dark:focus-within:border-coral-400/70" data-test="ask-form">
                        <span class="shrink-0 text-coral-500" wire:loading.class="animate-pulse" wire:target="ask">
                            <flux:icon.sparkles class="size-5" />
                        </span>
                        <input
                            type="text"
                            wire:model="question"
                            x-on:keydown.escape="$el.blur()"
                            autocomplete="off"
                            spellcheck="false"
                            enterkeyhint="search"
                            placeholder="{{ $hasAnthropicKey ? __('Ask about your traffic, like “404s from hosting networks this week”') : __('Search paths, IPs, user agents and networks') }}"
                            class="min-w-0 flex-1 bg-transparent py-1.5 text-[15px] text-ink outline-none placeholder:text-zinc-400 dark:text-white dark:placeholder:text-zinc-500"
                            aria-label="{{ __('Ask about your traffic') }}"
                            data-test="activity-question"
                        />
                        @if ($question !== '' || $ownClauses !== [])
                            <button type="button" wire:click="clearQuestion" class="shrink-0 rounded p-1 text-zinc-400 hover:text-ink dark:hover:text-white" aria-label="{{ __('Clear the search') }}" data-test="clear-question">
                                <flux:icon.x-mark variant="micro" />
                            </button>
                        @endif
                        <flux:button type="submit" size="sm" variant="primary" class="shrink-0" data-test="ask-button">
                            <span wire:loading.remove wire:target="ask">{{ __('Search') }}</span>
                            <span wire:loading wire:target="ask">{{ $hasAnthropicKey ? __('Thinking…') : __('Searching…') }}</span>
                        </flux:button>
                    </form>

                    <div wire:loading.flex wire:target="ask" class="mt-2 hidden items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                        <span class="h-1.5 w-24 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800"><span class="block h-full w-1/2 animate-pulse rounded-full bg-coral-400"></span></span>
                        {{ $hasAnthropicKey ? __('Claude is writing the WHERE…') : __('Searching…') }}
                    </div>

                    <div class="mt-2 space-y-2" wire:loading.remove wire:target="ask">
                        @if ($askError)
                            <p class="flex gap-2 text-sm text-rose-700 dark:text-rose-300" data-test="ask-error">
                                <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0" />
                                <span>{{ $askError }}</span>
                            </p>
                        @endif

                        @if (trim($search) !== '')
                            {{-- The WHERE the search runs. Click it to edit it. --}}
                            <button
                                type="button"
                                x-on:click="use('where')"
                                class="group flex w-full items-start gap-3 rounded-lg border border-zinc-800 bg-zinc-950 px-3 py-1.5 text-start font-mono text-[12.5px] leading-6 transition hover:border-zinc-600" data-privacy-hide
                                title="{{ __('Edit as WHERE') }}"
                                data-test="ask-where"
                            >
                                <span class="shrink-0 font-semibold text-coral-400 select-none">WHERE</span>
                                <span class="min-w-0 flex-1 break-words text-zinc-100">
                                    <span class="whitespace-pre-wrap" wire:ignore x-html="highlightRule($wire.search.replace(/\s*\n\s*/g, ' '), fields)">{{ preg_replace('/\s*\n\s*/', ' ', $search) }}</span>
                                    @if ($client !== '')
                                        <span @class([
                                            'ms-1 inline-flex items-center rounded px-1.5 align-middle font-sans text-[11px] leading-5 font-medium',
                                            'bg-rose-500/20 text-rose-200' => $client === 'spoofed',
                                            'bg-amber-500/20 text-amber-200' => $client !== 'spoofed',
                                        ]) title="{{ __('A client filter, set with the Client picker below') }}" data-test="ask-client">{{ $client === 'spoofed' ? __('+ spoofed clients') : __('+ outdated browsers') }}</span>
                                    @endif
                                </span>
                                <span class="inline-flex shrink-0 items-center gap-1 font-sans text-xs leading-6 text-zinc-500 group-hover:text-zinc-200">
                                    <flux:icon.pencil-square variant="micro" class="size-3.5" />{{ __('Edit') }}
                                </span>
                            </button>
                        @endif

                        @if ($askNote)
                            <p class="flex gap-2 text-sm text-amber-800 dark:text-amber-200" data-test="ask-note">
                                <flux:icon.information-circle variant="mini" class="mt-0.5 size-4 shrink-0" />
                                <span>{{ $askNote }}</span>
                            </p>
                        @endif

                        @if ($question === '' && $ownClauses === [])
                            @if ($hasAnthropicKey)
                                <div class="flex flex-wrap items-center gap-1.5" data-test="ask-examples">
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Try') }}</span>
                                    @foreach ([__('404s from hosting networks'), __('Who looked for .env files this week?'), __('Blocked requests from outside the US'), __('Clients that aren\'t browsers'), __('Responses slower than 2 seconds')] as $example)
                                        <button
                                            type="button"
                                            x-on:click="$wire.question = @js($example); $wire.ask().then(() => $wire.$refresh())"
                                            class="rounded-full border border-line bg-white px-2.5 py-0.5 text-xs text-zinc-600 transition hover:border-coral-300 hover:text-ink dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-coral-500/50 dark:hover:text-white"
                                        >{{ $example }}</button>
                                    @endforeach
                                </div>
                            @else
                                <div class="flex items-start gap-3 rounded-lg border border-dashed border-coral-300 bg-coral-50/60 px-3 py-2.5 text-sm dark:border-coral-500/30 dark:bg-coral-500/5" data-test="ask-connect">
                                    <flux:icon.sparkles variant="mini" class="mt-0.5 size-4 shrink-0 text-coral-500" />
                                    <p class="text-zinc-700 dark:text-zinc-200">
                                        {{ __('Ask in plain English, like “404s from hosting networks this week”.') }}
                                        <a href="{{ route('ai.edit') }}" wire:navigate class="font-medium text-coral-700 underline underline-offset-2 dark:text-coral-300">{{ __('Add your Anthropic API key') }}</a>
                                        {{ __('and Claude writes the WHERE. Until then, words are matched in paths, IPs, user agents and networks.') }}
                                    </p>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>

                {{-- WHERE: the rule language, highlighted as it's typed. --}}
                <div x-data="ruleEditor(fields, 'search')">
                    <div x-show="mode === 'where' && ! privacy" @if (session('activity.search_mode') !== 'where') x-cloak @endif>
                        <div @class([
                            'flex overflow-hidden rounded-lg border bg-zinc-950 font-mono text-[13px] leading-6',
                            'border-rose-500/70' => $parsedSearch['error'],
                            'border-emerald-500/50' => $parsedSearch['where'],
                            'border-zinc-700' => ! $parsedSearch['error'] && ! $parsedSearch['where'],
                        ])>
                            <div class="shrink-0 border-e border-zinc-800 bg-zinc-900 px-3 py-1.5 font-semibold text-coral-400 select-none" aria-hidden="true">WHERE</div>
                            <div class="relative min-w-0 flex-1">
                                <pre x-ref="overlay" wire:ignore aria-hidden="true" class="pointer-events-none absolute inset-0 m-0 overflow-hidden px-3 py-1.5 font-mono text-[13px] leading-6 whitespace-pre-wrap break-words text-zinc-100" x-html="html"></pre>
                                <textarea
                                    x-ref="input"
                                    wire:model.live.debounce.400ms="search"
                                    x-on:input="sync()"
                                    x-on:scroll="scroll()"
                                    spellcheck="false"
                                    autocapitalize="off"
                                    rows="{{ max(1, substr_count($search, "\n") + 1) }}"
                                    placeholder="{{ __("status = 404 AND path LIKE '/wp-%', or just type a path, IP, name or network") }}"
                                    class="relative block w-full resize-none bg-transparent px-3 py-1.5 font-mono text-[13px] leading-6 whitespace-pre-wrap break-words text-zinc-100 caret-coral-400 outline-none selection:bg-coral-500/30 placeholder:text-zinc-500 [field-sizing:content]"
                                    x-bind:class="{ '!text-transparent': ready }"
                                    aria-label="{{ __('Search, as a WHERE clause') }}"
                                    data-test="activity-search"
                                >{{ $search }}</textarea>
                            </div>
                            @if ($search !== '')
                                <button type="button" wire:click="$set('search', '')" class="shrink-0 px-2 text-zinc-500 hover:text-zinc-200" aria-label="{{ __('Clear the search') }}">
                                    <flux:icon.x-mark variant="micro" />
                                </button>
                            @endif
                        </div>
                    </div>

                    <div class="mt-2 flex flex-wrap items-start justify-between gap-x-4 gap-y-1 text-sm" data-privacy-hide wire:loading.class="opacity-60" wire:target="search, ask">
                        @if ($parsedSearch['error'])
                            <p class="flex gap-2 text-rose-700 dark:text-rose-300" data-test="search-error">
                                <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0" />
                                <span><span class="font-mono text-xs">{{ __('Line :line, column :column', ['line' => $parsedSearch['error']['line'], 'column' => $parsedSearch['error']['column']]) }}</span> · {{ $parsedSearch['error']['message'] }}</span>
                            </p>
                        @elseif ($parsedSearch['readsAs'])
                            <p class="flex gap-2 text-zinc-700 dark:text-zinc-200" data-test="search-reads-as">
                                <flux:icon.chat-bubble-left-ellipsis variant="mini" class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                <span><span class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ __('Reads as') }}</span> {{ $parsedSearch['readsAs'] }}</span>
                            </p>
                        @else
                            <span></span>
                        @endif

                        @if ($hasAnthropicKey && trim($question) !== '' && ! (new RequestQuery($search))->has(RequestQuery::quote(trim($question))))
                            <button type="button" wire:click="searchWords" x-show="mode === 'ask'" class="text-xs text-zinc-500 underline decoration-dotted underline-offset-2 hover:text-ink dark:text-zinc-400 dark:hover:text-white" data-test="search-words">
                                {{ __('Not it? Search for “:words” as typed', ['words' => Str::limit(trim($question), 60)]) }}
                            </button>
                        @endif

                        <details class="group text-xs" x-show="mode === 'where'" @if (session('activity.search_mode') !== 'where') x-cloak @endif>
                            <summary class="cursor-pointer text-zinc-500 select-none hover:text-ink dark:text-zinc-400 dark:hover:text-white">{{ __('Fields') }}</summary>
                            <div class="mt-2 space-y-1.5">
                                @foreach (collect(app(RuleFields::class)->all())->groupBy(fn (RuleFieldDefinition $field) => $field->group()) as $group => $fields)
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="w-28 shrink-0 text-zinc-500 dark:text-zinc-400">{{ $group }}</span>
                                        @foreach ($fields as $field)
                                            <button type="button" x-on:click="insert(@js($field->value))" title="{{ $field->englishName() }}{{ $field->hint() ? ' · '.$field->hint() : '' }}" class="rounded border border-line px-1.5 py-0.5 font-mono text-sky-700 hover:border-sky-400 dark:border-zinc-700 dark:text-sky-300">{{ $field->value }}</button>
                                        @endforeach
                                    </div>
                                @endforeach
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="w-28 shrink-0 text-zinc-500 dark:text-zinc-400">{{ __('Clauses') }}</span>
                                    @foreach (['AND', 'OR', 'NOT', "LIKE '%…%'", 'IN (…)', 'IS NULL', "'any text'", 'GROUP BY ip', 'HAVING COUNT(*) > 10', "COUNT(*) FILTER (WHERE path = '/') = 0", 'COUNT(DISTINCT host)'] as $clause)
                                        <button type="button" x-on:click="insert(@js($clause))" class="rounded border border-line px-1.5 py-0.5 font-mono text-coral-700 hover:border-coral-400 dark:border-zinc-700 dark:text-coral-300">{{ $clause }}</button>
                                    @endforeach
                                </div>
                            </div>
                        </details>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:flex" data-privacy-hide>
                <x-ntb.domain-picker wire:model.live="domain" class="lg:w-44" />

                <flux:select wire:model.live="status" size="sm" class="lg:w-36" :aria-label="__('Status')">
                    <flux:select.option value="">{{ __('Any status') }}</flux:select.option>
                    <flux:select.option value="2xx">2xx</flux:select.option>
                    <flux:select.option value="3xx">3xx</flux:select.option>
                    <flux:select.option value="4xx">4xx</flux:select.option>
                    <flux:select.option value="404">{{ __('404 only') }}</flux:select.option>
                    <flux:select.option value="5xx">5xx</flux:select.option>
                </flux:select>

                <flux:select wire:model.live="action" size="sm" class="lg:w-40" :aria-label="__('Action')">
                    <flux:select.option value="">{{ __('All actions') }}</flux:select.option>
                    <flux:select.option value="suspicious">{{ __('Any suspicious') }}</flux:select.option>
                    <flux:select.option value="kept-off">{{ __('Kept off origin') }}</flux:select.option>
                    @foreach (RequestAction::cases() as $actionOption)
                        <flux:select.option :value="$actionOption->slug()">{{ $actionOption->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="client" size="sm" class="lg:w-40" :aria-label="__('Client')" data-test="client-filter">
                    <flux:select.option value="">{{ __('Any client') }}</flux:select.option>
                    <flux:select.option value="spoofed">{{ __('Spoofed clients') }}</flux:select.option>
                    <flux:select.option value="outdated">{{ __('Outdated browsers') }}</flux:select.option>
                </flux:select>

                <flux:select wire:model.live="period" size="sm" class="lg:w-36" :aria-label="__('Time period')">
                    @foreach (TrafficPeriod::cases() as $periodOption)
                        <flux:select.option :value="$periodOption->value">{{ $periodOption->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @php($countsShown = $this->countsShown())
        @if ($this->domains->isNotEmpty() && $parsedSearch['error'] === null && ! $countsShown && $tooBigSearch === $this->countSignature())
            <div class="relative border-b border-line px-4 py-6 text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400" data-test="activity-chart-too-big">
                <p class="flex items-center gap-2 font-medium text-ink dark:text-white"><flux:icon.clock variant="mini" class="size-4 text-zinc-400" />{{ __('Too many requests to count in time.') }}</p>
                <p class="mt-1">{{ __('The list below still shows them, newest first. Try a shorter period or narrow the search to see the chart and totals.') }}</p>
            </div>
        @elseif ($this->domains->isNotEmpty() && $parsedSearch['error'] === null && ! $countsShown)
            {{-- A skeleton of the chart while its counts load: same height, so nothing jumps when it lands. --}}
            <div wire:init="loadCounts" wire:key="activity-chart-pending-{{ $this->countSignature() }}" class="relative border-b border-line px-4 pt-4 pb-3 dark:border-zinc-800" aria-busy="true" data-test="activity-chart-pending">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-medium text-zinc-500 dark:text-zinc-400">
                    <flux:icon.loading class="size-4" />{{ __('Counting the requests…') }}
                </h2>
                <div class="mb-3 flex items-center gap-4" aria-hidden="true">
                    @foreach ([12, 16, 14, 18] as $width)
                        <span class="flex items-center gap-1.5">
                            <span class="size-2 rounded-full bg-zinc-200 dark:bg-zinc-700"></span>
                            <span class="h-2.5 animate-pulse rounded-full bg-zinc-100 dark:bg-zinc-800" style="width: {{ $width * 4 }}px"></span>
                        </span>
                    @endforeach
                </div>
                <div class="chart-skeleton flex h-28 items-end gap-1 border-b border-dashed border-zinc-200 dark:border-zinc-800" aria-hidden="true">
                    @foreach ([38, 62, 70, 45, 64, 40, 52, 58, 50, 54, 60, 32, 50, 40, 34, 52, 34, 48, 50, 52, 28, 42, 22, 30] as $bar)
                        {{-- Each bar rises and falls on its own beat (staggered start, slightly different speed), like a meter. --}}
                        <div
                            class="chart-skeleton-bar flex-1 rounded-t-sm bg-zinc-200 dark:bg-zinc-700/70"
                            style="height: {{ $bar }}%; animation-delay: -{{ ($loop->index * 137) % 1400 }}ms; animation-duration: {{ 1100 + ($loop->index * 263) % 800 }}ms"
                        ></div>
                    @endforeach
                </div>
            </div>
        @elseif ($this->domains->isNotEmpty() && $parsedSearch['error'] === null)
            @php($chartPeriod = TrafficPeriod::tryFrom($period) ?? TrafficPeriod::Day)
            <div class="relative border-b border-line px-4 pt-4 pb-3 dark:border-zinc-800" data-test="activity-chart">
                <x-ntb.traffic-chart :buckets="$this->chartBuckets" height-class="h-28" selectable="range" client-filter="client" color-toggles wire:loading.class="opacity-60" wire:target.except="heartbeat, range">
                    <x-slot:heading>
                        <h2 class="mb-3 text-sm font-medium text-ink dark:text-white" data-test="activity-chart-heading">
                            {{ __(':requests :requestNoun found across :websites :websiteNoun', [
                                'requests' => Number::format($this->requestCount),
                                'requestNoun' => Str::plural(__('request'), $this->requestCount),
                                'websites' => Number::format($this->websiteCount),
                                'websiteNoun' => Str::plural(__('website'), $this->websiteCount),
                            ]) }}
                        </h2>
                    </x-slot:heading>
                    @if (count($chartIntervals = ChartInterval::for($chartPeriod)) > 1)
                        <x-slot:actions>
                            <flux:select wire:model.live="chartInterval" size="xs" class="w-32" :aria-label="__('Bar size')" data-test="chart-interval">
                                @foreach ($chartIntervals as $intervalOption)
                                    <flux:select.option :value="$intervalOption === ChartInterval::default($chartPeriod) ? '' : $intervalOption->value">{{ $intervalOption->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </x-slot:actions>
                    @endif
                </x-ntb.traffic-chart>
            </div>
        @endif

        @php($clientNames = match ($parsedSearch['subjects']) {
            [RuleSubject::Ip] => ['view' => __('IPs'), 'column' => __('IP'), 'one' => __('IP'), 'many' => __('IPs')],
            [RuleSubject::Ja4] => ['view' => __('Fingerprints'), 'column' => __('Fingerprint'), 'one' => __('fingerprint'), 'many' => __('fingerprints')],
            default => ['view' => __('Clients'), 'column' => __('Client'), 'one' => __('client'), 'many' => __('clients')],
        })
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 pt-3 text-xs text-zinc-500 dark:text-zinc-400">
            <div class="inline-flex rounded-md border border-line bg-canvas p-0.5 font-medium dark:border-zinc-700 dark:bg-zinc-900" data-privacy-hide role="tablist" aria-label="{{ __('List') }}" data-test="activity-view">
                @foreach ($countsShown ? [
                    'requests' => __(':label (:count)', ['label' => __('Requests'), 'count' => Number::format($this->requestCount)]),
                    'clients' => __(':label (:count)', ['label' => $clientNames['view'], 'count' => Number::format($this->clientCount)]),
                    'paths' => __(':label (:count)', ['label' => __('Paths'), 'count' => Number::format($this->pathCount)]),
                ] : [
                    'requests' => __('Requests'),
                    'clients' => $clientNames['view'],
                    'paths' => __('Paths'),
                ] as $viewOption => $viewLabel)
                    <button
                        type="button"
                        role="tab"
                        wire:click="$set('view', '{{ $viewOption }}')"
                        aria-selected="{{ $view === $viewOption ? 'true' : 'false' }}"
                        title="{{ match ($viewOption) { 'clients' => __('One row per :client, with its requests counted', ['client' => $clientNames['one']]), 'paths' => __('One row per distinct path, with its requests counted'), default => __('Every request') } }}"
                        @class([
                            'rounded px-2 py-0.5 transition',
                            'bg-white text-ink shadow-xs dark:bg-zinc-700 dark:text-white' => $view === $viewOption,
                            'hover:text-ink dark:hover:text-white' => $view !== $viewOption,
                        ])
                        data-test="view-{{ $viewOption }}"
                    >{{ $viewLabel }}@unless ($countsShown)<span class="ms-1 inline-block h-2 w-6 animate-pulse rounded-full bg-zinc-200 align-middle dark:bg-zinc-700" aria-hidden="true"></span>@endunless</button>
                @endforeach
            </div>
            @if ($rangeLabel = $this->rangeLabel)
                <button
                    type="button"
                    wire:click="$set('range', '')"
                    title="{{ __('Only the stretch picked on the chart. Click to list the whole period again.') }}"
                    class="inline-flex items-center gap-1 rounded-full border border-coral-300 bg-coral-50 px-2 py-0.5 font-medium text-coral-700 transition hover:border-coral-400 dark:border-coral-500/40 dark:bg-coral-500/10 dark:text-coral-300"
                    data-test="activity-range"
                >
                    <flux:icon.clock variant="micro" class="size-3" />{{ $rangeLabel }}<flux:icon.x-mark variant="micro" class="size-3" />
                </button>
            @endif
            @if ($countsShown)
            <span class="inline-flex items-center gap-1.5" data-privacy-hide><span class="size-2 rounded-full bg-(--chart-suspicious)"></span><strong class="font-semibold text-ink tabular-nums dark:text-white">{{ Number::format($this->suspiciousCount) }}</strong> {{ __('suspicious') }}</span>
            <button type="button" wire:click="$set('client', 'spoofed')" title="{{ __('Requests from clients that aren\'t the browser they claim to be. Click to show only them.') }}" class="inline-flex items-center gap-1.5 hover:text-ink dark:hover:text-white" data-privacy-hide data-test="spoofed-count"><span class="size-2 rounded-full bg-rose-500"></span><strong class="font-semibold text-ink tabular-nums dark:text-white">{{ Number::format($this->spoofedCount) }}</strong> {{ __('spoofed') }}</button>
            @else
                @foreach (['bg-(--chart-suspicious)' => __('suspicious'), 'bg-rose-500' => __('spoofed')] as $dot => $countLabel)
                    <span class="inline-flex items-center gap-1.5" data-privacy-hide aria-hidden="true"><span class="size-2 rounded-full {{ $dot }}"></span><span class="h-2.5 w-10 animate-pulse rounded-full bg-zinc-200 dark:bg-zinc-700"></span> {{ $countLabel }}</span>
                @endforeach
            @endif
            @php($hidden = $this->hiddenNoise())
            <span class="inline-flex items-center gap-1" data-privacy-hide data-test="activity-hide">
                <span>{{ __('Hide') }}</span>
                @foreach (ActivityNoise::cases() as $noise)
                    @continue($noise === ActivityNoise::Mine && ! $this->hasMyTraffic)
                    @php($isHidden = in_array($noise, $hidden, true))
                    <button
                        type="button"
                        wire:click="toggleHide('{{ $noise->value }}')"
                        title="{{ $noise->description() }}"
                        aria-pressed="{{ $isHidden ? 'true' : 'false' }}"
                        @class([
                            'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 font-medium transition',
                            'border-ink bg-ink text-white dark:border-white dark:bg-white dark:text-ink' => $isHidden,
                            'border-line text-zinc-600 hover:border-zinc-400 dark:border-zinc-700 dark:text-zinc-300 dark:hover:border-zinc-500' => ! $isHidden,
                        ])
                        data-test="hide-{{ $noise->value }}"
                    >
                        @if ($isHidden)
                            <flux:icon.eye-slash variant="micro" class="size-3" />
                        @endif
                        <span @class(['font-mono' => $noise === ActivityNoise::Homepage])>{{ $noise->label() }}</span>
                    </button>
                @endforeach
            </span>
            @if ($this->hasFilters)
                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
            @endif
            <span class="ms-auto" wire:loading.delay wire:target.except="heartbeat">{{ __('Updating…') }}</span>
            @if ($ruleDraftUrl = $this->ruleDraftUrl)
                <flux:button size="xs" icon="scale" :href="$ruleDraftUrl" wire:navigate title="{{ __('Start a rule that tags the :clients this search finds, from now on', ['clients' => $clientNames['many']]) }}" data-test="create-rule">{{ __('Create a rule') }}</flux:button>
            @endif
        </div>

        <div class="px-4 pb-2 transition-opacity" wire:loading.class="opacity-50" wire:target="range">
            @if ((match ($view) { 'clients' => $this->clients, 'paths' => $this->paths, default => $this->requests })->isEmpty())
                <div class="py-16 text-center">
                    <flux:icon.magnifying-glass class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                    <p class="mt-3 text-sm font-medium">{{ match (true) { $this->domains->isEmpty() => __('No domains yet'), $parsedSearch['error'] !== null => __('Finish the search to see requests'), default => __('Nothing matches those filters') } }}</p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ match (true) { $this->domains->isEmpty() => __('Add a domain to start seeing requests here.'), $parsedSearch['error'] !== null => __('It doesn\'t parse yet. The message under it says where.'), default => __('Try a longer period or clear a filter.') } }}
                    </p>
                </div>
            @else
                {{-- Right-click a client to hide it or mark it as yours; right-click elsewhere on a request to categorize its path. --}}
                <div
                    x-data="{
                        open: false, mode: 'path', x: 0, y: 0, id: null, path: '', current: '', manual: false, kind: '', value: '', name: '', enriched: false,
                        canCategorize: {{ $this->canManageRules ? 'true' : 'false' }},
                        show(event) {
                            const client = event.target.closest('[data-client-kind]');
                            const row = event.target.closest('tr[data-request-id]');
                            if (client) {
                                Object.assign(this, { mode: 'client', kind: client.dataset.clientKind, value: client.dataset.clientValue, name: client.dataset.clientName || client.dataset.clientValue, enriched: client.dataset.clientEnriched === '1' });
                            } else if (this.canCategorize && row && row.dataset.path) {
                                Object.assign(this, { mode: 'path', id: Number(row.dataset.requestId), path: row.dataset.path, current: row.dataset.category, manual: row.dataset.manual === '1' });
                            } else {
                                return;
                            }
                            event.preventDefault();
                            Object.assign(this, { x: event.clientX, y: event.clientY, open: true });
                            this.$nextTick(() => {
                                const box = this.$refs.menu.getBoundingClientRect();
                                this.x = Math.max(8, Math.min(this.x, window.innerWidth - box.width - 8));
                                this.y = Math.max(8, Math.min(this.y, window.innerHeight - box.height - 8));
                            });
                        },
                        pick(choice) { this.open = false; $wire.categorizePath(this.id, choice); },
                        client(action) {
                            this.open = false;
                            if (action === 'exclude') $wire.excludeClient(this.kind, this.value);
                            if (action === 'mine') $wire.markMine(this.kind, this.value);
                            if (action === 'only') $wire.onlyClient(this.kind, this.value);
                            if (action === 'enrich') $wire.enrichClient(this.value);
                            if (action === 'name') $wire.dispatch('label-value', { kind: this.kind, value: this.value });
                        },
                    }"
                    x-on:contextmenu="show($event)"
                    x-on:keydown.escape.window="open = false"
                    x-on:scroll.window="open = false"
                >
                    @if ($view === 'clients')
                    @php($groupsByIp = in_array(RuleSubject::Ip, $parsedSearch['subjects'], true))
                    @php($groupsByJa4 = in_array(RuleSubject::Ja4, $parsedSearch['subjects'], true))
                    <flux:table :paginate="$this->clients" wire:loading.class="opacity-60" wire:target.except="heartbeat" data-test="clients-table">
                        <flux:table.columns>
                            <flux:table.column>{{ $clientNames['column'] }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Requests') }}</flux:table.column>
                            <flux:table.column class="max-md:hidden">{{ __('Sites') }}</flux:table.column>
                            <flux:table.column class="max-md:hidden">{{ __('First seen') }}</flux:table.column>
                            <flux:table.column>{{ __('Last seen') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->clients as $client)
                                @php($latest = $client->latest)
                                @php($fingerprint = Ja4Fingerprint::tryFrom($latest?->ja4))
                                <flux:table.row
                                    :key="'client-'.$client->last_request_id"
                                    class="cursor-pointer hover:bg-canvas dark:hover:bg-zinc-800/50"
                                    wire:click="showClient('{{ $groupsByIp ? $latest?->client_ip : '' }}', '{{ $groupsByJa4 ? $fingerprint?->value : '' }}')"
                                    title="{{ __('See this :client\'s requests', ['client' => $clientNames['one']]) }}"
                                    data-test="client-row"
                                >
                                    <flux:table.cell class="font-mono text-xs">
                                        @if ($groupsByIp && $latest)
                                            @php($neighborhood = $this->neighborhoods[$latest->client_ip] ?? null)
                                            <span data-client-kind="ip" data-client-value="{{ $latest->client_ip }}" data-client-name="{{ app(LabelDirectory::class)->labelFor($this->team->id, LabelKind::Ip, $latest->client_ip)?->name }}" data-client-enriched="{{ $neighborhood ? '1' : '' }}">@if ($neighborhood)<x-ntb.prefix-ladder :$neighborhood :scale="$this->neighborhoodScale" />@else<x-ntb.label kind="ip" :value="$latest->client_ip" />@endif</span>
                                            <x-ntb.country :code="$latest->country_code" class="ms-1 text-zinc-400" />
                                            @if ($latest->network || $neighborhood?->badge())
                                                <span class="mt-0.5 flex max-w-64 items-center gap-1 font-sans text-[0.65rem] text-zinc-500 dark:text-zinc-400" title="{{ $latest->network ? 'AS'.$latest->asn.' · '.$latest->network->name : '' }}">
                                                    @if ($latest->network)
                                                        <flux:icon.server-stack variant="micro" class="size-3 shrink-0" />
                                                        <span class="truncate">{{ $latest->network->name }}</span>
                                                    @endif
                                                    <x-ntb.neighborhood-badge :$neighborhood />
                                                </span>
                                            @endif
                                        @endif
                                        @if ($groupsByJa4 && $fingerprint)
                                            <span
                                                class="mt-0.5 flex items-center gap-1 text-[0.65rem] text-zinc-500 dark:text-zinc-400"
                                                title="{{ $fingerprint->value }} · {{ $fingerprint->summary() }}"
                                                data-client-kind="ja4"
                                                data-client-value="{{ $fingerprint->value }}"
                                                data-client-name="{{ app(LabelDirectory::class)->labelFor($this->team->id, LabelKind::Ja4, $fingerprint->value)?->name }}"
                                            >
                                                <flux:icon.finger-print variant="micro" class="size-3 shrink-0" />
                                                <x-ntb.label kind="ja4" :value="$fingerprint->value" :display="$groupsByIp ? $fingerprint->prefix() : null" />
                                            </span>
                                        @elseif ($groupsByJa4 === false && $client->fingerprints > 1)
                                            <span class="mt-0.5 block font-sans text-[0.65rem] text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count TLS fingerprint|:count TLS fingerprints', $client->fingerprints) }}</span>
                                        @endif
                                        @if ($groupsByIp === false)
                                            <span class="mt-0.5 block font-sans text-[0.65rem] text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count IP|:count IPs', $client->ips) }}</span>
                                        @endif
                                        @if ($latest && ($clientTraits = $this->clientTraits($clientProfile = ClientProfile::forRequest($latest))))
                                            <span class="mt-1 flex flex-wrap gap-1 font-sans" data-test="client-traits">
                                                @foreach ($clientTraits as $family => $familyTraits)
                                                    <span title="{{ implode(' ', array_map(fn (ClientTrait $trait) => $trait->explain($clientProfile, $this->browserReleases), $familyTraits)) }}">
                                                        <flux:badge size="sm" :color="$familyTraits[0]->color()">{{ $familyTraits[0]->label() }}</flux:badge>
                                                    </span>
                                                @endforeach
                                            </span>
                                        @endif
                                        {{ $this->clientAddons[$loop->index] ?? '' }}
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="font-mono text-sm font-semibold tabular-nums">{{ Number::format($client->requests) }}</flux:table.cell>
                                    <flux:table.cell class="text-xs max-md:hidden">
                                        {{ (int) $client->hosts === 1 ? $latest?->domain?->hostname : trans_choice(':count site|:count sites', $client->hosts) }}
                                    </flux:table.cell>
                                    <flux:table.cell class="text-xs whitespace-nowrap max-md:hidden">
                                        <span class="block">{{ CarbonImmutable::parse($client->first_seen)->format('M j, H:i:s') }}</span>
                                        <span class="block text-zinc-400">{{ CarbonImmutable::parse($client->first_seen)->diffForHumans(short: true) }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-xs whitespace-nowrap">
                                        <span class="block max-md:hidden">{{ CarbonImmutable::parse($client->last_seen)->format('M j, H:i:s') }}</span>
                                        <span class="block text-zinc-400">{{ CarbonImmutable::parse($client->last_seen)->diffForHumans(short: true) }}</span>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                    @elseif ($view === 'paths')
                    <flux:table :paginate="$this->paths" wire:loading.class="opacity-60" wire:target.except="heartbeat" data-test="paths-table">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Path') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Requests') }}</flux:table.column>
                            <flux:table.column class="max-md:hidden">{{ __('Sites') }}</flux:table.column>
                            <flux:table.column align="end" class="max-md:hidden">{{ __('IPs') }}</flux:table.column>
                            <flux:table.column class="max-md:hidden">{{ __('First seen') }}</flux:table.column>
                            <flux:table.column>{{ __('Last seen') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->paths as $path)
                                @php($latest = $path->latest)
                                <flux:table.row
                                    :key="'path-'.$path->path_id"
                                    class="cursor-pointer hover:bg-canvas dark:hover:bg-zinc-800/50"
                                    wire:click="showPath({{ (int) $path->path_id }})"
                                    title="{{ __('See this path\'s requests') }}"
                                    data-test="path-row"
                                    data-request-id="{{ $path->last_request_id }}"
                                    data-path="{{ $latest?->path }}"
                                    data-category="{{ $latest?->requestPath?->category?->slug() }}"
                                    data-manual="{{ $latest?->requestPath?->isCategorizedByHand() ? '1' : '' }}"
                                >
                                    <flux:table.cell class="max-w-96">
                                        <span class="block truncate font-mono text-xs text-ink dark:text-zinc-100">{{ $latest?->path }}</span>
                                        @if ($pathCategory = $latest?->requestPath?->category)
                                            <span @class(['block font-mono text-[0.65rem]', 'text-coral-700 dark:text-coral-300' => $pathCategory->isProbe(), 'text-zinc-500 dark:text-zinc-400' => ! $pathCategory->isProbe()])>{{ $pathCategory->slug() }}</span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="font-mono text-sm font-semibold tabular-nums">{{ Number::format($path->requests) }}</flux:table.cell>
                                    <flux:table.cell class="text-xs max-md:hidden">
                                        {{ (int) $path->hosts === 1 ? $latest?->domain?->hostname : trans_choice(':count site|:count sites', $path->hosts) }}
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="font-mono text-xs tabular-nums max-md:hidden">{{ Number::format($path->ips) }}</flux:table.cell>
                                    <flux:table.cell class="text-xs whitespace-nowrap max-md:hidden">
                                        <span class="block">{{ CarbonImmutable::parse($path->first_seen)->format('M j, H:i:s') }}</span>
                                        <span class="block text-zinc-400">{{ CarbonImmutable::parse($path->first_seen)->diffForHumans(short: true) }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-xs whitespace-nowrap">
                                        <span class="block max-md:hidden">{{ CarbonImmutable::parse($path->last_seen)->format('M j, H:i:s') }}</span>
                                        <span class="block text-zinc-400">{{ CarbonImmutable::parse($path->last_seen)->diffForHumans(short: true) }}</span>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                    @else
                    <flux:table :paginate="$this->requests" wire:loading.class="opacity-60" wire:target.except="heartbeat">
                        <flux:table.columns>
                            <flux:table.column>{{ __('When') }}</flux:table.column>
                            <flux:table.column>{{ __('Request') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column>{{ __('Action') }}</flux:table.column>
                            <flux:table.column class="max-md:hidden">{{ __('Client') }}</flux:table.column>
                            <flux:table.column align="end" class="max-md:hidden">{{ __('Time') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->requests as $request)
                                <flux:table.row
                                    :key="$request->id"
                                    class="cursor-pointer hover:bg-canvas dark:hover:bg-zinc-800/50"
                                    wire:click="inspect({{ $request->id }})"
                                    data-test="activity-row"
                                    data-request-id="{{ $request->id }}"
                                    data-path="{{ $request->path }}"
                                    data-category="{{ $request->requestPath?->category?->slug() }}"
                                    data-manual="{{ $request->requestPath?->isCategorizedByHand() ? '1' : '' }}"
                                >
                                    <flux:table.cell class="text-xs whitespace-nowrap">
                                        <span class="block">{{ $request->occurred_at->format('M j, H:i:s') }}</span>
                                        <span class="block text-zinc-400">{{ $request->occurred_at->diffForHumans(short: true) }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell class="max-w-80">
                                        <span class="block truncate font-mono text-xs text-ink dark:text-zinc-100">
                                            <span class="text-zinc-400">{{ $request->method->label() }}</span> {{ $request->path }}
                                        </span>
                                        {{ $this->requestMarks[$loop->index] ?? '' }}
                                        <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $request->domain->hostname }}
                                            @if ($pathCategory = $request->requestPath?->category)
                                                <span @class(['ms-1 font-mono text-[0.65rem]', 'text-coral-700 dark:text-coral-300' => $pathCategory->isProbe()])>· {{ $pathCategory->slug() }}</span>
                                            @endif
                                        </span>
                                    </flux:table.cell>
                                    <flux:table.cell><x-ntb.status-code :code="$request->status_code" /></flux:table.cell>
                                    <flux:table.cell>
                                        @if (($gymSession = $this->gymSessionsByRequest[$request->id] ?? null) && $gymSession->routines->isNotEmpty())
                                            <flux:tooltip :content="trans_choice('Routine: :names|Routines: :names', $gymSession->routines->count(), ['names' => $gymSession->routines->pluck('name')->join(', ')])" position="top">
                                                <x-ntb.action-badge :action="$request->action" class="cursor-help" data-test="gym-routines" />
                                            </flux:tooltip>
                                        @else
                                            <x-ntb.action-badge :action="$request->action" />
                                        @endif
                                        @if ($gymSession)
                                            <span class="mt-1 flex items-center gap-1 text-[0.65rem] whitespace-nowrap text-zinc-500 dark:text-zinc-400" title="{{ $gymSession->routines->pluck('name')->join(', ') }}" data-test="gym-outcome">
                                                <span @class([
                                                    'size-1.5 shrink-0 rounded-full',
                                                    'bg-zinc-400' => $gymSession->hasGivenUp(),
                                                    'bg-amber-500' => ! $gymSession->hasGivenUp() && $gymSession->status === GymSessionStatus::Training,
                                                    'bg-emerald-500' => $gymSession->status === GymSessionStatus::Cleared,
                                                    'bg-rose-500' => $gymSession->status === GymSessionStatus::TurnedAway,
                                                ])></span>
                                                {{ $gymSession->hasGivenUp() ? __('Gave up') : ($gymSession->status === GymSessionStatus::Training ? __('Still training') : $gymSession->status->label()) }}
                                                · {{ HashPuzzle::describeSeconds($gymSession->secondsInGym()) }}
                                            </span>
                                        @endif
                                        @if ($request->bot_tag)
                                            <span class="mt-1 block font-mono text-[0.65rem] text-zinc-500 dark:text-zinc-400">{{ $request->bot_tag }}</span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="font-mono text-xs max-md:hidden">
                                        @php($neighborhood = $this->neighborhoods[$request->client_ip] ?? null)
                                        <span data-client-kind="ip" data-client-value="{{ $request->client_ip }}" data-client-name="{{ app(LabelDirectory::class)->labelFor($this->team->id, LabelKind::Ip, $request->client_ip)?->name }}" data-client-enriched="{{ $neighborhood ? '1' : '' }}">@if ($neighborhood)<x-ntb.prefix-ladder :$neighborhood :scale="$this->neighborhoodScale" />@else<x-ntb.label kind="ip" :value="$request->client_ip" />@endif</span>
                                        <x-ntb.country :code="$request->country_code" class="ms-1 text-zinc-400" />
                                        @if ($request->network || $neighborhood?->badge())
                                            <span class="mt-0.5 flex max-w-64 items-center gap-1 font-sans text-[0.65rem] text-zinc-500 dark:text-zinc-400" title="{{ $request->network ? 'AS'.$request->asn.' · '.$request->network->name : '' }}">
                                                @if ($request->network)
                                                    <flux:icon.server-stack variant="micro" class="size-3 shrink-0" />
                                                    <span class="max-w-40 truncate">{{ $request->network->name }}</span>
                                                @endif
                                                <x-ntb.neighborhood-badge :$neighborhood />
                                            </span>
                                        @endif
                                        @if ($fingerprint = Ja4Fingerprint::tryFrom($request->ja4))
                                            <a
                                                href="{{ route('activity.index', ['ja4' => $fingerprint->value, 'period' => $this->period]) }}"
                                                wire:navigate
                                                x-on:click.stop
                                                title="{{ $fingerprint->value }} · {{ $fingerprint->summary() }}"
                                                class="mt-0.5 flex items-center gap-1 text-[0.65rem] text-zinc-500 hover:text-ink hover:underline dark:text-zinc-400 dark:hover:text-white"
                                                data-test="ja4-link"
                                                data-client-kind="ja4"
                                                data-client-value="{{ $fingerprint->value }}"
                                                data-client-name="{{ app(LabelDirectory::class)->labelFor($this->team->id, LabelKind::Ja4, $fingerprint->value)?->name }}"
                                            >
                                                <flux:icon.finger-print variant="micro" class="size-3 shrink-0" />
                                                <x-ntb.label kind="ja4" :value="$fingerprint->value" :display="$fingerprint->prefix()" />
                                            </a>
                                        @endif
                                        @if ($request->client_hints && app(LabelDirectory::class)->labelFor($this->team->id, LabelKind::ClientHints, $request->client_hints))
                                            <span class="mt-0.5 flex items-center gap-1 text-[0.65rem] text-zinc-500 dark:text-zinc-400" title="{{ __('Client hints') }}: {{ $request->client_hints }}" data-test="hints-label">
                                                <flux:icon.tag variant="micro" class="size-3 shrink-0" />
                                                <x-ntb.label kind="hints" :value="$request->client_hints" />
                                            </span>
                                        @endif
                                        @php($clientProfile = ClientProfile::forRequest($request))
                                        @if ($clientTraits = $this->clientTraits($clientProfile))
                                            <span class="mt-1 flex flex-wrap gap-1 font-sans" data-test="client-traits">
                                                @foreach ($clientTraits as $family => $familyTraits)
                                                    <button
                                                        type="button"
                                                        wire:click.stop="$set('client', '{{ $family }}')"
                                                        wire:key="client-trait-{{ $request->id }}-{{ $family }}"
                                                        title="{{ implode(' ', array_map(fn (ClientTrait $trait) => $trait->explain($clientProfile, $this->browserReleases), $familyTraits)) }}"
                                                        data-test="client-trait-{{ $family }}"
                                                    >
                                                        <flux:badge size="sm" :color="$familyTraits[0]->color()">{{ $familyTraits[0]->label() }}</flux:badge>
                                                    </button>
                                                @endforeach
                                            </span>
                                        @endif
                                        {{ $this->clientAddons[$loop->index] ?? '' }}
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="font-mono text-xs tabular-nums max-md:hidden">
                                        {{ $request->duration_ms >= 1000 ? Number::format($request->duration_ms / 1000, 1).'s' : $request->duration_ms.'ms' }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                    @endif

                    <div class="flex items-center justify-end gap-2 pt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        <span>{{ __('Rows per page') }}</span>
                        <flux:select wire:model.live="perPage" size="xs" class="w-24" :aria-label="__('Rows per page')" data-test="per-page">
                            @foreach ($this::PAGE_SIZES as $pageSize)
                                <flux:select.option :value="$pageSize">{{ Number::format($pageSize) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div
                        x-show="open"
                        style="display: none"
                        x-ref="menu"
                        x-on:click.outside="open = false"
                        x-bind:style="{ left: x + 'px', top: y + 'px' }"
                        class="fixed z-50 w-60 rounded-lg border border-line bg-white p-1 text-sm shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
                        role="menu"
                    >
                        <div x-show="mode === 'client'" data-test="client-menu">
                            <p class="truncate px-2 pt-1.5 pb-1 font-mono text-xs text-zinc-500 dark:text-zinc-400" x-text="name" x-bind:title="value"></p>
                            <button type="button" role="menuitem" x-on:click="client('exclude')" class="flex w-full items-center gap-2 rounded px-2 py-1 text-start hover:bg-canvas dark:hover:bg-zinc-800">
                                <flux:icon.eye-slash variant="micro" class="size-3.5 text-zinc-400" /> {{ __('Hide from this view') }}
                            </button>
                            <button type="button" role="menuitem" x-on:click="client('only')" class="flex w-full items-center gap-2 rounded px-2 py-1 text-start hover:bg-canvas dark:hover:bg-zinc-800">
                                <flux:icon.funnel variant="micro" class="size-3.5 text-zinc-400" /> {{ __('Show only this') }}
                            </button>
                            <button type="button" role="menuitem" x-show="kind === 'ip' && ! value.includes(':')" x-on:click="client('enrich')" class="flex w-full items-center gap-2 rounded px-2 py-1 text-start hover:bg-canvas dark:hover:bg-zinc-800" data-test="enrich-client">
                                <flux:icon.chart-bar variant="micro" class="size-3.5 text-violet-500" /> <span x-text="enriched ? '{{ __('Stop enriching') }}' : '{{ __('Enrich: show its neighbors') }}'"></span>
                            </button>
                            @if ($this->canLabelClients)
                                <div class="my-1 border-t border-line dark:border-zinc-800"></div>
                                <button type="button" role="menuitem" x-on:click="client('mine')" class="flex w-full items-center gap-2 rounded px-2 py-1 text-start hover:bg-canvas dark:hover:bg-zinc-800" data-test="mark-mine">
                                    <flux:icon.user variant="micro" class="size-3.5 text-coral-600" /> {{ __('This is my traffic, always hide') }}
                                </button>
                                <button type="button" role="menuitem" x-on:click="client('name')" class="flex w-full items-center gap-2 rounded px-2 py-1 text-start hover:bg-canvas dark:hover:bg-zinc-800">
                                    <flux:icon.tag variant="micro" class="size-3.5 text-zinc-400" /> {{ __('Name it…') }}
                                </button>
                            @endif
                        </div>
                        @if ($this->canManageRules)
                        <div x-show="mode === 'path'" data-test="categorize-menu">
                            <p class="truncate px-2 pt-1.5 pb-1 font-mono text-xs text-zinc-500 dark:text-zinc-400" x-text="path"></p>
                            @foreach (['probe' => __('Probe'), 'asset' => __('Asset')] as $family => $familyLabel)
                                <p class="px-2 pt-2 pb-0.5 font-mono text-[0.6rem] tracking-widest text-zinc-400 uppercase">{{ $familyLabel }}</p>
                                @foreach (PathCategory::cases() as $categoryOption)
                                    @continue($categoryOption->family() !== $family)
                                    <button type="button" role="menuitem" x-on:click="pick('{{ $categoryOption->slug() }}')" title="{{ $categoryOption->description() }}" class="flex w-full items-center justify-between rounded px-2 py-1 text-start hover:bg-canvas dark:hover:bg-zinc-800">
                                        <span>{{ Str::after($categoryOption->slug(), '/') }}</span>
                                        <flux:icon.check variant="micro" class="size-3.5 text-coral-600" x-show="current === '{{ $categoryOption->slug() }}'" />
                                    </button>
                                @endforeach
                            @endforeach
                            <div class="my-1 border-t border-line dark:border-zinc-800"></div>
                            <button type="button" role="menuitem" x-on:click="pick('none')" class="flex w-full items-center justify-between rounded px-2 py-1 text-start hover:bg-canvas dark:hover:bg-zinc-800">
                                <span>{{ __('Ordinary page') }}</span>
                                <flux:icon.check variant="micro" class="size-3.5 text-coral-600" x-show="current === ''" />
                            </button>
                            <button type="button" role="menuitem" x-show="manual" x-on:click="pick('auto')" class="flex w-full items-center rounded px-2 py-1 text-start text-zinc-500 hover:bg-canvas dark:text-zinc-400 dark:hover:bg-zinc-800">
                                {{ __('Back to automatic') }}
                            </button>
                            <p class="px-2 pt-1 pb-1.5 text-[0.65rem] text-zinc-400" x-show="manual">{{ __('Set by hand. Patterns won\'t change it.') }}</p>
                        </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </x-ntb.panel>

    <div class="mt-4 flex gap-3 rounded-xl border border-dashed border-line px-4 py-3 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
        <flux:icon.information-circle variant="mini" class="size-4 shrink-0" />
        <p>{{ __('Paths are redacted at the edge before they leave the node: query values, claim PINs, and secret headers never reach NiceTryBot. A tag is a signal, not a verdict. Review before you enforce.') }}</p>
    </div>

    <flux:modal name="request-detail" class="md:w-2xl">
        @if ($request = $this->inspectedRequest)
            <div class="space-y-5">
                <div>
                    <p class="mb-2 font-mono text-xs tracking-[0.14em] text-zinc-500 uppercase dark:text-zinc-400">{{ __('Request evidence') }}</p>
                    <flux:heading size="lg" class="font-mono break-all">{{ $request->method->label() }} {{ $request->path }}</flux:heading>
                    <flux:subheading>{{ $request->domain->hostname }} · {{ $request->occurred_at->toDayDateTimeString() }}</flux:subheading>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-ntb.status-code :code="$request->status_code" />
                    <x-ntb.action-badge :action="$request->action" />
                    @if ($request->bot_tag)
                        <flux:badge size="sm" icon="bug-ant">{{ $request->bot_tag }}</flux:badge>
                    @endif
                    @if ($pathCategory = $request->requestPath?->category)
                        <a href="{{ route('activity.index', ['category' => $pathCategory->slug(), 'period' => '30d']) }}" wire:navigate title="{{ $pathCategory->description() }}">
                            <flux:badge size="sm" icon="squares-plus" :color="$pathCategory->isProbe() ? 'rose' : 'zinc'">{{ $pathCategory->slug() }}</flux:badge>
                        </a>
                    @elseif ($category = $request->notFoundCategory())
                        <flux:badge size="sm" :color="$category->color()">{{ $category->label() }}</flux:badge>
                    @endif
                </div>

                @if ($this->inspectedVerdicts->isNotEmpty())
                    <div class="rounded-lg border border-amber-200 bg-amber-50/60 p-3 dark:border-amber-500/30 dark:bg-amber-500/10" data-test="client-tags">
                        <p class="mb-2 text-xs font-medium text-amber-900 dark:text-amber-200">{{ __('This client is tagged right now') }}</p>
                        <ul class="space-y-1.5">
                            @foreach ($this->inspectedVerdicts as $verdict)
                                <li class="flex flex-wrap items-center gap-2 text-xs" wire:key="client-tag-{{ $verdict->id }}">
                                    <a href="{{ $verdict->tag ? route('tags.index', ['tag' => $verdict->tag]) : route('tags.index', ['bans' => 1]) }}" wire:navigate>
                                        <flux:badge size="sm" icon="tag" :color="$verdict->action->color()">{{ $verdict->tag ?? $verdict->action->label() }}</flux:badge>
                                    </a>
                                    <span class="text-zinc-600 dark:text-zinc-300">{{ $verdict->rule?->name ?? __('Deleted rule') }}</span>
                                    <span class="text-zinc-500 dark:text-zinc-400">· {{ $verdict->client_ip && $verdict->ja4_id ? __('IP and fingerprint') : ($verdict->client_ip ? __('IP') : __('fingerprint')) }} · {{ $verdict->expires_at ? __('expires in :time', ['time' => $verdict->expires_at->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true)]) : __('never expires') }}</span>
                                    @if ($this->canManageRules)
                                        <flux:button size="xs" variant="ghost" class="ms-auto" wire:click="liftVerdict({{ $verdict->id }})" data-test="lift-client-tag">{{ __('Lift') }}</flux:button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @php($inspectedClient = ClientProfile::forRequest($request))
                @if ($inspectedTraits = $inspectedClient->traits($this->browserReleases))
                    {{-- Spoofed traits come first, so the first trait sets the tone. --}}
                    <div
                        @class([
                            'rounded-lg border p-3',
                            'border-rose-200 bg-rose-50/60 dark:border-rose-500/30 dark:bg-rose-500/10' => $inspectedTraits[0]->isSpoofed(),
                            'border-amber-200 bg-amber-50/60 dark:border-amber-500/30 dark:bg-amber-500/10' => ! $inspectedTraits[0]->isSpoofed(),
                        ])
                        data-test="client-traits-detail"
                    >
                        <p @class(['mb-2 text-xs font-medium', 'text-rose-900 dark:text-rose-200' => $inspectedTraits[0]->isSpoofed(), 'text-amber-900 dark:text-amber-200' => ! $inspectedTraits[0]->isSpoofed()])>
                            {{ $inspectedTraits[0]->isSpoofed() ? __('This client isn\'t the browser it claims to be') : __('This client runs an old browser') }}
                        </p>
                        <ul class="space-y-1.5">
                            @foreach ($inspectedTraits as $trait)
                                <li class="flex flex-wrap items-center gap-2 text-xs" wire:key="inspected-client-trait-{{ $trait->value }}">
                                    <a href="{{ route('activity.index', ['client' => $trait->family(), 'period' => $this->period]) }}" wire:navigate>
                                        <flux:badge size="sm" :color="$trait->color()" class="font-mono">{{ $trait->value }}</flux:badge>
                                    </a>
                                    <span class="text-zinc-600 dark:text-zinc-300">{{ $trait->explain($inspectedClient, $this->browserReleases) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <dl class="grid gap-x-6 gap-y-4 rounded-lg bg-canvas p-4 text-sm sm:grid-cols-2 dark:bg-zinc-800/60">
                    <div>
                        <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Client IP') }}</dt>
                        <dd class="font-mono">
                            <span class="group/label flex items-center gap-1"><x-ntb.label kind="ip" :value="$request->client_ip" /><x-ntb.label-button kind="ip" :value="$request->client_ip" /></span>
                            @if (app(LabelDirectory::class)->labelFor($this->team->id, LabelKind::Ip, $request->client_ip))
                                <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $request->client_ip }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Network') }}</dt>
                        @if ($request->network)
                            <dd>
                                <a href="{{ route('activity.index', ['asn' => $request->asn, 'period' => '30d']) }}" wire:navigate class="underline decoration-zinc-300 underline-offset-2 hover:decoration-current dark:decoration-zinc-600">{{ $request->network->name }}</a>
                                <span class="font-mono text-xs text-zinc-400">AS{{ $request->asn }}</span>
                                @if ($request->network->isHosting())
                                    <flux:badge size="sm" color="amber" class="ms-1">{{ __('Hosting') }}</flux:badge>
                                @endif
                            </dd>
                        @else
                            <dd class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Unknown') }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Location') }}</dt>
                        @if ($request->location)
                            <dd>
                                <a href="{{ route('activity.index', ['country' => $request->location->country_code, 'period' => '30d']) }}" wire:navigate class="inline-flex items-center gap-1.5 underline decoration-zinc-300 underline-offset-2 hover:decoration-current dark:decoration-zinc-600"><x-ntb.country :code="$request->location->country_code" :show-code="false" />{{ $request->location->label() }}</a>
                            </dd>
                        @else
                            <dd class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Unknown') }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Response time') }}</dt>
                        <dd class="font-mono">{{ Number::format($request->duration_ms) }} ms</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('User agent') }}</dt>
                        @if ($request->user_agent_id)
                            <dd>
                                <span class="group/label flex items-start gap-1">
                                    <a href="{{ route('activity.index', ['ua' => $request->user_agent_id, 'period' => '30d']) }}" wire:navigate class="font-mono text-xs break-all underline decoration-zinc-300 underline-offset-2 hover:decoration-current dark:decoration-zinc-600"><x-ntb.label kind="ua" :value="$request->user_agent" /></a>
                                    <x-ntb.label-button kind="ua" :value="$request->user_agent" />
                                </span>
                                @if (app(LabelDirectory::class)->labelFor($this->team->id, LabelKind::UserAgent, $request->user_agent))
                                    <span class="mt-0.5 block font-mono text-xs break-all text-zinc-500 dark:text-zinc-400">{{ $request->user_agent }}</span>
                                @endif
                            </dd>
                        @else
                            <dd class="font-mono text-xs">—</dd>
                        @endif
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('TLS fingerprint (JA4)') }}</dt>
                        @if ($fingerprint = Ja4Fingerprint::tryFrom($request->ja4))
                            <dd>
                                <span class="group/label flex items-start gap-1">
                                    <a href="{{ route('activity.index', ['ja4' => $fingerprint->value, 'period' => '30d']) }}" wire:navigate class="font-mono text-xs break-all text-ink underline decoration-zinc-300 underline-offset-2 hover:decoration-current dark:text-white dark:decoration-zinc-600"><x-ntb.label kind="ja4" :value="$fingerprint->value" /></a>
                                    <x-ntb.label-button kind="ja4" :value="$fingerprint->value" />
                                </span>
                                @if (app(LabelDirectory::class)->labelFor($this->team->id, LabelKind::Ja4, $fingerprint->value))
                                    <span class="mt-0.5 block font-mono text-xs break-all text-zinc-500 dark:text-zinc-400">{{ $fingerprint->value }}</span>
                                @endif
                                <span @class(['mt-0.5 block text-xs', 'text-zinc-500 dark:text-zinc-400' => $fingerprint->offersAlpn(), 'text-coral-700 dark:text-coral-300' => ! $fingerprint->offersAlpn()])>{{ $fingerprint->summary() }}</span>
                            </dd>
                        @else
                            <dd class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('None. The request arrived over plain HTTP, or before fingerprinting was enabled.') }}</dd>
                        @endif
                    </div>
                    @if ($request->ja4h || $request->client_hints || $request->accept_language)
                        <div class="sm:col-span-2" data-test="header-fingerprint">
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Headers') }}</dt>
                            <dd class="mt-0.5 space-y-0.5 font-mono text-xs break-all text-zinc-600 dark:text-zinc-300">
                                @if ($request->ja4h)
                                    <span class="block" title="{{ __('JA4H-style header fingerprint (header names sorted)') }}">{{ $request->ja4h }}</span>
                                @endif
                                @if ($request->client_hints)
                                    <span class="group/label flex items-start gap-1 text-zinc-500 dark:text-zinc-400">{{ __('Client hints') }}: <x-ntb.label kind="hints" :value="$request->client_hints" /><x-ntb.label-button kind="hints" :value="$request->client_hints" /></span>
                                @endif
                                <span class="block text-zinc-500 dark:text-zinc-400">{{ __('Language') }}: {{ $request->accept_language ?? __('none sent') }}</span>
                            </dd>
                        </div>
                    @endif
                    @if ($request->ja3)
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('JA3') }}</dt>
                            <dd class="font-mono text-xs break-all text-zinc-500 dark:text-zinc-400">{{ $request->ja3 }}</dd>
                        </div>
                    @endif
                </dl>

                {{-- Add-ons' part of the details, from App\Support\RequestAddons. --}}
                {{ app(RequestAddons::class)->details($this->team, $request) }}

                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    @if ($request->action === RequestAction::Diverted && ($gymSession = $this->inspectedGymSession))
                        {{ __('Sent to the gym (:routines) instead of reaching your origin. It spent :time there, worked :hashes hashes and :outcome.', [
                            'routines' => $gymSession->routines->pluck('name')->join(', '),
                            'time' => HashPuzzle::describeSeconds($gymSession->secondsInGym()),
                            'hashes' => Number::abbreviate($gymSession->hashes),
                            'outcome' => match (true) {
                                $gymSession->hasGivenUp() => __('gave up'),
                                $gymSession->status === GymSessionStatus::Cleared => __('got through'),
                                $gymSession->status === GymSessionStatus::TurnedAway => __('was turned away'),
                                default => __('is still training'),
                            },
                        ]) }}
                        @if ($gymSession->switchedClients())
                            <span class="mt-1 block text-coral-700 dark:text-coral-300" data-test="gym-switched-clients">{{ __('The work came from a different IP than the visitor: it may have been handed to another machine.') }}</span>
                        @endif
                        <flux:link :href="route('gym.index')" wire:navigate class="mt-1 block">{{ __('Bot gym') }} →</flux:link>
                    @elseif ($request->action === RequestAction::Diverted)
                        {{ __('Sent to the gym instead of reaching your origin.') }}
                    @elseif ($request->action === RequestAction::Blocked)
                        {{ __('This IP is on the verdict list, so the edge answered 403 without contacting your origin.') }}
                    @elseif ($request->action === RequestAction::Observed)
                        {{ __('Tagged, but passed through because this domain is in observe mode.') }}
                    @else
                        {{ __('Nothing suspicious. Passed straight through to your origin.') }}
                    @endif
                </p>

                <div class="flex flex-wrap justify-end gap-2">
                    {{ app(RequestAddons::class)->actions($this->team, $request) }}
                    <flux:button size="sm" icon="magnifying-glass" :href="route('activity.index', ['ip' => $request->client_ip, 'period' => '30d'])" wire:navigate>{{ __('Everything from this IP') }}</flux:button>
                    @if ($fingerprint = Ja4Fingerprint::tryFrom($request->ja4))
                        <flux:button size="sm" icon="finger-print" :href="route('activity.index', ['ja4' => $fingerprint->value, 'period' => '30d'])" wire:navigate data-test="ja4-everything">{{ __('Everything with this fingerprint') }}</flux:button>
                    @endif
                    <flux:modal.close>
                        <flux:button size="sm" variant="primary">{{ __('Done') }}</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>
    @can('create', [App\Models\ClientLabel::class, $this->team])
        <livewire:pages::clients.label-modal />
    @endcan
</div>
