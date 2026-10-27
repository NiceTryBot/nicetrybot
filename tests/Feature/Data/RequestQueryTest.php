<?php

use App\Data\RequestQuery;
use App\Data\RuleScript;
use App\Data\RuleScriptSyntaxError;
use App\Enums\RuleSubject;

test('a search splits into its top-level AND clauses, as written', function () {
    $search = new RequestQuery("WHERE status = 404\nand (ua LIKE '%curl%' OR ua IS NULL)\nAND path = 'it''s AND that'");

    expect($search->clauses())->toBe(['status = 404', "(ua LIKE '%curl%' OR ua IS NULL)", "path = 'it''s AND that'"]);
});

test('a search joined by a top-level OR is one clause, so a filter can sit beside it', function () {
    $search = new RequestQuery('status = 404 OR status = 410');

    expect($search->with("host = 'claims.example.com'")->source)->toBe("(status = 404 OR status = 410)\nAND host = 'claims.example.com'");
});

test('clauses match however they are spaced, capitalized or wrapped', function () {
    $search = new RequestQuery("STATUS>=400 and Status < 500 AND ((method = 'POST'))");

    expect($search->has('status >= 400'))->toBeTrue()
        ->and($search->has("(method = 'POST')"))->toBeTrue()
        ->and($search->has("method = 'post'"))->toBeFalse()
        ->and($search->without(['status >= 400', 'status < 500'])->source)->toBe("((method = 'POST'))")
        ->and($search->toggled("method = 'POST'")->clauses())->toBe(['STATUS>=400', 'Status < 500']);
});

test('plain text searches as a text term', function () {
    $search = new RequestQuery('digitalocean');

    expect($search->where())->toBe(['kind' => 'text', 'text' => 'digitalocean'])
        ->and($search->describe())->toBe('Requests where anything mentions “digitalocean”.')
        ->and($search->with('status = 404')->source)->toBe("'digitalocean'\nAND status = 404");
});

test('a search that is not plain text and does not parse is an error', function () {
    (new RequestQuery("status = '404'"))->where();
})->throws(RuleScriptSyntaxError::class, 'status takes a number');

test('rule scripts still need a field before a value', function () {
    RuleScript::parse("BAN ip FOR 1 DAY WHERE 'curl'");
})->throws(RuleScriptSyntaxError::class, 'Expected a field');

test('the filters change a search\'s WHERE and leave its GROUP BY and HAVING alone', function () {
    $search = new RequestQuery("host = 'settlement.example'\nGROUP BY ip\nHAVING COUNT(*) FILTER (WHERE path = '/') = 0");

    expect($search->clauses())->toBe(["host = 'settlement.example'"])
        ->and($search->with('status = 404')->source)->toBe("host = 'settlement.example'\nAND status = 404\nGROUP BY ip\nHAVING COUNT(*) FILTER (WHERE path = '/') = 0")
        ->and($search->without(["host = 'settlement.example'"])->source)->toBe("GROUP BY ip\nHAVING COUNT(*) FILTER (WHERE path = '/') = 0")
        ->and($search->subjects())->toBe([RuleSubject::Ip]);
});

test('a search with HAVING reads as the clients it keeps', function () {
    $search = new RequestQuery("host = 'settlement.example' HAVING COUNT(*) FILTER (WHERE path LIKE '%/thank-you') >= 1 AND COUNT(*) FILTER (WHERE path = '/') = 0");

    expect($search->describe())->toBe('Requests where host is “settlement.example”, from clients (an IP and TLS fingerprint together) with at least 1 request where path matches “%/thank-you” and no requests where path is “/”.')
        ->and($search->subjects())->toBe([RuleSubject::Ip, RuleSubject::Ja4]);
});

test('words that only look like GROUP BY or HAVING search as text', function () {
    $search = new RequestQuery('having trouble');

    expect($search->where())->toBe(['kind' => 'text', 'text' => 'having trouble'])
        ->and($search->tail())->toBe('')
        ->and($search->with('status = 404')->source)->toBe("'having trouble'\nAND status = 404");
});

test('a search becomes a rule that tags the clients it finds over a window of at most a day', function () {
    $search = new RequestQuery("host = 'settlement.example' GROUP BY ja4 HAVING COUNT(*) > 20");

    expect($search->toRule('busy-fingerprints', 7 * 86400, ['From an Activity search.']))->toBe(implode("\n", [
        '-- From an Activity search.',
        "TAG ja4 AS 'busy-fingerprints' FOR 1 DAY",
        "WHERE host = 'settlement.example'",
        'WITHIN 24 HOURS',
        'HAVING COUNT(*) > 20',
    ]))
        ->and((new RequestQuery('status = 404'))->toRule('missing', 3600))->toBe("TAG ip, ja4 AS 'missing' FOR 1 DAY\nWHERE status = 404");
});
