<?php

use App\Models\Domain;
use App\Models\Team;
use Illuminate\Testing\Fluent\AssertableJson;
use NiceTry\PrivacyMode\Mcp\GetPrivacyMap;
use NiceTry\PrivacyMode\Models\PrivacyWord;

test('the privacy map gives the team\'s hosts their stand-ins and the hidden words theirs, never another team\'s hosts', function () {
    [$token] = apiToken();
    Domain::factory()->for($token->team)->create(['hostname' => 'shop.example', 'privacy_hostname' => 'sneaky-otter.hidden-domain.com']);
    Domain::factory()->for(Team::factory())->create(['hostname' => 'theirs.example']);
    PrivacyWord::factory()->create(['word' => 'umbrella', 'replacement' => 'acme']);

    mcpAs($token)->tool(GetPrivacyMap::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('hostnames', fn ($hostnames) => $hostnames['shop.example'] === 'sneaky-otter.hidden-domain.com')
            ->where('words', ['umbrella' => 'acme'])
            ->etc())
        ->assertDontSee('theirs.example');
});
