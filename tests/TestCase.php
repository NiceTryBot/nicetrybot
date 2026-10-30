<?php

namespace Tests;

use App\Actions\Domains\LookUpDnsRecords;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests never ask real DNS: every name resolves to nothing unless a test says otherwise. (Herd, for
     * one, answers every .test name with 127.0.0.1.)
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(LookUpDnsRecords::class, new class extends LookUpDnsRecords
        {
            protected function resolve(string $hostname, int $type, string $field): array
            {
                return [];
            }
        });
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
