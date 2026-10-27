<?php

namespace App\Models;

use App\Enums\DomainStatus;
use App\Enums\HostHeaderMode;
use App\Enums\HstsPolicy;
use App\Enums\ProtectionMode;
use App\Support\HeaderName;
use App\Support\PreviewHostname;
use App\Support\PrivacyHostname;
use App\Support\SharedOrigins;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $origin_id Set when the domain is one of a shared origin's sites; its settings then come from the origin (App\Support\SharedOrigins).
 * @property string $hostname
 * @property string $privacy_hostname A made-up name to show in its place on a page redacted for screen sharing (PrivacyHostname).
 * @property string|null $preview_hostname Where the edge serves the site before DNS moves, like shop-example-com.preview.example. Null under the preview zone itself.
 * @property string $origin_url
 * @property HostHeaderMode $host_header_mode
 * @property string|null $origin_host_header
 * @property string|null $forwarded_host_header Comma-separated: every header that carries the visitor's host, like "X-Domain, User-Custom-Domain".
 * @property string|null $server_header What the edge answers in the Server header; null keeps its default (nginx).
 * @property int $origin_timeout_seconds
 * @property HstsPolicy $hsts_policy
 * @property bool $cache_assets Whether the edge tells browsers to cache /img, /css and /js.
 * @property bool $serves_www Whether www.<hostname> is served as this domain too. Only for a domain of its own; a site of a shared origin follows the origin.
 * @property ProtectionMode $protection_mode
 * @property DomainStatus $status
 * @property string $verification_token
 * @property string $origin_secret
 * @property Carbon|null $verified_at
 * @property Carbon|null $edge_dns_pointed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, EdgeRequest> $edgeRequests
 */
#[Fillable([
    'origin_id',
    'hostname',
    'privacy_hostname',
    'preview_hostname',
    'origin_url',
    'host_header_mode',
    'origin_host_header',
    'forwarded_host_header',
    'server_header',
    'origin_timeout_seconds',
    'hsts_policy',
    'cache_assets',
    'serves_www',
    'protection_mode',
    'status',
    'verification_token',
    'origin_secret',
    'verified_at',
    'edge_dns_pointed_at',
])]
#[Hidden(['verification_token', 'origin_secret'])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    /**
     * The header the edge adds so the origin can reject traffic that bypassed it.
     */
    public const string ORIGIN_SECRET_HEADER = 'X-NiceTry-Origin-Secret';

    /**
     * What the edge's Server header says when a domain doesn't choose: a common server, not Caddy.
     */
    public const string DEFAULT_SERVER_HEADER = 'nginx';

    /**
     * Server headers worth pretending to be, e.g. for a honeypot that looks old and unpatched.
     *
     * @var list<string>
     */
    public const array SERVER_HEADER_SUGGESTIONS = [
        'nginx',
        'nginx/1.18.0 (Ubuntu)',
        'Apache',
        'Apache/2.4.29 (Ubuntu)',
        'Microsoft-IIS/10.0',
        'Microsoft-IIS/8.5',
        'LiteSpeed',
        'openresty',
    ];

    /**
     * The model's default attribute values, matching the columns' defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'hsts_policy' => 'off',
        'cache_assets' => false,
        'serves_www' => false,
    ];

    /**
     * Give every new domain a made-up stand-in and a preview hostname, whichever way it was added.
     */
    protected static function booted(): void
    {
        static::creating(function (Domain $domain): void {
            $domain->privacy_hostname ??= PrivacyHostname::generate();
            $domain->preview_hostname ??= PreviewHostname::generate($domain->hostname);
        });
    }

    /**
     * Get the team that owns the domain.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Set the domain's protection mode. A site of a shared origin keeps a mode set here as its own, past
     * the origin's changes, and null sends it back to following its origin (App\Support\SharedOrigins);
     * a domain of its own keeps its mode on null.
     */
    public function setProtectionMode(?ProtectionMode $mode): void
    {
        if (! app(SharedOrigins::class)->setProtectionMode($this, $mode)) {
            $this->update(['protection_mode' => $mode ?? $this->protection_mode]);
        }
    }

    /**
     * Get the requests the edge has logged for the domain.
     *
     * @return HasMany<EdgeRequest, $this>
     */
    public function edgeRequests(): HasMany
    {
        return $this->hasMany(EdgeRequest::class);
    }

    /**
     * Limit to the domain the edge serves a hostname as: the domain's own hostname, or its preview.
     *
     * @param  Builder<self>  $query
     */
    public function scopeServedAs(Builder $query, string $hostname): void
    {
        $query->where(fn (Builder $query) => $query->where('hostname', $hostname)->orWhere('preview_hostname', $hostname));
    }

    /**
     * Limit to the domains of their own: not sites of a shared origin. While nothing keeps shared origins
     * (App\Support\SharedOrigins), every domain is one of its own.
     *
     * @param  Builder<self>  $query
     */
    public function scopeStandalone(Builder $query): void
    {
        if (app(SharedOrigins::class)->areKept()) {
            $query->whereNull('origin_id');
        }
    }

    /**
     * Limit to the domains the edge also serves under www.: domains of their own that turned www. on, and
     * the sites a shared origin serves under www. too. Names a shared origin turns away are checked
     * separately (refusesHostname()).
     *
     * @param  Builder<self>  $query
     */
    public function scopeServingWww(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->where(fn (Builder $query) => $query->standalone()->where('serves_www', true));

            app(SharedOrigins::class)->orWhereServesWww($query);
        });
    }

    /**
     * Load, with the domains, what deciding their edge hostnames reads for the sites of shared origins
     * among them (servesWww() and refusesHostname()), for the lot at once instead of a query a site.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithEdgeHostnames(Builder $query): void
    {
        $query->afterQuery(function (mixed $domains): void {
            if ($domains instanceof Collection) {
                app(SharedOrigins::class)->load($domains);
            }
        });
    }

    /**
     * Determine if the edge serves www.<hostname> as this domain: on a site of a shared origin when the
     * origin says so (it folds www, and the site's group serves www.), otherwise when the domain turned
     * www. on. Never for a hostname that already starts with www.
     */
    public function servesWww(): bool
    {
        if (self::withoutWww($this->hostname) !== null) {
            return false;
        }

        return app(SharedOrigins::class)->servesWww($this) ?? $this->serves_www;
    }

    /**
     * Determine if the edge turns a hostname away though it would be served as this domain: a name the
     * domain's shared origin refuses, like a www. name under a group that doesn't serve www. A domain of
     * its own never does.
     */
    public function refusesHostname(string $hostname): bool
    {
        return app(SharedOrigins::class)->refuses($this, $hostname);
    }

    /**
     * Get the DNS name of the TXT record that proves ownership.
     */
    public function verificationRecordName(): string
    {
        return '_nicetrybot.'.$this->hostname;
    }

    /**
     * Get the value of the TXT record that proves ownership.
     */
    public function verificationRecordValue(): string
    {
        return 'nicetrybot-verify='.$this->verification_token;
    }

    /**
     * Get the hostname that subdomains point their CNAME record at (config edge.hostname).
     */
    public static function edgeHostname(): string
    {
        return (string) config('edge.hostname');
    }

    /**
     * Get the static addresses that apex domains point their A records at (config edge.addresses).
     *
     * @return list<string>
     */
    public static function edgeAddresses(): array
    {
        return array_values(array_map(strval(...), (array) config('edge.addresses', [])));
    }

    /**
     * Get the hostname without its leading "www." (every one, for www.www.site), or null when it has none
     * (or would leave a bare TLD).
     */
    public static function withoutWww(string $hostname): ?string
    {
        if (! str_starts_with($hostname, 'www.')) {
            return null;
        }

        $bare = (string) preg_replace('/^(?:www\.)+/', '', $hostname);

        return str_contains($bare, '.') ? $bare : null;
    }

    /**
     * Get the parent domain a hostname is grouped under: its last two labels, like example.com.
     */
    public static function parentDomain(string $hostname): string
    {
        return implode('.', array_slice(explode('.', $hostname), -2));
    }

    /**
     * Get the hostnames the edge serves as this domain: its own, www. too when it serves www.
     * (servesWww()), and its preview.
     *
     * @return list<string>
     */
    public function edgeHostnames(): array
    {
        return array_values(array_filter([
            $this->hostname,
            $this->servesWww() ? 'www.'.$this->hostname : null,
            $this->preview_hostname,
        ]));
    }

    /**
     * Determine if the domain is an apex domain (no subdomain), which cannot use a CNAME record.
     */
    public function isApex(): bool
    {
        return substr_count($this->hostname, '.') === 1;
    }

    /**
     * Get the host header the origin receives for a request to one of the domain's hostnames (its own
     * by default).
     */
    public function originHostHeader(?string $hostname = null): string
    {
        if ($this->host_header_mode === HostHeaderMode::PassThrough) {
            return $hostname ?? $this->hostname;
        }

        return $this->origin_host_header ?: (string) parse_url($this->origin_url, PHP_URL_HOST);
    }

    /**
     * Determine if the edge may serve the domain: ownership verified, and not removed from protection.
     */
    public function isServable(): bool
    {
        return $this->verified_at !== null
            && in_array($this->status, [DomainStatus::Active, DomainStatus::Paused], true);
    }

    /**
     * Get how the edge reaches the domain's origin and dresses requests for it.
     *
     * The first forwarded host header keeps its own key (null falls back to the edge's X-Forwarded-Host);
     * any others come as extras. Pass one of the domain's other edge hostnames (www., or the preview) to
     * route it. The site host is what the origin is told the visitor asked for: a preview passes as the
     * domain itself, so the origin serves it exactly as it will once DNS moves.
     *
     * @return array{host: string, site_host: string, scheme: string, upstream: string, server_name: string, host_header: string, forwarded_host_header: string|null, secret: string, timeout: int, extra_forwarded_host_headers: list<string>, hsts: string|null, cache_assets: bool}
     */
    public function edgeRoute(?string $hostname = null): array
    {
        $hostname ??= $this->hostname;
        $siteHostname = $hostname === $this->preview_hostname ? $this->hostname : $hostname;
        $scheme = (string) parse_url($this->origin_url, PHP_URL_SCHEME);
        $host = (string) parse_url($this->origin_url, PHP_URL_HOST);
        $port = parse_url($this->origin_url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);
        $forwardedHostHeaders = HeaderName::split($this->forwarded_host_header);

        return [
            'host' => $hostname,
            'site_host' => $siteHostname,
            'scheme' => $scheme,
            'upstream' => "{$host}:{$port}",
            'server_name' => trim($host, '[]'),
            'host_header' => $this->originHostHeader($siteHostname),
            'forwarded_host_header' => $forwardedHostHeaders[0] ?? null,
            'secret' => $this->origin_secret,
            'timeout' => $this->origin_timeout_seconds,
            'extra_forwarded_host_headers' => array_slice($forwardedHostHeaders, 1),
            'hsts' => $this->hsts_policy->headerValue(),
            'cache_assets' => $this->cache_assets,
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'hostname';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'host_header_mode' => HostHeaderMode::class,
            'protection_mode' => ProtectionMode::class,
            'status' => DomainStatus::class,
            'origin_timeout_seconds' => 'integer',
            'hsts_policy' => HstsPolicy::class,
            'cache_assets' => 'boolean',
            'serves_www' => 'boolean',
            'verified_at' => 'datetime',
            'edge_dns_pointed_at' => 'datetime',
        ];
    }
}
