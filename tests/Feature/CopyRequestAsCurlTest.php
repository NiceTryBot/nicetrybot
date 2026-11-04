<?php

use App\Enums\HttpMethod;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\User;
use App\Support\CurlCommand;
use Livewire\Livewire;

/**
 * Runs a command in a real shell with a stand-in for curl that prints the arguments it was given, one per
 * NUL, so a test sees exactly what curl would.
 *
 * @return list<string>
 */
function curlArguments(string $command): array
{
    $bin = sys_get_temp_dir().'/curl-args-'.uniqid();
    mkdir($bin);
    file_put_contents("{$bin}/curl", "#!/bin/sh\nprintf '%s\\0' \"\$@\"\n");
    chmod("{$bin}/curl", 0755);

    $output = shell_exec('PATH='.escapeshellarg($bin.':'.getenv('PATH')).' sh -c '.escapeshellarg($command));

    unlink("{$bin}/curl");
    rmdir($bin);

    return array_values(array_filter(explode("\0", (string) $output), fn (string $argument) => $argument !== ''));
}

function recordedRequest(array $attributes = []): EdgeRequest
{
    return EdgeRequest::factory()->for(Domain::factory()->create(['hostname' => 'www.example.test']))->create($attributes);
}

test('a request becomes a curl command with what the edge recorded', function () {
    $request = recordedRequest([
        'method' => HttpMethod::Post,
        'path' => '/login?next=[redacted]',
        'user_agent' => 'sqlmap/1.10.11',
        'accept_language' => 'en-US',
        'client_hints' => '"Chromium";v="141" | "macOS"',
    ]);

    expect(curlArguments(CurlCommand::for($request, ['content_type' => 'application/json', 'body' => '{"password":"[redacted]"}'])))->toBe([
        'https://www.example.test/login?next=[redacted]',
        '--globoff',
        '--path-as-is',
        '-X', 'POST',
        '-H', 'User-Agent: sqlmap/1.10.11',
        '-H', 'Accept-Language: en-US',
        '-H', 'Sec-CH-UA: "Chromium";v="141"',
        '-H', 'Sec-CH-UA-Platform: "macOS"',
        '-H', 'Content-Type: application/json',
        '--data-raw', '{"password":"[redacted]"}',
    ]);
});

test('a plain GET needs no method and sends only the headers it had', function () {
    $request = recordedRequest(['method' => HttpMethod::Get, 'path' => '/', 'user_agent' => null, 'accept_language' => null, 'client_hints' => null]);

    expect(curlArguments(CurlCommand::for($request)))->toBe(['https://www.example.test/', '--globoff', '--path-as-is']);
});

test('a GET with a body stays a GET', function () {
    $request = recordedRequest(['method' => HttpMethod::Get, 'path' => '/', 'user_agent' => null, 'accept_language' => null, 'client_hints' => null]);

    expect(curlArguments(CurlCommand::for($request, ['content_type' => null, 'body' => 'a=1'])))
        ->toBe(['https://www.example.test/', '--globoff', '--path-as-is', '-X', 'GET', '--data-raw', 'a=1']);
});

test('a HEAD request uses --head and drops a body curl would refuse', function () {
    $request = recordedRequest(['method' => HttpMethod::Head, 'path' => '/', 'user_agent' => null, 'accept_language' => null, 'client_hints' => null]);

    expect(curlArguments(CurlCommand::for($request, ['content_type' => 'text/plain', 'body' => 'a=1'])))
        ->toBe(['https://www.example.test/', '--globoff', '--path-as-is', '--head']);
});

test('nothing a visitor sends can run in the shell it is pasted into', function (string $hostile) {
    $request = recordedRequest(['method' => HttpMethod::Post, 'path' => '/a b', 'user_agent' => $hostile, 'accept_language' => null, 'client_hints' => null]);

    $arguments = curlArguments(CurlCommand::for($request, ['content_type' => null, 'body' => $hostile]));

    expect($arguments[count($arguments) - 1])->toBe($hostile)
        ->and($arguments[count($arguments) - 2])->toBe('--data-raw')
        ->and($arguments)->toContain('-H', 'User-Agent: '.str_replace("\n", '', $hostile))
        ->and($arguments[0])->toBe('https://www.example.test/a%20b');
})->with([
    'command substitution' => ['$(touch /tmp/pwned) `id` ${HOME}'],
    'closing the quote' => ["'; touch /tmp/pwned; echo '"],
    'a trailing backslash' => ['nope\\'],
    'a line break' => ["one'\ntouch /tmp/pwned\n"],
]);

test('control characters a terminal would act on while pasting are dropped', function () {
    $request = recordedRequest(['method' => HttpMethod::Post, 'path' => '/', 'user_agent' => "a\x03b\x1b[201~c\nd", 'accept_language' => null, 'client_hints' => null]);

    $command = CurlCommand::for($request, ['content_type' => "text/plain\r\nX-Injected: 1", 'body' => "one\x03\x1b[201~\u{9B}\ntwo"]);

    expect($command)->not->toMatch('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\x{9B}/u')
        ->and(curlArguments($command))->toContain('User-Agent: ab[201~cd', 'Content-Type: text/plainX-Injected: 1', "one[201~\ntwo");
});

test('the request details offer the command for the request being inspected', function () {
    $user = User::factory()->create();
    $domain = Domain::factory()->for($user->currentTeam)->create(['hostname' => 'www.example.test']);
    $first = EdgeRequest::factory()->for($domain)->create(['path' => '/first']);
    $second = EdgeRequest::factory()->for($domain)->create(['path' => '/second']);
    $this->actingAs($user);

    Livewire::test('pages::activity.index')
        ->call('inspect', $first->id)
        ->assertSeeHtml('data-test="copy-curl"')
        ->assertSee('https://www.example.test/first')
        ->call('inspect', $second->id)
        ->assertSee('https://www.example.test/second');
});
