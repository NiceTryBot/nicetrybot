<?php

namespace App\Addons;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * A licence key from the marketplace, once its signature holds. A key is `ntb1.<payload>.<signature>`,
 * both parts base64url; the signature is Ed25519 over `ntb1.<payload>`, made with the marketplace's
 * secret key and checked here with its public key (config addons.licensing.public_key), so a key is
 * proven without calling anywhere. The payload is JSON: the licence's id (what the marketplace is
 * asked about), the add-on it's for, who it's licensed to, when it was issued and until when it gets
 * updates.
 */
final readonly class LicenceKey
{
    public const string PREFIX = 'ntb1';

    public function __construct(
        public string $id,
        public string $addon,
        public string $to,
        public CarbonImmutable $issued,
        public CarbonImmutable $updatesUntil,
    ) {
        //
    }

    /**
     * Read a key, proving it first. The public key is the marketplace's, base64 (the config's by default).
     *
     * @throws InvalidLicenceKey
     */
    public static function parse(string $key, ?string $publicKey = null): self
    {
        $publicKey = base64_decode($publicKey ?? (string) config('addons.licensing.public_key'), true);

        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidLicenceKey(__('The marketplace’s public key (config addons.licensing.public_key) isn’t set, so keys can’t be checked.'));
        }

        $parts = explode('.', trim($key));

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX || $parts[1] === '' || $parts[2] === '') {
            throw new InvalidLicenceKey(__('That isn’t a NiceTryBot licence key. One starts with ntb1. and has three parts separated by dots.'));
        }

        $signature = self::decode($parts[2]);

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, $parts[0].'.'.$parts[1], $publicKey)) {
            throw new InvalidLicenceKey(__('The key’s signature doesn’t match. Copy the whole key from your account on the marketplace.'));
        }

        $payload = json_decode(self::decode($parts[1]), true);

        if (! is_array($payload) || ($payload['v'] ?? null) !== 1
            || ! is_string($payload['id'] ?? null) || ! is_string($payload['addon'] ?? null)
            || ! is_string($payload['issued'] ?? null) || ! is_string($payload['updates_until'] ?? null)) {
            throw new InvalidLicenceKey(__('The key’s contents aren’t in a form this version of NiceTryBot understands.'));
        }

        try {
            return new self(
                id: $payload['id'],
                addon: $payload['addon'],
                to: is_string($payload['to'] ?? null) ? $payload['to'] : '',
                issued: CarbonImmutable::parse($payload['issued']),
                updatesUntil: CarbonImmutable::parse($payload['updates_until']),
            );
        } catch (Throwable) {
            throw new InvalidLicenceKey(__('The key’s dates can’t be read.'));
        }
    }

    private static function decode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'), true);
    }
}
