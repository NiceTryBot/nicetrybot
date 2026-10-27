<?php

namespace Database\Factories;

use App\Enums\HttpMethod;
use App\Enums\NetworkKind;
use App\Enums\RequestAction;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\Location;
use App\Models\Network;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EdgeRequest>
 */
class EdgeRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'occurred_at' => now()->subMinutes(fake()->numberBetween(1, 600)),
            'client_ip' => '203.0.113.'.fake()->numberBetween(1, 254),
            'country_code' => 'US',
            'method' => HttpMethod::Get,
            'path' => '/'.fake()->slug(2),
            'status_code' => 200,
            'duration_ms' => fake()->numberBetween(20, 400),
            'user_agent' => fake()->userAgent(),
            'action' => RequestAction::Passed,
            'bot_tag' => null,
        ];
    }

    /**
     * Indicate that the request returned a 404.
     */
    public function notFound(string $path = '/missing-page'): static
    {
        return $this->state(fn (array $attributes) => [
            'path' => $path,
            'status_code' => 404,
        ]);
    }

    /**
     * Indicate that the client IP belongs to the given network.
     */
    public function fromNetwork(int $asn = 16276, string $name = 'OVH SAS', NetworkKind $kind = NetworkKind::Hosting): static
    {
        return $this->state(function (array $attributes) use ($asn, $name, $kind) {
            Network::query()->firstOrCreate(['asn' => $asn], ['name' => $name, 'kind' => $kind]);

            return ['asn' => $asn];
        });
    }

    /**
     * Indicate that the client IP geolocates to the given place.
     */
    public function locatedIn(string $countryCode = 'CA', string $region = 'Quebec', string $city = 'Montreal'): static
    {
        return $this->state(fn (array $attributes) => [
            'location_id' => Location::idFor($countryCode, $region, $city),
        ]);
    }

    /**
     * Indicate that the request arrived over TLS with the given client fingerprints.
     */
    public function withFingerprint(string $ja4 = 't13d1518h2_8daaf6152771_e2d80978ab2e', string $ja3 = 'd951d8e88419f959665fd90aaaeed0d5'): static
    {
        return $this->state(fn (array $attributes) => [
            'ja4' => $ja4,
            'ja3' => $ja3,
        ]);
    }

    /**
     * Indicate that the request was a tagged scanner probe.
     */
    /**
     * Sent by a client with this user agent, and this JA4 fingerprint and set of client hints if given.
     */
    public function fromClient(string $userAgent, ?string $ja4 = null, ?string $clientHints = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_agent' => $userAgent,
            'ja4' => $ja4,
            'client_hints' => $clientHints,
        ]);
    }

    /**
     * Sent by a genuine Chrome on Windows: Chrome's TLS, with client hints that agree with its user agent.
     */
    public function genuineChrome(int $majorVersion = 155): static
    {
        return $this->fromClient(
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{$majorVersion}.0.0.0 Safari/537.36",
            't13d1517h2_8daaf6152771_cb7bf5808d99',
            "\"Google Chrome\";v=\"{$majorVersion}\", \"Chromium\";v=\"{$majorVersion}\", \"Not(A:Brand\";v=\"24\" | \"Windows\"",
        );
    }

    public function probe(RequestAction $action = RequestAction::Observed): static
    {
        return $this->notFound('/.env')->state(fn (array $attributes) => [
            'user_agent' => 'python-requests/2.32.3',
            'action' => $action,
            'bot_tag' => 'dotfile-probe',
        ]);
    }
}
