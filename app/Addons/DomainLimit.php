<?php

namespace App\Addons;

use App\Models\Domain;
use App\Models\DomainPack;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * How many domains this installation may protect: the free 10 (config nicetry.domain_limit), plus 100
 * for each More domains pack (a key from the marketplace, pasted in Admin → Add-ons). Packs stack. A
 * hostname behind an Origins origin doesn't count, since Origins is for exactly that. It's a simple,
 * honest limit: keys are proven with the marketplace's public key and checked daily, nothing more.
 *
 * With no limit configured (NICETRY_DOMAIN_LIMIT=unlimited, as the hosted service runs), there's none.
 */
class DomainLimit
{
    public const int PACK_SIZE = 100;

    /** The add-on slug a pack's licence key names. */
    public const string PACK = 'more-domains';

    public function __construct(private Licences $licences)
    {
        //
    }

    /**
     * The domains this installation gets without packs, or null for no limit.
     */
    public function free(): ?int
    {
        $limit = config('nicetry.domain_limit');

        return is_int($limit) ? $limit : null;
    }

    public function packs(): int
    {
        return DomainPack::query()->valid()->count();
    }

    /**
     * The domains this installation may protect, or null for no limit.
     */
    public function allowed(): ?int
    {
        $free = $this->free();

        return $free === null ? null : $free + self::PACK_SIZE * $this->packs();
    }

    /**
     * The domains that count: every domain except the hostnames behind an Origins origin.
     */
    public function used(): int
    {
        return Domain::query()->whereNull('origin_id')->count();
    }

    public function reached(): bool
    {
        $allowed = $this->allowed();

        return $allowed !== null && $this->used() >= $allowed;
    }

    /**
     * Refuse another domain when the limit's reached, with what to do about it.
     *
     * @throws ValidationException
     */
    public function ensureRoom(string $field = 'hostname'): void
    {
        if (! $this->reached()) {
            return;
        }

        throw ValidationException::withMessages([$field => __('This installation protects :allowed domains, its limit. Each More domains pack adds :size more (:url); an administrator adds its key in Admin → Add-ons.', [
            'allowed' => $this->allowed(),
            'size' => self::PACK_SIZE,
            'url' => self::buyUrl(),
        ])]);
    }

    /**
     * Add a pack: prove its key, make sure it's a More domains key not already here, keep it, and ask
     * the marketplace about it (if it can't be reached, the pack counts until the daily check).
     *
     * @throws InvalidLicenceKey
     */
    public function enter(string $key, ?User $by = null): DomainPack
    {
        $parsed = LicenceKey::parse($key);

        if ($parsed->addon !== self::PACK) {
            throw new InvalidLicenceKey(__('That key is for :for, not a More domains pack. Add-on keys go on the add-on itself, below.', ['for' => $parsed->addon]));
        }

        if (DomainPack::query()->where('licence_id', $parsed->id)->exists()) {
            throw new InvalidLicenceKey(__('That pack is already added. Each pack has its own key.'));
        }

        $pack = DomainPack::query()->create([
            'licence_id' => $parsed->id,
            'key' => trim($key),
            'licensed_to' => $parsed->to ?: null,
            'issued_on' => $parsed->issued,
            'status' => DomainPack::VALID,
            'entered_by_id' => $by?->id,
        ]);

        $this->check($pack);

        return $pack;
    }

    /**
     * Ask the marketplace whether a pack is still good (a refund or chargeback revokes it). Returns
     * whether it answered; when it didn't, the last answer stands.
     */
    public function check(DomainPack $pack): bool
    {
        $answer = $this->licences->ask($pack->licence_id);

        if ($answer === null) {
            return false;
        }

        $pack->update([
            'status' => $answer['status'] === 'revoked' ? DomainPack::REVOKED : DomainPack::VALID,
            'checked_at' => now(),
        ]);

        return true;
    }

    /**
     * Where a pack is bought.
     */
    public static function buyUrl(): string
    {
        return rtrim((string) config('addons.marketplace.url'), '/').'/marketplace/'.self::PACK;
    }
}
