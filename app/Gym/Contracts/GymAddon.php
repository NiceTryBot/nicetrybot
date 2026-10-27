<?php

namespace App\Gym\Contracts;

use App\Gym\GymVisit;
use App\Models\GymRoutine;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Something a package adds to the gym from outside the core: a setting in the routine editor, kept by
 * the package itself, and anything it puts on the page a visitor sees. Add-ons are tagged in the
 * container under App\Gym\GymAddons::TAG.
 */
interface GymAddon
{
    /**
     * Get the key the routine editor keeps the add-on's setting under.
     */
    public function key(): string;

    /**
     * Get the Blade view that draws the setting in the routine editor. It's given the setting's
     * `$value`, the `$model` path to wire:model it to, and whether the user `$canManage` routines.
     */
    public function editorView(): string;

    /**
     * Get the setting for a routine, or its default for one not yet saved.
     */
    public function load(?GymRoutine $routine): mixed;

    /**
     * Keep the setting for a routine that was just saved.
     */
    public function save(GymRoutine $routine, mixed $value): void;

    /**
     * Get what to add to the page a visitor sees for one of their obstacles, or null for nothing.
     */
    public function pageHtml(GymVisit $visit): ?Htmlable;
}
