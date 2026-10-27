<?php

namespace App\Data;

/**
 * A JA4 TLS client fingerprint, e.g. t13d1518h2_8daaf6152771_e2d80978ab2e.
 *
 * The first section is readable: transport, TLS version, whether SNI was a domain or an IP, the
 * cipher and extension counts, and the first ALPN value offered. The two hashes identify the exact
 * cipher list and extension set. Unlike JA3, JA4 sorts extensions before hashing, so a browser that
 * shuffles its extension order (Chrome does, per connection) keeps one JA4.
 */
readonly class Ja4Fingerprint
{
    /**
     * The shape of a JA4 TLS client fingerprint.
     */
    public const string PATTERN = '/^([tqd])(1[0-3]|s[23]|d[1-3]|00)([di])(\d{2})(\d{2})([a-z0-9]{2})_([0-9a-f]{12})_([0-9a-f]{12})$/';

    public function __construct(
        public string $value,
        public string $transport,
        public string $version,
        public bool $sniIsDomain,
        public int $cipherCount,
        public int $extensionCount,
        public string $alpn,
        public string $cipherHash,
        public string $extensionHash,
    ) {
        //
    }

    /**
     * Parse a JA4 fingerprint, or return null when the value isn't one.
     */
    public static function tryFrom(?string $value): ?self
    {
        $value = strtolower(trim((string) $value));

        if (preg_match(self::PATTERN, $value, $parts) !== 1) {
            return null;
        }

        return new self(
            value: $value,
            transport: $parts[1],
            version: $parts[2],
            sniIsDomain: $parts[3] === 'd',
            cipherCount: (int) $parts[4],
            extensionCount: (int) $parts[5],
            alpn: $parts[6],
            cipherHash: $parts[7],
            extensionHash: $parts[8],
        );
    }

    /**
     * Determine if the value is a JA4 fingerprint.
     */
    public static function isValid(?string $value): bool
    {
        return self::tryFrom($value) !== null;
    }

    /**
     * Get the readable first section, e.g. "t13d1518h2".
     */
    public function prefix(): string
    {
        return strstr($this->value, '_', before_needle: true);
    }

    /**
     * Determine if the client offered an application protocol. Every current browser offers HTTP/2 or
     * HTTP/3 over HTTPS, so a client that claims to be a browser but offers none is usually a script.
     */
    public function offersAlpn(): bool
    {
        return $this->alpn !== '00';
    }

    /**
     * Get a one-line, human description of the readable section.
     */
    public function summary(): string
    {
        return implode(' · ', [
            $this->protocolLabel(),
            trans_choice(':count cipher|:count ciphers', $this->cipherCount),
            trans_choice(':count extension|:count extensions', $this->extensionCount),
            $this->alpnLabel(),
        ]);
    }

    /**
     * Get the transport and TLS version, e.g. "TLS 1.3" or "QUIC · TLS 1.3".
     */
    private function protocolLabel(): string
    {
        $version = match ($this->version) {
            '13' => 'TLS 1.3',
            '12' => 'TLS 1.2',
            '11' => 'TLS 1.1',
            '10' => 'TLS 1.0',
            's3' => 'SSL 3.0',
            's2' => 'SSL 2.0',
            'd1' => 'DTLS 1.0',
            'd2' => 'DTLS 1.2',
            'd3' => 'DTLS 1.3',
            default => __('unknown TLS version'),
        };

        return match ($this->transport) {
            'q' => 'QUIC · '.$version,
            default => $version,
        };
    }

    /**
     * Describe the first ALPN value offered.
     */
    private function alpnLabel(): string
    {
        return match ($this->alpn) {
            '00' => __('offers no ALPN'),
            'h2' => __('offers HTTP/2'),
            'h3' => __('offers HTTP/3'),
            'h1' => __('offers HTTP/1.1'),
            default => __('offers ALPN :alpn', ['alpn' => $this->alpn]),
        };
    }
}
