<?php

namespace App\Enums;

/**
 * What an autonomous system is for, as App\Models\NetworkCategory records it: a shipped dataset covering
 * most of the internet's networks, which an administrator can correct. It says more than NetworkKind
 * (hosting or not, from a curated list), but it's too long to send to edges, so rules reading it are
 * judged from the logs rather than live (RuleField::isLive()).
 */
enum NetworkCategory: int
{
    case Hosting = 1;
    case Isp = 2;
    case Business = 3;
    case Education = 4;
    case Government = 5;

    /**
     * Get the category a script or a form names, or null when it names none.
     */
    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === strtolower($slug)) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Get the category as a script writes it.
     */
    public function slug(): string
    {
        return match ($this) {
            self::Hosting => 'hosting',
            self::Isp => 'isp',
            self::Business => 'business',
            self::Education => 'education',
            self::Government => 'government',
        };
    }

    /**
     * Get the display label for the category.
     */
    public function label(): string
    {
        return match ($this) {
            self::Hosting => __('Hosting'),
            self::Isp => __('ISP'),
            self::Business => __('Business'),
            self::Education => __('Education'),
            self::Government => __('Government'),
        };
    }

    /**
     * Get the colour of the category's badge.
     */
    public function color(): string
    {
        return match ($this) {
            self::Hosting => 'amber',
            self::Isp => 'sky',
            self::Business => 'zinc',
            self::Education => 'violet',
            self::Government => 'indigo',
        };
    }

    /**
     * Determine if the category's networks are mostly used by real people, rather than machines.
     */
    public function isMostlyPeople(): bool
    {
        return in_array($this, [self::Isp, self::Education, self::Government], true);
    }
}
