<?php

namespace NiceTry\TarpitPro;

use App\Addons\AddonServiceProvider;

/**
 * Tarpit Pro, as an add-on: it ships no pages, routes or feeds of its own. It only unlocks the tarpit's
 * advanced holds — any response status a rule names (not just 404), asset-safe holds, and the per-try
 * cooldown ladder — which the free Tarpit add-on gates on this one being on. Turn it off, or let its
 * licence lapse, and those fall back to the free behaviour: only 404 answers held, a flat cooldown, and
 * assets held like any 404.
 */
class TarpitProServiceProvider extends AddonServiceProvider
{
    //
}
