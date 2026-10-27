<?php

namespace App\Enums;

/**
 * A flow report: the stages a Sankey diagram traces requests or visits through.
 */
enum TrafficFlow: string
{
    case Identity = 'identity';
    case Origins = 'origins';
    case Requests = 'requests';
    case Journeys = 'journeys';

    /**
     * Get the display label for the report.
     */
    public function label(): string
    {
        return match ($this) {
            self::Identity => __('Network → fingerprint → user agent'),
            self::Origins => __('Country → network → outcome'),
            self::Requests => __('Fingerprint → user agent → path'),
            self::Journeys => __('Visitor journeys'),
        };
    }

    /**
     * Get the short label used in the report switcher.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Identity => __('Who sends what'),
            self::Origins => __('Where from, how it ends'),
            self::Requests => __('What it asks for'),
            self::Journeys => __('Journeys'),
        };
    }

    /**
     * Get a short explanation of what the report shows.
     */
    public function description(): string
    {
        return match ($this) {
            self::Identity => __('Which networks run which TLS software, and what browser it claims to be. Many user agents behind one fingerprint is a script rotating names.'),
            self::Origins => __('Where requests geolocate, which network carried them, and what the edge or origin answered.'),
            self::Requests => __('What each TLS client claimed to be and which paths it requested.'),
            self::Journeys => __('Paths visitors took, step by step. A visitor is one IP and TLS fingerprint; a pause of 30 minutes starts a new visit.'),
        };
    }

    /**
     * Get the stage titles, left to right.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::Identity => [__('Network'), __('TLS fingerprint'), __('User agent')],
            self::Origins => [__('Country'), __('Network'), __('Outcome')],
            self::Requests => [__('TLS fingerprint'), __('User agent'), __('Path')],
            self::Journeys => [__('Entry'), __('Step 2'), __('Step 3'), __('Step 4')],
        };
    }

    /**
     * Determine if the report has a stage of request paths, which can be grouped by category.
     */
    public function hasPathStage(): bool
    {
        return in_array($this, [self::Requests, self::Journeys], true);
    }

    /**
     * Get what each path's value counts.
     */
    public function unit(): string
    {
        return $this === self::Journeys ? __('visits') : __('requests');
    }
}
