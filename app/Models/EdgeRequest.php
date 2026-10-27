<?php

namespace App\Models;

use App\Enums\HttpMethod;
use App\Enums\NotFoundCategory;
use App\Enums\RequestAction;
use App\Enums\TlsFingerprintType;
use Database\Factories\EdgeRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single request seen by the NiceTryBot edge, already redacted before ingest.
 *
 * The table holds only fixed-width values: repeated strings live in lookup tables and closed sets
 * are smallint enums. The string attributes below (path, user_agent, bot_tag, ja4, ja3, country_code)
 * read through those lookups and resolve them when set.
 *
 * @property int $id
 * @property string|null $event_id The edge's id for the log line, which keeps a request the live ingest and the S3 import both carry from being stored twice.
 * @property int $domain_id
 * @property Carbon $occurred_at
 * @property string $client_ip
 * @property HttpMethod $method
 * @property int|null $path_id
 * @property int $status_code
 * @property int $duration_ms
 * @property RequestAction $action
 * @property int|null $user_agent_id
 * @property int|null $bot_tag_id
 * @property int|null $ja4_id
 * @property int|null $ja3_id
 * @property int|null $ja4h_id
 * @property int|null $client_hints_id
 * @property string|null $accept_language
 * @property int|null $asn
 * @property int|null $location_id
 * @property string $path
 * @property string|null $user_agent
 * @property string|null $bot_tag
 * @property string|null $ja4
 * @property string|null $ja3
 * @property string|null $ja4h A JA4H-style header fingerprint (header names hashed sorted: Go doesn't keep their order).
 * @property string|null $client_hints "<Sec-CH-UA> | <Sec-CH-UA-Platform>".
 * @property string|null $country_code
 * @property-read Domain $domain
 * @property-read RequestPath|null $requestPath
 * @property-read UserAgent|null $userAgentRecord
 * @property-read BotTag|null $botTagRecord
 * @property-read TlsFingerprint|null $ja4Fingerprint
 * @property-read TlsFingerprint|null $ja3Fingerprint
 * @property-read TlsFingerprint|null $ja4hFingerprint
 * @property-read ClientHint|null $clientHintRecord
 * @property-read Network|null $network
 * @property-read Location|null $location
 */
#[Fillable([
    'occurred_at',
    'client_ip',
    'method',
    'status_code',
    'duration_ms',
    'action',
    'path_id',
    'user_agent_id',
    'bot_tag_id',
    'ja4_id',
    'ja3_id',
    'ja4h_id',
    'client_hints_id',
    'accept_language',
    'asn',
    'location_id',
    'path',
    'user_agent',
    'bot_tag',
    'ja4',
    'ja3',
    'ja4h',
    'client_hints',
    'country_code',
])]
class EdgeRequest extends Model
{
    /** @use HasFactory<EdgeRequestFactory> */
    use HasFactory;

    /**
     * When the edge began logging requests' client hints (UTC). A request from before then has none on
     * record whatever its browser sent.
     */
    public const string CLIENT_HINTS_SINCE = '2026-10-08 15:19:25';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The relationships that should always be loaded, so the string attributes never query per row.
     *
     * @var list<string>
     */
    protected $with = [
        'requestPath',
        'userAgentRecord',
        'botTagRecord',
        'ja4Fingerprint',
        'ja3Fingerprint',
        'network',
        'location',
    ];

    /**
     * Get the domain the request was sent to.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return BelongsTo<RequestPath, $this>
     */
    public function requestPath(): BelongsTo
    {
        return $this->belongsTo(RequestPath::class, 'path_id');
    }

    /**
     * @return BelongsTo<UserAgent, $this>
     */
    public function userAgentRecord(): BelongsTo
    {
        return $this->belongsTo(UserAgent::class, 'user_agent_id');
    }

    /**
     * @return BelongsTo<BotTag, $this>
     */
    public function botTagRecord(): BelongsTo
    {
        return $this->belongsTo(BotTag::class, 'bot_tag_id');
    }

    /**
     * @return BelongsTo<TlsFingerprint, $this>
     */
    public function ja4Fingerprint(): BelongsTo
    {
        return $this->belongsTo(TlsFingerprint::class, 'ja4_id');
    }

    /**
     * Get the request's header fingerprint.
     *
     * @return BelongsTo<TlsFingerprint, $this>
     */
    public function ja4hFingerprint(): BelongsTo
    {
        return $this->belongsTo(TlsFingerprint::class, 'ja4h_id');
    }

    /**
     * Get the client hints the request sent.
     *
     * @return BelongsTo<ClientHint, $this>
     */
    public function clientHintRecord(): BelongsTo
    {
        return $this->belongsTo(ClientHint::class, 'client_hints_id');
    }

    /**
     * @return BelongsTo<TlsFingerprint, $this>
     */
    public function ja3Fingerprint(): BelongsTo
    {
        return $this->belongsTo(TlsFingerprint::class, 'ja3_id');
    }

    /**
     * Get the network (ISP or hosting provider) the client IP belongs to.
     *
     * @return BelongsTo<Network, $this>
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class, 'asn', 'asn');
    }

    /**
     * Get where the client IP geolocates to.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Determine if the request returned a 404.
     */
    public function isNotFound(): bool
    {
        return $this->status_code === 404;
    }

    /**
     * Get the 404 category of the request, if it returned a 404.
     */
    public function notFoundCategory(): ?NotFoundCategory
    {
        return $this->isNotFound() ? ($this->requestPath?->category?->notFoundCategory() ?? NotFoundCategory::MissingPage) : null;
    }

    /**
     * The redacted request path.
     */
    protected function path(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->requestPath?->path,
            set: fn (?string $value) => ['path_id' => $value === null ? null : RequestPath::idFor($value)],
        );
    }

    /**
     * The User-Agent header as sent.
     */
    protected function userAgent(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->userAgentRecord?->value,
            set: fn (?string $value) => ['user_agent_id' => blank($value) ? null : UserAgent::idFor($value)],
        );
    }

    /**
     * The name of the edge rule that tagged the request.
     */
    protected function botTag(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->botTagRecord?->name,
            set: fn (?string $value) => ['bot_tag_id' => blank($value) ? null : BotTag::idFor($value)],
        );
    }

    /**
     * The JA4 TLS client fingerprint.
     */
    protected function ja4h(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ja4hFingerprint?->value,
            set: fn (?string $value) => ['ja4h_id' => blank($value) ? null : TlsFingerprint::idFor(TlsFingerprintType::Ja4h, $value)],
        );
    }

    protected function clientHints(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->clientHintRecord?->value,
            set: fn (?string $value) => ['client_hints_id' => blank($value) ? null : ClientHint::idFor($value)],
        );
    }

    protected function ja4(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ja4Fingerprint?->value,
            set: fn (?string $value) => ['ja4_id' => blank($value) ? null : TlsFingerprint::idFor(TlsFingerprintType::Ja4, $value)],
        );
    }

    /**
     * The JA3 TLS client fingerprint.
     */
    protected function ja3(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ja3Fingerprint?->value,
            set: fn (?string $value) => ['ja3_id' => blank($value) ? null : TlsFingerprint::idFor(TlsFingerprintType::Ja3, $value)],
        );
    }

    /**
     * The ISO country the client IP geolocates to.
     */
    protected function countryCode(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->location?->country_code,
            set: fn (?string $value) => ['location_id' => blank($value) ? null : Location::idFor($value)],
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'method' => HttpMethod::class,
            'status_code' => 'integer',
            'duration_ms' => 'integer',
            'action' => RequestAction::class,
            'asn' => 'integer',
        ];
    }
}
