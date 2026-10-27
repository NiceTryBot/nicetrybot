<?php

namespace App\Support;

/**
 * Starter rules a package adds to the Rules page's template gallery, after the core's. Sources are
 * tagged in the container under RuleTemplates::TAG.
 */
interface RuleTemplateSource
{
    /**
     * The templates, keyed by a slug unique among every template (a key already taken is left out).
     * Name and blurb are untranslated; the gallery translates them as it's drawn. The source must parse.
     *
     * @return array<string, array{name: string, blurb: string, source: string}>
     */
    public function ruleTemplates(): array;
}
