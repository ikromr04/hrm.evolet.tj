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
        ],

        // Every photograph the company keeps: the faces on the cards and the
        // pictures in the fleet's journal. Nothing of this is reachable from the
        // web root — a face is read through /employees/{id}/avatar/{file} and a
        // unit's picture through /equipment/{id}/photos/{id}, both of which ask
        // whether the person looking may see that card before they answer. A
        // static file asks nobody, which is why these do not sit beside the
        // stylesheets.
        'photos' => [
            'driver' => 'local',
            'root' => storage_path('app/photos'),
            'throw' => false,
        ],

        // The documents a card points at: a diploma scanned and kept beside the
        // record of where somebody studied. Private for the same reason the
        // photographs are, and more so — a diploma carries a name, a date of
        // birth and a registry number, and a file under the web root is read by
        // whoever holds its address without anybody being asked. Read through
        // /employees/{id}/educations/{id}/document, which asks first.
        'documents' => [
            'driver' => 'local',
            'root' => storage_path('app/documents'),
            'throw' => false,
        ],

        // Where photographs used to be kept, back when a URL was all it took to
        // read one. Still configured because the files are still there until
        // `hrm:photos-private` has been run on a server, and the serving code
        // looks here when the private disk does not have the file; see
        // App\Support\PhotoStore. Nothing writes here any more.
        //
        // Hence PUBLIC_DISK_ROOT: on shared hosting that forbids symlinks, or
        // where the web root is a folder apart from the application, the files
        // were written straight into …/public_html/storage rather than reached
        // through the symlink `storage:link` makes. That is where the command
        // has to find them. See docs/deploy-cpanel.md.
        'public' => [
            'driver' => 'local',
            'root' => env('PUBLIC_DISK_ROOT') ?: storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
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

    // Nothing. `storage:link` exists to put a folder of uploads inside the web
    // root, and the only uploads this application keeps are photographs, which
    // are precisely what must not be in there: see the `photos` disk above.
    // Left empty rather than deleted so that running the command changes
    // nothing instead of quietly reopening the hole.
    'links' => [],

];
