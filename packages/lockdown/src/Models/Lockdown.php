<?php

namespace NiceTry\Lockdown\Models;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Team;
use App\Support\SharedOrigin;
use App\Support\SharedOrigins;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use NiceTry\Lockdown\Database\Factories\LockdownFactory;
use NiceTry\Lockdown\Support\LockdownPass;

/**
 * A team's lockdown: while engaged, the edge answers every request to the team's locked sites with a
 * maintenance page, so nothing reaches their origins. A shared origin in the pool (App\Support\SharedOrigins)
 * locks all of its sites; a decision about one site (locked, or released) wins over its origin's, so a
 * team can lock everything at once and let sites back out one at a time. Admins get through from an
 * allowlisted address, or with a pass signed by the lockdown's secret.
 *
 * Without shared origins (nothing keeps them, or its add-on is off) there's no pool to add to: locking
 * everything locks every site on its own. Origins already in the pool keep their sites locked, since
 * the sites still carry their origin_id, so turning an add-on off never unlocks anything.
 *
 * @property int $id
 * @property int $team_id
 * @property Carbon|null $engaged_at
 * @property string $secret
 * @property string|null $page_html The team's own maintenance page; null serves the stock one.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PooledOrigin> $pooledOrigins
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Domain> $domainDecisions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LockdownAllowedIp> $allowedIps
 */
#[Fillable(['team_id', 'engaged_at', 'secret', 'page_html'])]
#[Hidden(['secret'])]
class Lockdown extends Model
{
    /** @use HasFactory<LockdownFactory> */
    use HasFactory;

    protected static function newFactory(): LockdownFactory
    {
        return LockdownFactory::new();
    }

    /**
     * How long, in seconds, the edge tells visitors and crawlers to wait before trying again.
     */
    public const int RETRY_AFTER_SECONDS = 3600;

    /**
     * The largest page a team can save, in bytes. The edge holds every lockdown's page in memory.
     */
    public const int MAX_PAGE_BYTES = 256 * 1024;

    /**
     * Where the edge's last check-ins are kept: one timestamp per edge address.
     */
    private const string EDGE_CHECK_INS_KEY = 'lockdown:edge-check-ins';

    /**
     * Get the team's lockdown, setting one up (disengaged, with nothing in the pool) the first time.
     */
    public static function for(Team $team): self
    {
        return self::query()->firstOrCreate(['team_id' => $team->id], ['secret' => self::newSecret()]);
    }

    /**
     * Get the team that owns the lockdown.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the shared origins in the pool, whose sites are all locked unless released one by one.
     *
     * @return HasMany<PooledOrigin, $this>
     */
    public function pooledOrigins(): HasMany
    {
        return $this->hasMany(PooledOrigin::class);
    }

    /**
     * Get the sites decided one by one: locked on their own, or released from their origin's lock.
     *
     * @return BelongsToMany<Domain, $this>
     */
    public function domainDecisions(): BelongsToMany
    {
        return $this->belongsToMany(Domain::class, 'lockdown_domains')->withPivot('locked')->withTimestamps();
    }

    /**
     * Get the addresses that reach the real sites while the lockdown is engaged.
     *
     * @return HasMany<LockdownAllowedIp, $this>
     */
    public function allowedIps(): HasMany
    {
        return $this->hasMany(LockdownAllowedIp::class);
    }

    /**
     * Determine if the lockdown is engaged, so the edge is holding its locked sites.
     */
    public function isEngaged(): bool
    {
        return $this->engaged_at !== null;
    }

    /**
     * Engage the lockdown: the edge starts serving the maintenance page within one poll.
     */
    public function engage(): void
    {
        if (! $this->isEngaged()) {
            $this->update(['engaged_at' => now()]);
        }
    }

    /**
     * Lift the lockdown: every site serves normally again. The pool stays as it is for next time.
     */
    public function lift(): void
    {
        $this->update(['engaged_at' => null]);
    }

    /**
     * Put every shared origin and site of the team in the pool and take back every release. The sites
     * of pooled origins are locked through them; every other site, on its own.
     */
    public function lockEverything(): void
    {
        DB::transaction(function () {
            app(SharedOrigins::class)->origins($this->team)->each(fn (SharedOrigin $origin) => $this->pool($origin->id));
            $this->domainDecisions()->detach();
            $this->domainDecisions()->attach(
                $this->team->domains()->standalone()->pluck('id')->mapWithKeys(fn (int $id) => [$id => ['locked' => true]]),
            );
        });

        $this->touch();
    }

    /**
     * Put a shared origin in the pool, locking every site it serves now or later (except released ones).
     */
    public function addOrigin(int $originId): void
    {
        $this->pool($originId);
        $this->touch();
    }

    /**
     * Take a shared origin out of the pool. Sites of it locked on their own stay locked.
     */
    public function removeOrigin(int $originId): void
    {
        $this->pooledOrigins()->whereKey($originId)->delete();
        $this->touch();
    }

    /**
     * Determine if a shared origin is in the pool.
     */
    public function pools(?int $originId): bool
    {
        return $originId !== null && $this->pooledOrigins()->whereKey($originId)->exists();
    }

    /**
     * Lock one site. A site of a pooled origin just loses its release.
     */
    public function lock(Domain $domain): void
    {
        $this->pools($domain->origin_id)
            ? $this->domainDecisions()->detach($domain->id)
            : $this->domainDecisions()->syncWithoutDetaching([$domain->id => ['locked' => true]]);

        $this->touch();
    }

    /**
     * Release one site so it serves normally while the rest stay locked.
     */
    public function release(Domain $domain): void
    {
        $this->pools($domain->origin_id)
            ? $this->domainDecisions()->syncWithoutDetaching([$domain->id => ['locked' => false]])
            : $this->domainDecisions()->detach($domain->id);

        $this->touch();
    }

    /**
     * Revoke every pass handed out so far by signing future ones with a new secret.
     */
    public function revokePasses(): void
    {
        $this->update(['secret' => self::newSecret()]);
    }

    /**
     * Limit a query of the team's sites to the ones the lockdown covers: locked on their own, or served
     * by a pooled origin and not released.
     *
     * @param  Builder<Domain>  $query
     * @return Builder<Domain>
     */
    public function constrainToLocked(Builder $query): Builder
    {
        $decided = fn (bool $locked) => fn ($decision) => $decision->from('lockdown_domains')
            ->whereColumn('lockdown_domains.domain_id', 'domains.id')
            ->where('lockdown_domains.locked', $locked);

        return $query->where(fn (Builder $query) => $query
            ->whereExists($decided(true))
            ->orWhere(fn (Builder $query) => $query
                ->whereIn('domains.origin_id', DB::table('lockdown_origins')->where('lockdown_id', $this->id)->select('origin_id'))
                ->whereNotExists($decided(false))));
    }

    /**
     * Get the team's sites the lockdown covers.
     *
     * @return Builder<Domain>
     */
    public function lockedDomains(): Builder
    {
        return $this->constrainToLocked($this->team->domains()->getQuery());
    }

    /**
     * Determine if the lockdown covers a site.
     */
    public function covers(Domain $domain): bool
    {
        return $this->lockedDomains()->whereKey($domain->id)->exists();
    }

    /**
     * Get a link that lets the browser that opens it through the lockdown on one of the site's hostnames.
     * The link works for a few minutes; the pass it hands over lasts until the given time.
     */
    public function passUrl(string $hostname, CarbonInterface $passExpiresAt): string
    {
        return LockdownPass::url($this->secret, $hostname, now()->addMinutes(5)->getTimestamp(), $passExpiresAt->getTimestamp());
    }

    /**
     * Get what the edge needs to hold the lockdown: every hostname the locked sites are served on, the
     * allowlist, the secret that signs passes, and the maintenance page.
     *
     * @return array{team: int, hosts: list<string>, allow: list<string>, secret: string, html: string, retry_after: int}
     */
    public function edgePayload(): array
    {
        return [
            'team' => $this->team_id,
            'hosts' => array_values($this->lockedDomains()
                ->whereIn('status', [DomainStatus::Active, DomainStatus::Paused])
                ->whereNotNull('verified_at')
                ->withEdgeHostnames()
                ->get()
                ->flatMap(fn (Domain $domain) => $domain->edgeHostnames())
                ->unique()
                ->sort()
                ->all()),
            'allow' => array_values($this->allowedIps()->orderBy('range')->get()->map(fn (LockdownAllowedIp $allowedIp) => $allowedIp->range)->all()),
            'secret' => $this->secret,
            'html' => $this->page(),
            'retry_after' => self::RETRY_AFTER_SECONDS,
        ];
    }

    /**
     * Get the page the edge serves on the locked sites: the team's own, or the stock one.
     */
    public function page(): string
    {
        return $this->page_html ?? self::maintenancePage();
    }

    /**
     * Get the stock maintenance page, rendered once and kept in the cache. It names no one and loads
     * nothing (the site behind it is locked, so it can't), so it looks like any site's planned downtime.
     */
    public static function maintenancePage(): string
    {
        return Cache::rememberForever('lockdown:maintenance-page:'.md5_file(View::getFinder()->find('lockdown::maintenance')), fn () => view('lockdown::maintenance')->render());
    }

    /**
     * Note that an edge picked up the current lockdowns, so the app can show when a change has landed.
     */
    public static function recordEdgeCheckIn(string $edge): void
    {
        $checkIns = array_filter(
            [...self::storedCheckIns(), $edge => now()->getTimestamp()],
            fn (int $at) => $at >= now()->subHour()->getTimestamp(),
        );

        Cache::put(self::EDGE_CHECK_INS_KEY, $checkIns, now()->addHour());
    }

    /**
     * Get when each edge last picked up the lockdowns, most recent first.
     *
     * @return Collection<string, Carbon>
     */
    public static function edgeCheckIns(): Collection
    {
        return collect(self::storedCheckIns())
            ->sortDesc()
            ->map(fn (int $at) => Carbon::createFromTimestamp($at));
    }

    /**
     * Get the stored check-ins: each edge's address and the Unix time it last checked in.
     *
     * @return array<string, int>
     */
    private static function storedCheckIns(): array
    {
        $stored = Cache::get(self::EDGE_CHECK_INS_KEY);

        return is_array($stored)
            ? array_filter($stored, fn (mixed $at, mixed $edge) => is_string($edge) && is_int($at), ARRAY_FILTER_USE_BOTH)
            : [];
    }

    /**
     * Add a shared origin to the pool unless it's there already.
     */
    private function pool(int $originId): void
    {
        $this->pooledOrigins()->firstOrCreate(['origin_id' => $originId]);
    }

    /**
     * Get a new secret for signing passes.
     */
    private static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'engaged_at' => 'datetime',
            'secret' => 'encrypted',
        ];
    }
}
