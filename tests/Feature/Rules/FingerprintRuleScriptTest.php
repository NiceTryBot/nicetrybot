<?php

use App\Data\RuleScript;
use App\Data\RuleScriptSyntaxError;
use App\Enums\RuleAction;

test('FINGERPRINT parses with a tag, and EVERY says how soon it repeats', function () {
    $script = RuleScript::parse("FINGERPRINT ip, ja4 AS 'claim-form' FOR 30 DAYS EVERY 60 MINUTES WHERE path LIKE '/claim%'");

    expect($script->action)->toBe(RuleAction::Fingerprint)
        ->and($script->tag)->toBe('claim-form')
        ->and($script->durationSeconds)->toBe(30 * 86400)
        ->and($script->everySeconds)->toBe(3600)
        ->and($script->describe())->toContain('fingerprinting again at most every');
});

test('EVERY is optional', function () {
    expect(RuleScript::parse("FINGERPRINT ip AS 'x' FOR 1 DAY")->everySeconds)->toBeNull();
});

test('a FINGERPRINT rule that can not work is refused where it is written', function (string $source, string $message) {
    expect(fn () => RuleScript::parse($source))->toThrow(RuleScriptSyntaxError::class, $message);
})->with([
    'no name' => ['FINGERPRINT ip FOR 1 DAY', 'FINGERPRINT needs a name'],
    'a window' => ["FINGERPRINT ip AS 'x' FOR 1 DAY WHERE path = '/a' WITHIN 10 MINUTES HAVING COUNT(*) >= 2", 'one request has to decide it'],
    'what the origin answered' => ["FINGERPRINT ip AS 'x' FOR 1 DAY WHERE status = 404", 'one request has to decide it'],
    'too often' => ["FINGERPRINT ip AS 'x' FOR 1 DAY EVERY 5 MINUTES", 'no less than 10 minutes'],
]);

test('only FINGERPRINT takes EVERY', function () {
    expect(fn () => RuleScript::parse("TAG ip AS 'x' FOR 1 DAY EVERY 1 HOUR"))->toThrow(RuleScriptSyntaxError::class, 'Only FINGERPRINT takes EVERY');
});
