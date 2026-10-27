<?php

namespace NiceTry\PrivacyMode\Support;

/**
 * Privacy mode's script, resources/js/privacy.js, served by the add-on itself rather than built into the
 * core's bundle. Its URL carries a version taken from its contents, so browsers keep it until it changes.
 */
final class Script
{
    /**
     * Read the script once.
     */
    public static function source(): string
    {
        static $source = null;

        return $source ??= (string) file_get_contents(__DIR__.'/../../resources/js/privacy.js');
    }

    /**
     * A short fingerprint of the script, for its URL.
     */
    public static function version(): string
    {
        return substr(md5(self::source()), 0, 16);
    }
}
