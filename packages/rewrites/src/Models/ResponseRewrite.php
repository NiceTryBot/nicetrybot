<?php

namespace NiceTry\Rewrites\Models;

use App\Models\Domain;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use NiceTry\Rewrites\Database\Factories\ResponseRewriteFactory;
use NiceTry\Rewrites\Enums\RewriteContent;

/**
 * A team's response rewrite: on every domain of the team (no domain) or on one, the edge turns each
 * `find` in the responses it reads (HTML pages, or any text, and their headers when asked) into `replace`
 * before the visitor sees it.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $domain_id Null when it covers every domain of the team.
 * @property string $find
 * @property string $replace
 * @property bool $ignore_case
 * @property RewriteContent $content
 * @property bool $in_headers Whether it changes the response's header values too.
 * @property bool $enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Team $team
 * @property-read Domain|null $domain
 */
#[Fillable([
    'team_id',
    'domain_id',
    'find',
    'replace',
    'ignore_case',
    'content',
    'in_headers',
    'enabled',
])]
class ResponseRewrite extends Model
{
    /** @use HasFactory<ResponseRewriteFactory> */
    use HasFactory;

    /**
     * Where the edge's list is kept between polls.
     */
    public const string FEED_CACHE_KEY = 'rewrites:feed';

    protected static function newFactory(): ResponseRewriteFactory
    {
        return ResponseRewriteFactory::new();
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::forgetFeed());
        static::deleted(fn () => self::forgetFeed());
    }

    /**
     * Drop the edge's cached list, so a change reaches the edge on its next poll.
     */
    public static function forgetFeed(): void
    {
        Cache::forget(self::FEED_CACHE_KEY);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The one domain the rewrite covers, when it doesn't cover them all.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * The rules for a rewrite's editor (find, replace, content), as both the Rewrites page and a domain's
     * Rewrites panel check them.
     *
     * @return array{rules: array<string, list<mixed>>, messages: array<string, string>}
     */
    public static function editorRules(): array
    {
        return [
            'rules' => [
                'find' => ['required', 'string', 'max:1000'],
                'replace' => ['present', 'string', 'max:10000', 'different:find'],
                'content' => [Rule::enum(RewriteContent::class)],
            ],
            'messages' => [
                'replace.different' => __('It would come out the same.'),
            ],
        ];
    }

    /**
     * Determine if the rewrite covers every domain of the team.
     */
    public function isTeamWide(): bool
    {
        return $this->domain_id === null;
    }

    protected function casts(): array
    {
        return [
            'ignore_case' => 'boolean',
            'content' => RewriteContent::class,
            'in_headers' => 'boolean',
            'enabled' => 'boolean',
        ];
    }
}
