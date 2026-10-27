<?php

use App\Actions\Edge\ResolveLookupIds;
use App\Enums\TlsFingerprintType;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Count the inserts a callback runs into lookup tables with an id sequence. Client profiles are keyed
 * by their lookup's id, so writing them again spends nothing.
 */
function insertsDuring(callable $callback): int
{
    $inserts = 0;
    DB::listen(function (QueryExecuted $query) use (&$inserts) {
        if (preg_match('/^\s*insert\s+(or\s+ignore\s+)?into\s+"(bot_tags|livewire_components|request_paths|user_agents|tls_fingerprints|locations)"/i', $query->sql) === 1) {
            $inserts++;
        }
    });

    $callback();

    return $inserts;
}

test('values already known are resolved without offering them to the table again', function () {
    $lookups = app(ResolveLookupIds::class);
    $resolve = fn () => [
        $lookups->botTags(['lockdown', 'php_multipart']),
        $lookups->livewireComponents(['app.filament.auth.login']),
        $lookups->paths(['/', '/.env']),
        $lookups->userAgents(['curl/8.7.1']),
        $lookups->fingerprints(TlsFingerprintType::Ja4, ['t13d1517h2_8daaf6152771_cb7bf5808d99']),
        $lookups->locations([['US', 'California', 'San Jose', 37.3, -121.9]]),
    ];
    $first = $resolve();

    $inserts = insertsDuring(function () use ($resolve, &$again) {
        $again = $resolve();
    });

    expect($inserts)->toBe(0)
        ->and($again)->toBe($first);
});

test('only the new values of a batch are inserted', function () {
    $lookups = app(ResolveLookupIds::class);
    $known = $lookups->botTags(['lockdown']);

    $ids = $lookups->botTags(['lockdown', 'php_multipart']);

    expect($ids['lockdown'])->toBe($known['lockdown'])
        ->and(DB::table('bot_tags')->where('name', 'php_multipart')->value('id'))->toBe($ids['php_multipart']);
});
