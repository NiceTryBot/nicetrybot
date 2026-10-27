<?php

namespace App\Support;

/**
 * One kind of visitor data a team chooses how long to keep, on the Visitor data page. Its days are
 * stored in the visitor_data_settings column of the same name, which the holder that declares it
 * adds in its own migration.
 */
final readonly class RetentionPeriod
{
    /**
     * @param  string  $label  Untranslated, like $help; both are translated as the page is drawn.
     * @param  int  $order  Where it's listed; the core's are spaced ten apart.
     * @param  string|null  $atMost  Another period's field this one may not be longer than.
     */
    public function __construct(
        public string $field,
        public string $label,
        public string $help,
        public int $default,
        public int $order,
        public ?string $atMost = null,
    ) {
        //
    }
}
