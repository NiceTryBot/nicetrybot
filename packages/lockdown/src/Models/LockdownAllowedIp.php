<?php

namespace NiceTry\Lockdown\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An address (or CIDR range) that reaches a team's real sites while its lockdown is engaged.
 *
 * @property int $id
 * @property int $lockdown_id
 * @property string $range A bare IP or a CIDR range, like "203.0.113.4" or "2001:db8::/48".
 * @property string|null $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lockdown $lockdown
 */
#[Fillable(['range', 'label'])]
class LockdownAllowedIp extends Model
{
    /**
     * Get the lockdown the address gets through.
     *
     * @return BelongsTo<Lockdown, $this>
     */
    public function lockdown(): BelongsTo
    {
        return $this->belongsTo(Lockdown::class);
    }

    /**
     * Write an IP or CIDR range the one way the edge and the allowlist expect (compressed IPv6, the
     * network address of a range), or null if it's neither.
     */
    public static function normalize(string $value): ?string
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
