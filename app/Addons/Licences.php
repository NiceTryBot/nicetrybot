<?php

namespace App\Addons;

use App\Models\AddonLicence;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The licences this installation holds for its paid add-ons, and what follows from them. A paid
 * add-on needs a key from the marketplace (one per installation, good for ever, a year of updates).
 * A key is proven here with the marketplace's public key, then the marketplace is asked about it
 * once a day (addons:check-licences), in case it was revoked. Without a valid key a paid add-on runs
 * for a trial from the first request that saw it, then goes off until a key is entered, as though an
 * administrator had turned it off; the switchboard (AddonSwitchboard) asks here.
 *
 * Keys aren't enforced in the local and testing environments unless config says so, so a copy on a
 * laptop runs every add-on. The rows are read once per request from the shared cache, as the
 * switches are, so a key entered on one server counts on every other.
 */
#[Scoped]
class Licences
{
    public const string CACHE_KEY = 'addons:licences';

    /**
     * @var array<string, AddonLicence>|null
     */
    private ?array $rows = null;

    /**
     * @var list<string>|null
     */
    private ?array $unlicensed = null;

    public function __construct(private Addons $addons)
    {
        //
    }

    /**
     * Determine if a paid add-on without a valid licence goes off here.
     */
    public function enforced(): bool
    {
        return (bool) (config('addons.licensing.enforce') ?? ! app()->environment('local', 'testing'));
    }

    /**
     * The slugs of the paid add-ons that are off for want of a licence: revoked, or unlicensed past
     * the trial. Empty where keys aren't enforced.
     *
     * @return list<string>
     */
    public function unlicensed(): array
    {
        if (! $this->enforced()) {
            return [];
        }

        return $this->unlicensed ??= array_values(array_map(
            fn (Addon $addon): string => $addon->slug,
            array_filter($this->addons->all(), fn (Addon $addon): bool => ! $this->state($addon)->allowsRunning()),
        ));
    }

    /**
     * Where an installed add-on stands. Seeing a paid add-on without a key for the first time starts
     * its trial, where keys are enforced.
     */
    public function state(Addon $addon): LicenceState
    {
        if (! $addon->paid) {
            return LicenceState::Free;
        }

        $licence = $this->find($addon->slug);

        if ($licence?->isValid()) {
            return LicenceState::Licensed;
        }

        if ($licence?->isRevoked()) {
            return LicenceState::Revoked;
        }

        if (! $this->enforced()) {
            return LicenceState::Trial;
        }

        try {
            $licence ??= $this->firstSeen($addon);
        } catch (QueryException) {
            // Before the table's migrated: the trial starts with the first request after it is.
            return LicenceState::Trial;
        }

        return $licence->trialEndsAt()?->isPast() ? LicenceState::TrialEnded : LicenceState::Trial;
    }

    /**
     * When an add-on's trial ends, where keys are enforced and it has been seen.
     */
    public function trialEndsAt(Addon $addon): ?CarbonInterface
    {
        return $this->enforced() ? $this->find($addon->slug)?->trialEndsAt() : null;
    }

    public function find(string $slug): ?AddonLicence
    {
        return $this->rows()[$slug] ?? null;
    }

    /**
     * Enter a key for an add-on: prove it, make sure it's for this add-on, keep it, and ask the
     * marketplace about it (if it can't be reached, the key stands until the daily check).
     *
     * @throws InvalidLicenceKey
     */
    public function enter(Addon $addon, string $key, ?User $by = null): AddonLicence
    {
        $parsed = LicenceKey::parse($key);

        if ($parsed->addon !== $addon->slug) {
            $for = $this->addons->find($parsed->addon)?->name ?? $parsed->addon;

            throw new InvalidLicenceKey(__('That key is for :for, not :name.', ['for' => $for, 'name' => $addon->name]));
        }

        $licence = AddonLicence::query()->firstOrNew(['slug' => $addon->slug], ['first_seen_at' => now()]);
        $licence->fill([
            'key' => trim($key),
            'licence_id' => $parsed->id,
            'licensed_to' => $parsed->to,
            'issued_on' => $parsed->issued,
            'updates_until' => $parsed->updatesUntil,
            'status' => AddonLicence::VALID,
            'checked_at' => null,
            'entered_by_id' => $by?->id,
        ]);
        $licence->save();

        $this->check($licence);

        return $licence;
    }

    /**
     * Ask the marketplace whether a licence is still good, and keep its answer. Returns whether it
     * answered; when it can't be reached, or answers something else, the last answer stands.
     */
    public function check(AddonLicence $licence): bool
    {
        $answer = $licence->licence_id === null ? null : $this->ask($licence->licence_id);

        if ($answer === null) {
            return false;
        }

        $licence->update([
            'status' => $answer['status'] === 'revoked' ? AddonLicence::REVOKED : AddonLicence::VALID,
            'updates_until' => $answer['updates_until'] ?? $licence->updates_until,
            'checked_at' => now(),
        ]);

        return true;
    }

    /**
     * What the marketplace says about a licence: valid or revoked, and until when it gets updates. Null
     * when it can't be reached or answers anything else, so the last answer stands.
     *
     * @return array{status: 'valid'|'revoked', updates_until: string|null}|null
     */
    public function ask(string $licenceId): ?array
    {
        try {
            $response = Http::timeout(5)->acceptJson()->get(config('addons.marketplace.url').'/licences/'.$licenceId);
        } catch (ConnectionException) {
            return null;
        }

        // Only a plain answer counts. A 404, an error page or a server that isn't the marketplace
        // (the hostname pointing elsewhere) leaves the last answer standing; revoking is explicit.
        $status = $response->json('status');

        if (! $response->successful() || ! in_array($status, ['valid', 'revoked'], true)) {
            return null;
        }

        $updatesUntil = $response->json('updates_until');

        return ['status' => $status, 'updates_until' => is_string($updatesUntil) ? $updatesUntil : null];
    }

    /**
     * Forget an add-on's key. Its trial doesn't start over.
     */
    public function remove(Addon $addon): void
    {
        AddonLicence::query()->where('slug', $addon->slug)->first()?->update([
            'key' => null,
            'licence_id' => null,
            'licensed_to' => null,
            'issued_on' => null,
            'updates_until' => null,
            'status' => AddonLicence::MISSING,
            'checked_at' => null,
            'entered_by_id' => null,
        ]);
    }

    /**
     * Read the licences again, on this server and every other, after one changed.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->rows = null;
        $this->unlicensed = null;
    }

    /**
     * Note that a paid add-on was met without a key, which starts its trial.
     */
    private function firstSeen(Addon $addon): AddonLicence
    {
        return AddonLicence::query()->firstOrCreate(['slug' => $addon->slug], [
            'status' => AddonLicence::MISSING,
            'first_seen_at' => now(),
        ]);
    }

    /**
     * @return array<string, AddonLicence>
     */
    private function rows(): array
    {
        return $this->rows ??= $this->load();
    }

    /**
     * @return array<string, AddonLicence>
     */
    private function load(): array
    {
        try {
            /** @var array<string, array<string, mixed>> $rows */
            $rows = Cache::rememberForever(self::CACHE_KEY, fn (): array => AddonLicence::query()
                ->orderBy('slug')
                ->get()
                ->mapWithKeys(fn (AddonLicence $licence): array => [$licence->slug => $licence->getAttributes()])
                ->all());
        } catch (QueryException) {
            // Before the table's migrated (a fresh install, or mid-deploy): nothing's licensed yet, and nothing's off for it.
            return [];
        }

        return array_map(fn (array $attributes): AddonLicence => (new AddonLicence)->newFromBuilder($attributes), $rows);
    }
}
