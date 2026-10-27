<?php

namespace Database\Seeders;

use App\Actions\Domains\GenerateSampleTraffic;
use App\Enums\HostHeaderMode;
use App\Enums\ProtectionMode;
use App\Gym\RoutineTemplates;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoWorkspaceSeeder extends Seeder
{
    /**
     * Give the test user's team a few domains with a month of fictional traffic, and a gym with the
     * three starter routines.
     */
    public function run(GenerateSampleTraffic $generateSampleTraffic): void
    {
        $team = User::where('email', 'test@example.com')->firstOrFail()->currentTeam;

        $enforcing = Domain::factory()->for($team)->verified()->enforcing()->create([
            'hostname' => 'claims.example.com',
            'origin_url' => 'https://sites.example.net',
            'host_header_mode' => HostHeaderMode::Rewrite,
            'origin_host_header' => 'sites.example.net',
            'forwarded_host_header' => 'User-Custom-Domain',
        ]);

        $observing = Domain::factory()->for($team)->verified()->create([
            'hostname' => 'notice.example.org',
            'origin_url' => 'https://notice-origin.example.org',
            'protection_mode' => ProtectionMode::Observe,
        ]);

        Domain::factory()->for($team)->create([
            'hostname' => 'example.net',
            'origin_url' => 'https://app.example.net',
        ]);

        $generateSampleTraffic->handle($enforcing, days: 30);
        $generateSampleTraffic->handle($observing, days: 30);

        foreach (['not-a-browser', 'datacenter-waiting-room', 'probe-scanners'] as $index => $template) {
            $team->gymRoutines()->create([...RoutineTemplates::attributes($template), 'position' => $index + 1]);
        }
    }
}
