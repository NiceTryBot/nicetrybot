<?php

namespace App\Data;

use App\Enums\PathCategory;
use App\Models\EdgeRequest;

/**
 * What the gym knows about a visitor when deciding which routines they enter: the request that
 * brought them, where it came from, and which rules hold an active verdict on them.
 */
readonly class GymVisitor
{
    /**
     * @param  list<int>  $verdictRuleIds  Rules with an active verdict on the visitor's IP or TLS fingerprint.
     * @param  string|null  $knownJa4  The fingerprint the visitor's gym session recorded, which a browser's
     *                                 resumed TLS connections change: verdicts on it still count.
     */
    public function __construct(
        public string $ip,
        public ?string $host = null,
        public ?string $path = null,
        public ?string $userAgent = null,
        public ?string $ja4 = null,
        public ?string $countryCode = null,
        public ?int $asn = null,
        public ?string $tag = null,
        public array $verdictRuleIds = [],
        public ?string $knownJa4 = null,
    ) {
        //
    }

    /**
     * Describe the visitor behind a logged edge request.
     *
     * @param  list<int>  $verdictRuleIds
     */
    public static function fromEdgeRequest(EdgeRequest $request, array $verdictRuleIds = []): self
    {
        return new self(
            ip: $request->client_ip,
            host: $request->domain->hostname,
            path: $request->path,
            userAgent: $request->user_agent,
            ja4: $request->ja4,
            countryCode: $request->country_code,
            asn: $request->asn,
            tag: $request->bot_tag,
            verdictRuleIds: $verdictRuleIds,
        );
    }

    /**
     * Get a copy that knows which rules hold a verdict on the visitor.
     *
     * @param  list<int>  $ruleIds
     */
    public function withVerdicts(array $ruleIds): self
    {
        return new self($this->ip, $this->host, $this->path, $this->userAgent, $this->ja4, $this->countryCode, $this->asn, $this->tag, $ruleIds, $this->knownJa4);
    }

    /**
     * Get the visitor's TLS fingerprints: the one this request came with, and the one their session
     * recorded when it differs.
     *
     * @return list<string>
     */
    public function ja4s(): array
    {
        return array_values(array_unique(array_filter([$this->ja4, $this->knownJa4])));
    }

    /**
     * Get the category of the path the visitor asked for.
     */
    public function category(): ?PathCategory
    {
        return $this->path === null ? null : PathCategory::classify($this->path);
    }
}
