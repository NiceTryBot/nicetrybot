<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Batch files of request logs shipped by the edge nodes, imported by edge:import-logs. Any
        // S3-compatible store works: AWS S3 (no endpoint), DigitalOcean Spaces, MinIO, R2 (an
        // endpoint, addressed path-style unless EDGE_LOGS_PATH_STYLE says otherwise). With no
        // key/secret the AWS SDK finds credentials itself: on Vapor, the Lambda role's (including
        // its session token). An empty bucket (the default) means there is none: every line reaches
        // the app through /caddy/ingest instead, and edge:import-logs has nothing to do.
        'edge-logs' => [
            'driver' => 's3',
            'key' => env('EDGE_LOGS_KEY'),
            'secret' => env('EDGE_LOGS_SECRET'),
            'region' => env('EDGE_LOGS_REGION') ?: 'us-east-1',
            'bucket' => env('EDGE_LOGS_BUCKET', ''),
            'endpoint' => env('EDGE_LOGS_ENDPOINT') ?: null,
            'use_path_style_endpoint' => match (env('EDGE_LOGS_PATH_STYLE')) {
                null, '' => filled(env('EDGE_LOGS_ENDPOINT')),
                default => (bool) env('EDGE_LOGS_PATH_STYLE'),
            },
            'throw' => true,
            'report' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
