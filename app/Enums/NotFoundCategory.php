<?php

namespace App\Enums;

enum NotFoundCategory: string
{
    case Probe = 'probe';
    case BrowserAsset = 'browser_asset';
    case MissingPage = 'missing_page';

    /**
     * Classify a requested path that returned a 404. The patterns live in PathCategory, the single
     * source of truth shared with the flow reports and future edge rules.
     */
    public static function classify(string $path): self
    {
        return PathCategory::classify($path)?->notFoundCategory() ?? self::MissingPage;
    }

    /**
     * The 404 category for a path's stored category (a PathCategory value, or null when nothing matched).
     */
    public static function fromPathCategory(int|string|null $category): self
    {
        return $category === null ? self::MissingPage : (PathCategory::tryFrom((int) $category)?->notFoundCategory() ?? self::MissingPage);
    }

    /**
     * Get the display label for the category.
     */
    public function label(): string
    {
        return match ($this) {
            self::Probe => __('Scanner probe'),
            self::BrowserAsset => __('Browser asset'),
            self::MissingPage => __('Missing page'),
        };
    }

    /**
     * Get a short explanation of the category.
     */
    public function description(): string
    {
        return match ($this) {
            self::Probe => __('Someone looking for secrets, admin panels, or known exploits.'),
            self::BrowserAsset => __('Icons and manifests that browsers ask for on their own. Usually real visitors.'),
            self::MissingPage => __('A link or bookmark that points nowhere. Worth a redirect.'),
        };
    }

    /**
     * Get the Flux badge color for the category.
     */
    public function color(): string
    {
        return match ($this) {
            self::Probe => 'rose',
            self::BrowserAsset => 'sky',
            self::MissingPage => 'zinc',
        };
    }
}
