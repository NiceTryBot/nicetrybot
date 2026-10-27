<?php

namespace App\Enums;

use App\Data\Ja4Fingerprint;

/**
 * The TLS library a client's handshake came from, told apart by the cipher suites it offers (the
 * JA4 cipher hash). A browser can lie in its user agent, but not about the library it's built on.
 */
enum TlsStack: int
{
    case Unknown = 0;
    case Chromium = 1;
    case Apple = 2;
    case Firefox = 3;
    case Quic = 4;
    case Other = 5;

    /**
     * Read the stack from a JA4 fingerprint.
     *
     * Chrome's cipher list (8daaf6152771) is BoringSSL's, which current Firefox now offers too; every
     * iOS browser and Safari use Apple's (a09f3c656075). QUIC handshakes only offer TLS 1.3's three
     * ciphers, which every stack shares, so they can't be told apart.
     */
    public static function fromJa4(?string $ja4): self
    {
        $fingerprint = Ja4Fingerprint::tryFrom($ja4);

        return match (true) {
            $fingerprint === null => self::Unknown,
            $fingerprint->transport === 'q' => self::Quic,
            $fingerprint->cipherHash === '8daaf6152771' => self::Chromium,
            $fingerprint->cipherHash === 'a09f3c656075' => self::Apple,
            $fingerprint->cipherHash === '5b57614c22b0' => self::Firefox,
            default => self::Other,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unknown => __('an unknown TLS stack'),
            self::Chromium => __("Chrome's TLS"),
            self::Apple => __("Apple's TLS"),
            self::Firefox => __("Firefox's TLS"),
            self::Quic => __('QUIC'),
            self::Other => __('a TLS library no browser uses, like curl, Go or Python'),
        };
    }
}
