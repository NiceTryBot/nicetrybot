<?php

namespace App\Addons;

use Composer\InstalledVersions;
use JsonException;

/**
 * An installed add-on: a Composer package of type nicetry-addon whose service provider extends
 * AddonServiceProvider. Everything here comes from the package's composer.json; its
 * extra.nicetry block names it, says whether it's paid, and lists the add-ons that must be on for it
 * to run (needs), beyond Composer's guarantee that they're installed.
 */
final readonly class Addon
{
    /**
     * @param  list<string>  $needs  The slugs of the add-ons this one goes off without.
     */
    public function __construct(
        public string $package,
        public string $slug,
        public string $name,
        public string $description,
        public ?string $version,
        public bool $paid,
        public string $path,
        public array $needs = [],
    ) {
        //
    }

    /**
     * Read the add-on from the composer.json at the root of its package.
     *
     * @throws JsonException
     */
    public static function fromPackage(string $path): self
    {
        /** @var array{name: string, description?: string, version?: string, extra?: array{nicetry?: array{name?: string, slug?: string, edition?: string, needs?: list<string>}}} $composer */
        $composer = json_decode((string) file_get_contents($path.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $meta = $composer['extra']['nicetry'] ?? [];
        $slug = $meta['slug'] ?? str($composer['name'])->afterLast('/')->toString();

        return new self(
            package: $composer['name'],
            slug: $slug,
            name: $meta['name'] ?? str($slug)->headline()->toString(),
            description: $composer['description'] ?? '',
            version: self::installedVersion($composer['name']) ?? $composer['version'] ?? null,
            paid: ($meta['edition'] ?? 'free') === 'paid',
            path: $path,
            needs: $meta['needs'] ?? [],
        );
    }

    private static function installedVersion(string $package): ?string
    {
        return InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;
    }
}
