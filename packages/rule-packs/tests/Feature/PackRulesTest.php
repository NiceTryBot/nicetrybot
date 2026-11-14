<?php

use App\Actions\Clients\FindCurrentFirefoxReleases;
use App\Actions\Rules\EvaluateRule;
use App\Addons\Addons;
use App\Data\LiveRequest;
use App\Enums\HttpMethod;
use App\Enums\NetworkKind;
use App\Enums\RequestAction;
use App\Enums\RuleAction;
use App\Models\Domain;
use App\Models\EdgeRequest;
use App\Models\EdgeRule;
use App\Models\Location;
use App\Models\User;
use App\Models\Verdict;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use NiceTry\Blackhole\Rules\BlackholePack;
use NiceTry\RequestMonitor\Models\CapturedResponse;
use NiceTry\RequestMonitor\Models\ResponseCapture;
use NiceTry\RiskyRules\RiskyRules;
use NiceTry\RulePacks\RulePacks;
use NiceTry\Tarpit\Rules\TarpitPack;

/**
 * A request from one client to one of the team's sites, a few minutes ago unless the attributes say
 * otherwise.
 *
 * @param  array<string, mixed>  $attributes
 */
function packSampleRequest(Domain $domain, string $ip, string $path, array $attributes = []): EdgeRequest
{
    return EdgeRequest::factory()->for($domain)->create([
        'client_ip' => $ip,
        'path' => $path,
        'method' => HttpMethod::Get,
        'status_code' => 200,
        'accept_language' => 'en-US,en;q=0.9',
        'occurred_at' => now()->subMinutes(2),
        ...$attributes,
    ]);
}

/**
 * What a person in Chrome does on a site: pages, a sign-in, the contact form, a missing favicon, and
 * pages whose paths look like probes (a blog post about backups, a page about credentials) but are
 * there. No pack rule may tag them.
 */
function packGenuineBrowsing(Domain $domain): void
{
    foreach ([
        ['/', 'GET', 200], ['/pricing', 'GET', 200], ['/login', 'GET', 200], ['/login', 'POST', 302], ['/contact', 'GET', 200],
        ['/contact', 'POST', 200], ['/favicon.ico', 'GET', 404], ['/blog/backup-strategies', 'GET', 200],
        ['/nursing/credentials/rn', 'GET', 200], ['/index.php?page=about', 'GET', 200], ['/wp-login.php', 'GET', 200],
    ] as [$path, $method, $status]) {
        EdgeRequest::factory()->for($domain)->genuineChrome()->create([
            'client_ip' => '198.51.100.200',
            'path' => $path,
            'method' => HttpMethod::fromVerb($method),
            'status_code' => $status,
            'accept_language' => 'en-US,en;q=0.9',
            'occurred_at' => now()->subMinutes(3),
        ]);
    }
}

$gptBot = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)';
$claudeBot = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)';
$chatGptUser = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot';
$perplexityUser = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)';
$googlebot = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
$chromeOnWindows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36';
$goTls = 't13d131100_f57a46bbacb6_e7c285222651';
$firefox = fn (int $version) => "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:{$version}.0) Gecko/20100101 Firefox/{$version}.0";
$firefoxTls = 't13d1717h2_5b57614c22b0_3cbfd9057e0d';

/**
 * Every shipped rule, with requests it must tag (from 203.0.113.x) and near misses it must leave alone
 * (from 198.51.100.x). Each also runs alongside packGenuineBrowsing().
 *
 * @var array<string, array{0: string, 1: string, 2: Closure(Collection<int, Domain>): void, 3: list<string>}>
 */
$samples = [
    'scanners / secret hunters' => ['scanners', 'secret-hunters', function (Collection $domains) {
        packSampleRequest($domains[0], '203.0.113.1', '/.env', ['status_code' => 404, 'user_agent' => 'python-requests/2.32.3']);
        packSampleRequest($domains[0], '203.0.113.2', '/.git/config', ['status_code' => 403, 'user_agent' => 'Mozilla/5.0 zgrab/0.x']);
        packSampleRequest($domains[0], '198.51.100.1', '/missing-page', ['status_code' => 404]);
        // The Bouncer's own 403 isn't the site's answer.
        packSampleRequest($domains[0], '198.51.100.2', '/.env', ['status_code' => 403, 'action' => RequestAction::Bouncer]);
    }, ['203.0.113.1', '203.0.113.2']],
    'scanners / CMS probes' => ['scanners', 'cms-probes', function (Collection $domains) {
        packSampleRequest($domains[0], '203.0.113.1', '/wp-login.php', ['status_code' => 404, 'user_agent' => 'Mozilla/5.0']);
        packSampleRequest($domains[0], '203.0.113.1', '/xmlrpc.php', ['status_code' => 404, 'user_agent' => 'Mozilla/5.0']);
        packSampleRequest($domains[0], '198.51.100.1', '/wp-admin/', ['status_code' => 302]);
        packSampleRequest($domains[0], '198.51.100.1', '/wp-login.php', ['status_code' => 200, 'method' => HttpMethod::Post]);
        packSampleRequest($domains[0], '198.51.100.2', '/wp-login.php', ['status_code' => 404]);
    }, ['203.0.113.1']],
    'scanners / wordlist sweep' => ['scanners', 'wordlist-sweep', function (Collection $domains) {
        foreach (['/.env', '/.git/config', '/wp-login.php', '/phpinfo.php', '/config.json'] as $path) {
            packSampleRequest($domains[0], '203.0.113.1', $path, ['status_code' => 404, 'user_agent' => 'Mozilla/5.0']);
        }

        foreach (['/old-page', '/2019-menu', '/team/jane', '/events/spring'] as $path) {
            packSampleRequest($domains[0], '198.51.100.1', $path, ['status_code' => 404]);
        }

        foreach (['/.env', '/.git/config', '/wp-login.php', '/phpinfo.php'] as $path) {
            packSampleRequest($domains[0], '198.51.100.2', $path, ['status_code' => 404]);
        }
    }, ['203.0.113.1']],
    'scanners / 404s across sites' => ['scanners', 'multi-site-404s', function (Collection $domains) {
        foreach ($domains as $domain) {
            packSampleRequest($domain, '203.0.113.1', '/old-page', ['status_code' => 404]);
            packSampleRequest($domain, '198.51.100.1', '/favicon.ico', ['status_code' => 404]);
            packSampleRequest($domain, '198.51.100.1', '/apple-touch-icon.png', ['status_code' => 404]);
        }

        packSampleRequest($domains[0], '198.51.100.2', '/old-page', ['status_code' => 404]);
        packSampleRequest($domains[1], '198.51.100.2', '/old-page', ['status_code' => 404]);

        // A routine's 404 exit, answered by the gym on every site, isn't the sites' own.
        foreach ($domains as $domain) {
            packSampleRequest($domain, '198.51.100.3', '/old-page', ['status_code' => 404, 'action' => RequestAction::Diverted]);
        }
    }, ['203.0.113.1']],
    'scanners / missing a lot' => ['scanners', 'missing-a-lot', function (Collection $domains) {
        // Inside its two-minute window with room to spare, however long the test takes to get there.
        $recent = ['occurred_at' => now()->subSeconds(30)];
        $guesses = ['/api/user', '/api/me', '/account/keys', '/account/orders', '/user', '/dashboard', '/api/v1/user', '/account/billing'];

        foreach ($guesses as $path) {
            packSampleRequest($domains[0], '203.0.113.1', $path, ['status_code' => 404, 'user_agent' => 'python-requests/2.34.2', 'accept_language' => null, ...$recent]);
            packSampleRequest($domains[0], '198.51.100.1', $path, ['status_code' => 404, ...$recent]);
            packSampleRequest($domains[0], '198.51.100.3', '/img'.$path.'.png', ['status_code' => 404, ...$recent]);
            packSampleRequest($domains[0], '198.51.100.4', $path, ['status_code' => 404, 'action' => RequestAction::Blocked, ...$recent]);
        }

        packSampleRequest($domains[0], '198.51.100.1', '/pricing', $recent);

        foreach (array_slice($guesses, 1) as $path) {
            packSampleRequest($domains[0], '198.51.100.2', $path, ['status_code' => 404, ...$recent]);
        }
    }, ['203.0.113.1']],
    'scanners / Livewire calls failing' => ['scanners', 'livewire-failing', function (Collection $domains) {
        foreach ([500, 419, 500, 500, 419] as $status) {
            packSampleRequest($domains[0], '203.0.113.1', '/livewire-f7c6ec91/update', ['method' => HttpMethod::Post, 'status_code' => $status]);
        }

        foreach ([419, 419] as $status) {
            packSampleRequest($domains[0], '198.51.100.1', '/livewire-f7c6ec91/update', ['method' => HttpMethod::Post, 'status_code' => $status]);
        }

        foreach (range(1, 6) as $call) {
            packSampleRequest($domains[0], '198.51.100.2', '/livewire-0ld0ld00/update', ['method' => HttpMethod::Post, 'status_code' => 404]);
            packSampleRequest($domains[0], '198.51.100.3', '/api/update', ['method' => HttpMethod::Post, 'status_code' => 500]);
        }
    }, ['203.0.113.1']],
    'PHP exploits / remote code' => ['php-exploits', 'php-rce', function (Collection $domains) {
        packSampleRequest($domains[0], '203.0.113.1', '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php', ['status_code' => 404, 'method' => HttpMethod::Post]);
        packSampleRequest($domains[0], '203.0.113.2', '/cgi-bin/php-cgi.exe?%ADd+allow_url_include%3d1+%ADd+auto_prepend_file%3dphp://input', ['status_code' => 404, 'method' => HttpMethod::Post]);
        packSampleRequest($domains[0], '203.0.113.3', '/index.php?s=/index/\think\app/invokefunction&function=call_user_func_array&vars[0]=md5', ['status_code' => 404]);
        packSampleRequest($domains[0], '203.0.113.4', '/_ignition/execute-solution', ['status_code' => 405, 'method' => HttpMethod::Post]);
        packSampleRequest($domains[0], '198.51.100.1', '/docs/phpunit-guide');
    }, ['203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4']],
    'PHP exploits / web shells' => ['php-exploits', 'php-shells', function (Collection $domains) {
        packSampleRequest($domains[0], '203.0.113.1', '/wp-content/uploads/2024/01/x.php', ['status_code' => 404]);
        packSampleRequest($domains[0], '203.0.113.2', '/wso.php', ['status_code' => 404]);
        packSampleRequest($domains[0], '203.0.113.3', '/alfacgiapi/perl.alfa', ['status_code' => 404]);
        packSampleRequest($domains[0], '198.51.100.1', '/wp-content/uploads/2024/01/photo.jpg');
        packSampleRequest($domains[0], '198.51.100.1', '/blog/c99-review');
    }, ['203.0.113.1', '203.0.113.2', '203.0.113.3']],
    'PHP exploits / server files' => ['php-exploits', 'server-files', function (Collection $domains) {
        packSampleRequest($domains[0], '203.0.113.1', '/index.php?file=../../../../etc/passwd', ['status_code' => 404]);
        packSampleRequest($domains[0], '203.0.113.2', '/static/../../../windows/win.ini', ['status_code' => 400]);
        packSampleRequest($domains[0], '203.0.113.3', '/?page=php://filter/convert.base64-encode/resource=index');
        packSampleRequest($domains[0], '198.51.100.1', '/docs/linux-passwd-file');
        packSampleRequest($domains[0], '198.51.100.1', '/blog/php-streams');
    }, ['203.0.113.1', '203.0.113.2', '203.0.113.3']],
    'AI crawlers / training' => ['ai-crawlers', 'ai-training', function (Collection $domains) use ($gptBot, $claudeBot, $chatGptUser, $googlebot) {
        packSampleRequest($domains[0], '203.0.113.1', '/about', ['user_agent' => $gptBot]);
        packSampleRequest($domains[0], '203.0.113.2', '/pricing', ['user_agent' => $claudeBot]);
        packSampleRequest($domains[0], '198.51.100.1', '/about', ['user_agent' => $chatGptUser]);
        packSampleRequest($domains[0], '198.51.100.2', '/about', ['user_agent' => $googlebot]);
    }, ['203.0.113.1', '203.0.113.2']],
    'AI crawlers / assistants' => ['ai-crawlers', 'ai-assistants', function (Collection $domains) use ($gptBot, $chatGptUser, $perplexityUser, $googlebot) {
        packSampleRequest($domains[0], '203.0.113.1', '/about', ['user_agent' => $chatGptUser]);
        packSampleRequest($domains[0], '203.0.113.2', '/pricing', ['user_agent' => $perplexityUser]);
        packSampleRequest($domains[0], '198.51.100.1', '/about', ['user_agent' => $gptBot]);
        packSampleRequest($domains[0], '198.51.100.2', '/about', ['user_agent' => $googlebot]);
    }, ['203.0.113.1', '203.0.113.2']],
    'AI crawlers / impostors' => ['ai-crawlers', 'ai-impostors', function (Collection $domains) use ($gptBot, $claudeBot) {
        foreach (['/.env', '/.git/config', '/wp-config.php.bak'] as $path) {
            packSampleRequest($domains[0], '203.0.113.1', $path, ['status_code' => 404, 'user_agent' => $claudeBot]);
        }

        foreach (['/about', '/pricing', '/index.php', '/old-page'] as $path) {
            packSampleRequest($domains[0], '198.51.100.1', $path, ['status_code' => $path === '/old-page' ? 404 : 200, 'user_agent' => $gptBot]);
        }

        foreach (['/.env', '/.git/config'] as $path) {
            packSampleRequest($domains[0], '198.51.100.2', $path, ['status_code' => 404, 'user_agent' => $gptBot]);
        }
    }, ['203.0.113.1']],
    'browsers / spoofed' => ['browser-impostors', 'spoofed-browser', function (Collection $domains) use ($chromeOnWindows, $goTls) {
        EdgeRequest::factory()->for($domains[0])->fromClient($chromeOnWindows, $goTls)->create(['client_ip' => '203.0.113.1', 'path' => '/pricing', 'occurred_at' => now()->subMinutes(2)]);
        EdgeRequest::factory()->for($domains[0])->genuineChrome(150)->create(['client_ip' => '198.51.100.1', 'path' => '/pricing', 'occurred_at' => now()->subMinutes(2)]);
    }, ['203.0.113.1']],
    'browsers / Chrome on HTTP/1.1' => ['browser-impostors', 'chrome-on-http1', function (Collection $domains) {
        $pythonTls = 't13d9013h1_c6771aded2ed_57a60bdf03d1';
        EdgeRequest::factory()->for($domains[0])->fromClient('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', $pythonTls)->create(['client_ip' => '203.0.113.1', 'path' => '/account', 'occurred_at' => now()->subMinutes(2)]);
        EdgeRequest::factory()->for($domains[0])->fromClient('python-requests/2.34.2', $pythonTls)->create(['client_ip' => '198.51.100.1', 'path' => '/account', 'occurred_at' => now()->subMinutes(2)]);
        EdgeRequest::factory()->for($domains[0])->genuineChrome()->create(['client_ip' => '198.51.100.2', 'path' => '/account', 'occurred_at' => now()->subMinutes(2)]);
    }, ['203.0.113.1']],
    'browsers / headless' => ['browser-impostors', 'headless-browser', function (Collection $domains) use ($chromeOnWindows) {
        packSampleRequest($domains[0], '203.0.113.1', '/pricing', ['user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/142.0.0.0 Safari/537.36']);
        packSampleRequest($domains[0], '203.0.113.2', '/pricing', ['user_agent' => $chromeOnWindows, 'client_hints' => '"Chromium";v="142", "HeadlessChrome";v="142" | "Linux"']);
        packSampleRequest($domains[0], '198.51.100.1', '/blog/headless-cms-guide', ['user_agent' => $chromeOnWindows]);
    }, ['203.0.113.1', '203.0.113.2']],
    'browsers / outdated' => ['browser-impostors', 'outdated-browser', function (Collection $domains) use ($firefox, $firefoxTls) {
        EdgeRequest::factory()->for($domains[0])->fromClient($firefox(150), $firefoxTls)->fromNetwork(7922, 'Comcast', NetworkKind::Unknown)->create(['client_ip' => '203.0.113.1', 'occurred_at' => now()->subMinutes(2)]);
        EdgeRequest::factory()->for($domains[0])->fromClient($firefox(158), $firefoxTls)->fromNetwork(7922, 'Comcast', NetworkKind::Unknown)->create(['client_ip' => '198.51.100.1', 'occurred_at' => now()->subMinutes(2)]);
        EdgeRequest::factory()->for($domains[0])->fromClient($firefox(153), $firefoxTls)->fromNetwork(7922, 'Comcast', NetworkKind::Unknown)->create(['client_ip' => '198.51.100.2', 'occurred_at' => now()->subMinutes(2)]);
        EdgeRequest::factory()->for($domains[0])->fromClient($firefox(150), $firefoxTls)->fromNetwork(22616, 'Zscaler', NetworkKind::Unknown)->create(['client_ip' => '198.51.100.3', 'occurred_at' => now()->subMinutes(2)]);
    }, ['203.0.113.1']],
    'forms / scripts posting' => ['form-scripts', 'scripts-posting-forms', function (Collection $domains) {
        packSampleRequest($domains[0], '203.0.113.1', '/login', ['method' => HttpMethod::Post, 'user_agent' => 'curl/8.4.0']);
        packSampleRequest($domains[0], '203.0.113.2', '/contact-us', ['method' => HttpMethod::Post, 'user_agent' => 'python-requests/2.32.3']);
        packSampleRequest($domains[0], '203.0.113.3', '/account/register', ['method' => HttpMethod::Post, 'user_agent' => null, 'accept_language' => null]);
        packSampleRequest($domains[0], '198.51.100.1', '/webhooks/payments', ['method' => HttpMethod::Post, 'user_agent' => 'python-requests/2.32.3']);
        packSampleRequest($domains[0], '198.51.100.2', '/login', ['user_agent' => 'curl/8.4.0']);
        packSampleRequest($domains[0], '198.51.100.3', '/api/login', ['method' => HttpMethod::Post, 'user_agent' => 'okhttp/4.12.0']);
    }, ['203.0.113.1', '203.0.113.2', '203.0.113.3']],
    'forms / sign-in hammering' => ['form-scripts', 'sign-in-hammering', function (Collection $domains) use ($chromeOnWindows) {
        foreach (range(1, 10) as $attempt) {
            packSampleRequest($domains[0], '203.0.113.1', '/login', ['method' => HttpMethod::Post, 'status_code' => 422, 'user_agent' => $chromeOnWindows, 'occurred_at' => now()->subMinutes(1)->subSeconds($attempt * 20)]);
        }

        foreach (range(1, 4) as $attempt) {
            packSampleRequest($domains[0], '198.51.100.1', '/login', ['method' => HttpMethod::Post, 'status_code' => 422, 'user_agent' => $chromeOnWindows]);
        }
    }, ['203.0.113.1']],
    'forms / failed sign-ins' => ['form-scripts', 'failed-sign-ins', function (Collection $domains) {
        $capture = ResponseCapture::factory()->for($domains[0]->team)->create();
        $signIn = fn (string $ip, string $body) => CapturedResponse::factory()->for($capture)->create([
            'edge_request_id' => packSampleRequest($domains[0], $ip, '/login', ['method' => HttpMethod::Post, 'status_code' => 302])->id,
            'body' => $body,
        ]);

        foreach (range(1, 3) as $attempt) {
            $signIn('203.0.113.1', '{"errors":{"email":["These credentials do not match our records."]}}');
        }

        $signIn('198.51.100.1', '{"errors":{"email":["These credentials do not match our records."]}}');
        $signIn('198.51.100.1', '{"redirect":"/dashboard"}');

        foreach (range(1, 3) as $attempt) {
            $signIn('198.51.100.2', '{"redirect":"/dashboard"}');
        }
    }, ['203.0.113.1']],
];

$samples += [
    'risky / no language' => ['risky-rules', 'no-language', function (Collection $domains) use ($chromeOnWindows) {
        packSampleRequest($domains[0], '203.0.113.1', '/account', ['user_agent' => 'python-requests/2.34.2', 'accept_language' => null]);
        packSampleRequest($domains[0], '203.0.113.2', '/login', ['user_agent' => $chromeOnWindows, 'accept_language' => null]);
        packSampleRequest($domains[0], '198.51.100.1', '/api/orders', ['user_agent' => 'okhttp/4.12.0', 'accept_language' => null]);
        packSampleRequest($domains[0], '198.51.100.2', '/stripe/webhook', ['method' => HttpMethod::Post, 'user_agent' => 'Stripe/1.0', 'accept_language' => null]);
        packSampleRequest($domains[0], '198.51.100.3', '/robots.txt', ['user_agent' => 'UptimeRobot/2.0', 'accept_language' => null]);
        packSampleRequest($domains[0], '198.51.100.4', '/css/app.css', ['user_agent' => 'Slackbot-LinkExpanding 1.0', 'accept_language' => null]);
    }, ['203.0.113.1', '203.0.113.2']],
    'risky / scripts anywhere' => ['risky-rules', 'scripts-anywhere', function (Collection $domains) {
        packSampleRequest($domains[0], '203.0.113.1', '/account', ['user_agent' => 'curl/8.21.0']);
        packSampleRequest($domains[0], '203.0.113.2', '/', ['user_agent' => 'python-requests/2.34.2']);
        packSampleRequest($domains[0], '198.51.100.1', '/api/orders', ['user_agent' => 'python-requests/2.34.2']);
        packSampleRequest($domains[0], '198.51.100.2', '/hooks/payment-webhook', ['method' => HttpMethod::Post, 'user_agent' => 'curl/8.21.0']);
        packSampleRequest($domains[0], '198.51.100.3', '/', ['user_agent' => 'okhttp/4.12.0']);
    }, ['203.0.113.1', '203.0.113.2']],
];

$samples += [
    'tarpit / error answers' => ['tarpit', 'error-answers', function (Collection $domains) {
        // Its verdict lapses a minute after the error, so only a client erroring just now holds one.
        $justNow = ['occurred_at' => now()->subSeconds(20)];
        packSampleRequest($domains[0], '203.0.113.1', '/api/user', ['status_code' => 404, ...$justNow]);
        packSampleRequest($domains[0], '203.0.113.2', '/livewire-f7c6ec91/update', ['method' => HttpMethod::Post, 'status_code' => 500, ...$justNow]);
        packSampleRequest($domains[0], '198.51.100.1', '/login', ['method' => HttpMethod::Post, 'status_code' => 422, ...$justNow]);
        packSampleRequest($domains[0], '198.51.100.2', '/checkout', ['status_code' => 502, ...$justNow]);
        packSampleRequest($domains[0], '198.51.100.3', '/old-page', ['status_code' => 404, 'occurred_at' => now()->subMinutes(5)]);
        // The Bouncer's own 403 challenge isn't the site's error answer.
        packSampleRequest($domains[0], '198.51.100.4', '/', ['status_code' => 403, 'action' => RequestAction::Bouncer, ...$justNow]);
    }, ['203.0.113.1', '203.0.113.2']],
    'tarpit / probe paths' => ['tarpit', 'probe-paths', function (Collection $domains) {
        packSampleRequest($domains[0], '203.0.113.1', '/.env', ['status_code' => 404]);
        packSampleRequest($domains[0], '203.0.113.2', '/backup/db.sql', ['status_code' => 404]);
        packSampleRequest($domains[0], '198.51.100.1', '/docs/environment-setup');
        packSampleRequest($domains[0], '198.51.100.2', '/nursing/credentials/rn');
    }, ['203.0.113.1', '203.0.113.2']],
];

$samples += [
    'blackhole / multi-host probe' => ['blackhole', 'multi-host-probe', function (Collection $domains) {
        packSampleRequest($domains[0], '203.0.113.1', '/.env', ['status_code' => 404, 'occurred_at' => now()->subHours(5)]);
        packSampleRequest($domains[1], '203.0.113.1', '/xmlrpc.php', ['status_code' => 404, 'occurred_at' => now()->subMinutes(5)]);
        packSampleRequest($domains[0], '198.51.100.1', '/.env', ['status_code' => 404]);
        packSampleRequest($domains[0], '198.51.100.1', '/.git/config', ['status_code' => 404]);
        packSampleRequest($domains[1], '198.51.100.2', '/missing-page', ['status_code' => 404]);
        packSampleRequest($domains[2], '198.51.100.2', '/old-page', ['status_code' => 404]);
    }, ['203.0.113.1']],
    'blackhole / probe from abroad' => ['blackhole', 'foreign-probe', function (Collection $domains) {
        $abroad = ['location_id' => Location::idFor('DE', 'Berlin', 'Berlin')];
        $home = ['location_id' => Location::idFor('US', 'California', 'San Diego')];
        packSampleRequest($domains[0], '203.0.113.1', '/.env', ['status_code' => 404, ...$abroad]);
        packSampleRequest($domains[0], '198.51.100.1', '/.env', ['status_code' => 404, ...$home]);
        packSampleRequest($domains[0], '198.51.100.2', '/about', $abroad);
    }, ['203.0.113.1']],
    'blackhole / repeat probe' => ['blackhole', 'repeat-probe', function (Collection $domains) {
        foreach (['/.env', '/.env.bak', '/.git/config', '/wp-config.php', '/.aws/credentials'] as $i => $path) {
            packSampleRequest($domains[0], '203.0.113.1', $path, ['status_code' => 404, 'occurred_at' => now()->subMinutes(10 + $i)]);
            packSampleRequest($domains[$i % 2], '198.51.100.2', $path, ['status_code' => 404, 'occurred_at' => now()->subMinutes(10 + $i)]);
        }

        foreach (['/.env', '/.git/config', '/wp-config.php', '/.aws/credentials'] as $i => $path) {
            packSampleRequest($domains[0], '198.51.100.1', $path, ['status_code' => 404, 'occurred_at' => now()->subMinutes(10 + $i)]);
        }
    }, ['203.0.113.1']],
];

// The Blackhole add-on brings its pack; without it the pack isn't offered, so it isn't tested.
if (! class_exists(BlackholePack::class)) {
    unset($samples['blackhole / multi-host probe'], $samples['blackhole / probe from abroad'], $samples['blackhole / repeat probe']);
}

// Tarpit traps come with the Tarpit add-on; without it the pack isn't offered, so it isn't tested.
if (! class_exists(TarpitPack::class)) {
    unset($samples['tarpit / error answers'], $samples['tarpit / probe paths']);
}

// Risky Rules is an add-on of its own; without it installed its pack isn't offered, so it isn't tested.
if (! class_exists(RiskyRules::class)) {
    unset($samples['risky / no language'], $samples['risky / scripts anywhere']);
}

// The failed sign-ins rule reads the paid Request monitor add-on's `response` field, and its sample records
// responses with that add-on's models. Without the add-on installed the rule isn't offered, so it isn't tested.
$withoutRequestMonitor = ! class_exists(ResponseCapture::class);
$needsRequestMonitor = fn (string $pack, string $rule): bool => $withoutRequestMonitor && "{$pack}/{$rule}" === 'form-scripts/failed-sign-ins';

if ($withoutRequestMonitor) {
    unset($samples['forms / failed sign-ins']);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([FindCurrentFirefoxReleases::URL => Http::response(['LATEST_FIREFOX_VERSION' => '158.0', 'FIREFOX_ESR' => '153.5.0esr'])]);
});

test('every shipped rule parses, and only tags', function () use ($needsRequestMonitor) {
    foreach (app(RulePacks::class)->all() as $pack) {
        foreach (array_keys($pack->rules()) as $rule) {
            if ($needsRequestMonitor($pack->key(), $rule)) {
                continue;
            }

            // A rule sending clients to the Bouncer only parses with the Bouncer add-on (which the paid
            // Fingerprints needs), so the public tree leaves it out.
            if (str_contains($pack->rules()[$rule]['source'], 'BOUNCER') && ! app(Addons::class)->has('bouncer')) {
                continue;
            }

            expect($pack->unavailableBecause($rule))->toBeNull()
                ->and($pack->script($rule)->action)->toBe(RuleAction::Tag);
        }
    }
});

test('every shipped rule has samples here', function () use ($samples, $needsRequestMonitor) {
    $shipped = collect(app(RulePacks::class)->all())
        ->flatMap(fn ($pack) => array_map(fn (string $rule) => $pack->key().'/'.$rule, array_keys($pack->rules())))
        ->reject(fn (string $key) => $needsRequestMonitor(...explode('/', $key, 2)))
        // Swarm Mode's rules reach verdicts on sites and paths, not clients: its own package tests them.
        ->reject(fn (string $key) => str_starts_with($key, 'swarm-mode/'))
        ->sort()->values()->all();

    expect(collect($samples)->map(fn (array $sample) => $sample[0].'/'.$sample[1])->sort()->values()->all())->toBe($shipped);
});

test('a shipped rule tags the clients it\'s for, and not its near misses or a person in Chrome', function (string $pack, string $rule, Closure $sample, array $tagged) {
    app(FindCurrentFirefoxReleases::class)->refresh();
    $user = User::factory()->create();
    $domains = Domain::factory()->for($user->currentTeam)->count(3)->create();
    $edgeRule = EdgeRule::factory()->for($user->currentTeam)->script(app(RulePacks::class)->find($pack)->rules()[$rule]['source'])->create(['last_request_id' => 0]);

    $sample($domains);
    packGenuineBrowsing($domains[0]);
    app(EvaluateRule::class)->handle($edgeRule);

    expect(Verdict::query()->where('edge_rule_id', $edgeRule->id)->orderBy('client_ip')->pluck('client_ip')->all())->toBe($tagged);
})->with($samples);

test('the shipped rules the edge can judge from one request agree with the minute\'s run about every sample', function () use ($samples) {
    $judged = 0;

    foreach ($samples as [$pack, $rule, $sample, $tagged]) {
        $script = app(RulePacks::class)->find($pack)->script($rule);

        if (! $script->isLive()) {
            continue;
        }

        $user = User::factory()->create();
        $domains = Domain::factory()->for($user->currentTeam)->count(3)->create();
        $sample($domains);
        packGenuineBrowsing($domains[0]);

        foreach (EdgeRequest::query()->whereIn('domain_id', $domains->modelKeys())->with('domain', 'requestPath', 'userAgentRecord', 'ja4Fingerprint', 'location')->get() as $request) {
            $live = new LiveRequest($request->method->label(), $request->domain->hostname, $request->path, ip: $request->client_ip, ja4: $request->ja4, userAgent: $request->user_agent, described: true, language: $request->accept_language, countryCode: $request->country_code);

            expect($live->evaluate($script->where))->toBe(in_array($request->client_ip, $tagged, true), "{$pack}/{$rule}: {$request->client_ip} {$request->path}");
        }

        $judged++;
    }

    expect($judged)->toBeGreaterThan(0);
});
