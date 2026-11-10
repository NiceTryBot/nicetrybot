<?php

namespace NiceTry\WhiteList\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use NiceTry\WhiteList\Database\Factories\WhitelistEntryFactory;

/**
 * One named list of addresses that never meet an enforcement. Its ranges are kept normalised — a bare
 * IP, or a CIDR range on its network address with IPv6 compressed — the one way the matcher
 * (App\Support\AllowedAddresses) and the edge expect.
 *
 * @property int $id
 * @property string $name
 * @property string $category A category written X/Y, like "bot/crawler" or "office/vpn".
 * @property list<string> $ranges
 * @property bool $enabled
 * @property string|null $reason
 * @property int|null $added_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'category', 'ranges', 'enabled', 'reason', 'added_by_id'])]
class WhitelistEntry extends Model
{
    /** @use HasFactory<WhitelistEntryFactory> */
    use HasFactory;

    /**
     * The shared cache the gathered allow ranges are kept under, so a change drops the whole list.
     */
    public const string RANGES_CACHE_KEY = 'white-list:ranges';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ranges' => 'array',
            'enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $forget = fn () => cache()->forget(self::RANGES_CACHE_KEY);

        static::saved($forget);
        static::deleted($forget);
    }

    protected static function newFactory(): WhitelistEntryFactory
    {
        return WhitelistEntryFactory::new();
    }

    /**
     * Only the entries that are on.
     *
     * @param  Builder<WhitelistEntry>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * Who added the entry, if they're still a user.
     *
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }

    /**
     * Turn pasted text (IPs and CIDR ranges, one per line or comma-separated) into a normalised,
     * deduplicated list, dropping anything that isn't an address or range.
     *
     * @return list<string>
     */
    public static function parseRanges(string $text): array
    {
        return collect(preg_split('/[\s,]+/', trim($text)) ?: [])
            ->map(fn (string $value) => self::normalizeRange($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Write one IP or CIDR range the one way the matcher and the edge expect (compressed IPv6, the
     * network address of a range), or null if it's neither.
     */
    public static function normalizeRange(string $value): ?string
    {
        [$address, $bits] = array_pad(explode('/', trim($value), 2), 2, null);
        $packed = filter_var($address, FILTER_VALIDATE_IP) !== false ? inet_pton($address) : false;

        if ($packed === false) {
            return null;
        }

        if ($bits === null) {
            return inet_ntop($packed) ?: null;
        }

        $maxBits = strlen($packed) * 8;

        if (! ctype_digit($bits) || (int) $bits > $maxBits) {
            return null;
        }

        $mask = str_repeat("\xff", intdiv((int) $bits, 8))
            .((int) $bits % 8 ? chr((0xFF << (8 - (int) $bits % 8)) & 0xFF) : '');
        $mask = str_pad($mask, strlen($packed), "\x00");

        $network = inet_ntop($packed & $mask);

        return $network === false ? null : $network.'/'.(int) $bits;
    }
}
