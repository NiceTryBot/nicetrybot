<?php

namespace App\Enums;

/**
 * What a client label names: one exact IP, TLS fingerprint, user agent or set of client hints (what a
 * Chromium browser says it really is, which a bot overriding only its user agent can't hide). Each is labelled on its own,
 * so an IP and the fingerprint seen on it can carry different names.
 */
enum LabelKind: string
{
    case Ip = 'ip';
    case Ja4 = 'ja4';
    case UserAgent = 'ua';
    case ClientHints = 'hints';

    /**
     * Get the kind as people say it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ip => __('IP'),
            self::Ja4 => __('TLS fingerprint'),
            self::UserAgent => __('user agent'),
            self::ClientHints => __('set of client hints'),
        };
    }

    /**
     * Determine if a value is something this kind could label.
     */
    public function accepts(string $value): bool
    {
        return match ($this) {
            self::Ip => filter_var($value, FILTER_VALIDATE_IP) !== false,
            self::Ja4 => preg_match('/^[a-z0-9_]{10,64}$/', $value) === 1,
            self::UserAgent => $value !== '' && mb_strlen($value) <= 512,
            self::ClientHints => $value !== '' && mb_strlen($value) <= 1000,
        };
    }
}
