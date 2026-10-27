<?php

use App\Data\LiveRequest;
use App\Data\RuleScript;
use App\Data\RuleScriptParser;
use App\Data\RuleScriptSyntaxError;
use App\Enums\RuleAction;
use App\Enums\RuleField;
use App\Enums\RuleSubject;

test('a probe rule bans the IP and fingerprint for 30 days', function () {
    $script = RuleScript::parse("-- Probe scanners\nban ip, ja4 for 30 days\nwhere category = 'probe'");

    expect($script->action)->toBe(RuleAction::Ban)
        ->and($script->subjects)->toBe([RuleSubject::Ip, RuleSubject::Ja4])
        ->and($script->durationSeconds)->toBe(30 * 86400)
        ->and($script->isAggregate())->toBeFalse()
        ->and($script->describe())->toBe('Ban the IP and TLS fingerprint for 30 days as soon as it makes a request where path category is “probe/*”.');
});

test('a windowed rule counts requests per subject with HAVING', function () {
    $script = RuleScript::parse("BAN ip FOR 1 HOUR\nWHERE status = 404\nWITHIN 10 MINUTES\nHAVING COUNT(*) >= 2 AND COUNT(DISTINCT host) >= 2");

    expect($script->windowSeconds)->toBe(600)
        ->and($script->isAggregate())->toBeTrue()
        ->and($script->fields())->toEqualCanonicalizing([RuleField::Status, RuleField::Host])
        ->and($script->describe())->toBe('Ban the IP for 1 hour when, within 10 minutes, its requests where status is 404 reach at least 2 requests and at least 2 different hosts.');
});

test('UNTIL says what lifts a verdict early', function () {
    $script = RuleScript::parse("TAG ip AS 'failed-login' FOR 1 DAY WHERE path = '/login' WITHIN 10 MINUTES HAVING COUNT(*) >= 3 UNTIL path = '/dashboard' AND status = 200");

    expect($script->until)->not->toBeNull()
        ->and($script->untilScript()->where)->toBe($script->until)
        ->and($script->describe())->toEndWith('reach at least 3 requests. It’s lifted as soon as the subject makes a request where path is “/dashboard” and status is 200.');
});

test('a tag rule carries its tag name and reads its conditions back', function () {
    $script = RuleScript::parse("TAG ip, ja4 AS 'ua-tls-mismatch' FOR 1 DAY WHERE ua LIKE '%Chrome/%' AND ja4.alpn = '00'");

    expect($script->action)->toBe(RuleAction::Tag)
        ->and($script->tag)->toBe('ua-tls-mismatch')
        ->and($script->describe())->toContain('user agent contains “Chrome/” and offered ALPN is “00”');
});

test('mistakes are reported with where they are and how to fix them', function (string $source, int $line, int $column, string $message) {
    try {
        RuleScript::parse($source);
        $this->fail('The script should not parse.');
    } catch (RuleScriptSyntaxError $error) {
        expect([$error->scriptLine, $error->scriptColumn])->toBe([$line, $column])
            ->and($error->getMessage())->toContain($message);
    }
})->with([
    'misspelled field' => ["BAN ip FOR 1 HOUR\nWHERE stauts = 404", 2, 7, 'Did you mean status?'],
    'unknown category' => ["BAN ip FOR 1 HOUR\nWHERE category = 'probes'", 2, 18, 'Unknown category probes'],
    'HAVING without a window' => ["BAN ip FOR 1 HOUR\nHAVING COUNT(*) > 3", 2, 1, 'HAVING needs a window first'],
    'text where a number belongs' => ['BAN ip FOR 1 HOUR WHERE status = \'x\'', 1, 34, 'status takes a number'],
    'unclosed quote' => ["BAN ip FOR 1 HOUR WHERE path LIKE '/admin", 1, 35, 'never closed'],
    'unsupported subject' => ['BAN asn FOR 1 HOUR', 1, 5, 'ip or ja4'],
    'tag without a name' => ['TAG ip FOR 1 DAY', 1, 8, 'TAG needs a name'],
    'window over a day' => ['BAN ip FOR 1 HOUR WITHIN 2 DAYS HAVING COUNT(*) > 1', 1, 26, 'at most 24 hours'],
    'unknown kind of client' => ["BAN ip FOR 1 HOUR WHERE client = 'old'", 1, 34, "client is 'spoofed', 'outdated' or 'corporate'"],
    'client tested for null' => ['BAN ip FOR 1 HOUR WHERE client IS NULL', 1, 32, 'client is never null'],
]);

test('an IP with a slash is a range, and a range that can\'t exist is refused', function () {
    $script = RuleScript::parse("TAG ip AS 'x' FOR 1 DAY WHERE NOT ip IN ('66.249.64.0/19', '203.0.113.7')");

    expect($script->describe())->toContain('IP is in “66.249.64.0/19”')
        ->and((new LiveRequest('GET', 'sign.example', '/', ip: '66.249.70.6'))->evaluate($script->where))->toBeFalse()
        ->and((new LiveRequest('GET', 'sign.example', '/', ip: '198.51.100.104'))->evaluate($script->where))->toBeTrue()
        ->and(fn () => RuleScript::parse("TAG ip AS 'x' FOR 1 DAY WHERE ip = '66.249.64.0/40'"))->toThrow(RuleScriptSyntaxError::class, "isn't an IP range");
});

test('a rule becomes the Activity search for the traffic it acts on', function (string $rule, string $search) {
    expect((new RuleScriptParser($rule))->activitySearch())->toBe($search)
        ->and((new RuleScriptParser($search))->parseSearch())->toBeArray();
})->with([
    'its WHERE, as written' => [
        "-- From an Activity search.\nTAG ip, ja4 AS 'activity-search' FOR 1 DAY\nWHERE host = 'sign.example'\nAND country != 'US'\nAND NOT path LIKE '/sign/%'",
        "host = 'sign.example'\nAND country != 'US'\nAND NOT path LIKE '/sign/%'",
    ],
    'grouped by IP, without the window' => [
        "BAN ip FOR 1 HOUR\nWHERE status = 404\nWITHIN 10 MINUTES\nHAVING COUNT(*) >= 2 AND COUNT(DISTINCT host) >= 2",
        "status = 404\nGROUP BY ip\nHAVING COUNT(*) >= 2 AND COUNT(DISTINCT host) >= 2",
    ],
    'without UNTIL, keeping a FILTER\'s own WHERE' => [
        "TAG ip, ja4 AS 'failed-login' FOR 1 DAY\nWHERE path = '/login'\nWITHIN 10 MINUTES HAVING COUNT(*) FILTER (WHERE status >= 400) >= 3\nUNTIL path = '/dashboard' AND status = 200",
        "path = '/login'\nHAVING COUNT(*) FILTER (WHERE status >= 400) >= 3",
    ],
    'HAVING alone, by fingerprint' => [
        "TAG ja4 AS 'noisy' FOR 1 DAY WITHIN 1 HOUR HAVING COUNT(*) FILTER (WHERE status = 404) > 5",
        "GROUP BY ja4\nHAVING COUNT(*) FILTER (WHERE status = 404) > 5",
    ],
]);
