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
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
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

        // Dedicated disk for backup relay archives to S3 Glacier.
        // Distinct from the general-purpose 's3' disk so backup credentials
        // can be isolated and configured independently.
        's3-backup-relay' => [
            'driver' => 's3',
            'key' => env('S3_BACKUP_RELAY_KEY', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('S3_BACKUP_RELAY_SECRET', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('S3_BACKUP_RELAY_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
            'bucket' => env('S3_BACKUP_RELAY_BUCKET', env('AWS_BUCKET')),
            'url' => env('S3_BACKUP_RELAY_URL', env('AWS_URL')),
            'endpoint' => env('S3_BACKUP_RELAY_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('S3_BACKUP_RELAY_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
            'throw' => false,
            'report' => false,
        ],

        // DigitalOcean Spaces — S3-compatible. Endpoint is region-derived so we
        // build it from CLOCKWORK_DO_SPACES_REGION rather than requiring a full
        // URL in the env. Consumed by App\Services\DigitalOcean\SpacesClient
        // to enumerate SpinupWP backup files for the Companion Backups page.
        'do_spaces' => [
            'driver' => 's3',
            'key' => env('CLOCKWORK_DO_SPACES_KEY'),
            'secret' => env('CLOCKWORK_DO_SPACES_SECRET'),
            'region' => env('CLOCKWORK_DO_SPACES_REGION', 'nyc3'),
            'bucket' => env('CLOCKWORK_DO_SPACES_BUCKET', ''),
            'endpoint' => 'https://'.env('CLOCKWORK_DO_SPACES_REGION', 'nyc3').'.digitaloceanspaces.com',
            'use_path_style_endpoint' => false,
            'throw' => false,
            'report' => false,
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
