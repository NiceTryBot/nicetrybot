<?php

namespace App\Gym;

use Illuminate\Support\Number;

/**
 * The proof-of-work primitive the gym's CPU obstacles share, identical to LN's PowSign and waiting
 * room: find a nonce so that sha256("<challenge>:<nonce>") starts with enough zero bits. The browser
 * side lives in resources/views/gym/partials/pow-core.blade.php; a test pins the two together.
 */
class HashPuzzle
{
    /**
     * Rough browser hash rates (single-block SHA-256 in a worker) that time estimates assume.
     */
    public const int LAPTOP_HASHES_PER_SECOND = 1_500_000;

    public const int PHONE_HASHES_PER_SECOND = 400_000;

    /**
     * Issue a fresh, single-use challenge.
     */
    public static function challenge(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Determine if a nonce solves a challenge at a difficulty.
     */
    public static function verify(string $challenge, string $nonce, int $bits): bool
    {
        if ($challenge === '' || $nonce === '' || strlen($nonce) > 32 || ! ctype_alnum($nonce)) {
            return false;
        }

        return self::leadingZeroBits(hash('sha256', $challenge.':'.$nonce, true)) >= $bits;
    }

    /**
     * Count the zero bits a raw digest starts with.
     */
    public static function leadingZeroBits(string $digest): int
    {
        $bits = 0;

        foreach (str_split($digest) as $character) {
            $byte = ord($character);

            if ($byte === 0) {
                $bits += 8;

                continue;
            }

            for ($mask = 0x80; ($byte & $mask) === 0; $mask >>= 1) {
                $bits++;
            }

            return $bits;
        }

        return $bits;
    }

    /**
     * Get the hashes a difficulty takes on average.
     */
    public static function expectedHashes(int $bits): int
    {
        return 2 ** $bits;
    }

    /**
     * Say a duration compactly, e.g. "0.2 s", "12 s" or "1 min 20 s".
     */
    public static function describeSeconds(float $seconds): string
    {
        return match (true) {
            $seconds < 1 => Number::format($seconds, maxPrecision: 1).' s',
            $seconds < 60 => Number::format($seconds, maxPrecision: $seconds < 10 ? 1 : 0).' s',
            default => intdiv((int) round($seconds), 60).' min'.((int) round($seconds) % 60 === 0 ? '' : ' '.((int) round($seconds) % 60).' s'),
        };
    }
}
