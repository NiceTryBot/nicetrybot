<?php

namespace App\Data;

/**
 * What a domain's origin answered when it was asked for its home page twice, as the edge asks: once
 * without the origin secret header and once with it. A locked origin refuses the first and serves
 * the second, so nobody can reach the app by going around the edge.
 */
readonly class OriginLockCheck
{
    public function __construct(
        public string $originUrl,
        public ?int $statusWithoutSecret,
        public ?int $statusWithSecret,
        public ?string $problem = null,
    ) {
        //
    }

    /**
     * A check that couldn't ask the origin at all.
     */
    public static function failed(string $originUrl, string $problem): self
    {
        return new self($originUrl, null, null, $problem);
    }

    /**
     * Determine if the origin refuses requests that don't come from the edge and serves those that do.
     */
    public function isLocked(): bool
    {
        return $this->refusesWithoutSecret() && $this->servesWithSecret();
    }

    /**
     * Determine if the origin turned away the request without the secret.
     */
    public function refusesWithoutSecret(): bool
    {
        return $this->statusWithoutSecret !== null && $this->statusWithoutSecret >= 400 && $this->statusWithoutSecret < 500;
    }

    /**
     * Determine if the origin answered the request with the secret, as it does a visitor's.
     */
    public function servesWithSecret(): bool
    {
        return $this->statusWithSecret !== null && $this->statusWithSecret < 400;
    }

    /**
     * Explain the result in a sentence.
     */
    public function summary(): string
    {
        if ($this->problem !== null) {
            return $this->problem;
        }

        if ($this->isLocked()) {
            return __('The origin refuses requests without the secret header (:without) and serves the edge\'s (:with). It\'s locked to the edge.', [
                'without' => $this->statusWithoutSecret,
                'with' => $this->statusWithSecret,
            ]);
        }

        if (! $this->servesWithSecret()) {
            return __('The origin answered :status even with the secret header, so it would turn real visitors away too. Fix the origin before pointing DNS at the edge.', [
                'status' => $this->statusWithSecret,
            ]);
        }

        return __('The origin served a request without the secret header (:status), so anyone can go around the edge. Make it refuse requests that lack the header.', [
            'status' => $this->statusWithoutSecret,
        ]);
    }

    /**
     * @return array{origin_url: string, locked: bool, status_without_secret: int|null, status_with_secret: int|null, summary: string}
     */
    public function toArray(): array
    {
        return [
            'origin_url' => $this->originUrl,
            'locked' => $this->isLocked(),
            'status_without_secret' => $this->statusWithoutSecret,
            'status_with_secret' => $this->statusWithSecret,
            'summary' => $this->summary(),
        ];
    }
}
