<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | This value names the disk used when filesystem calls do not select one.
    | It must match a key in the "disks" collection below. Shipped values:
    | `local|public|uploads`; custom configured disk names are also valid.
    |
    */
    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Each disk declares its storage "driver" and root directory. "local"
    | stores private application data, "public" stores publishable files, and
    | "uploads" isolates user-provided content from other application files.
    | The shipped driver is `local`; root examples are `storage/app` and
    | `storage/uploads`. Additional values depend on registered adapters.
    |
    */
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
        ],
        'uploads' => [
            'driver' => 'local',
            'root' => storage_path('uploads'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload Policy
    |--------------------------------------------------------------------------
    |
    | "disk" and "directory" select the destination; "temp_directory" controls
    | staging and "use_date_directories" partitions final files by date.
    | "validation_profile" selects an optional registered validation profile.
    |
    | "allowed_file_types" contains accepted media types and
    | "allowed_extensions" is an extension allowlist. "blocked_extensions" is
    | always denied. Empty allowlists mean no additional allowlist restriction.
    | "max_file_size" and "max_chunk_size" are bytes; zero chunk count/size or
    | image dimensions means no configured limit for that constraint.
    |
    | "naming_strategy" selects generated filenames. Malware scanning must be
    | available when "require_malware_scan" is true. Strict content-type
    | validation rejects files whose detected type does not match expectations.
    |
    | Disk example: `uploads`; directory example: `avatars`; temp example:
    | `/var/tmp/acme-uploads`. Validation profiles: `image|video|document` or
    | null. MIME example: `image/png`; extension example: `png`. Naming strategy:
    | `hash|timestamp`. All switches accept `true|false`. Size values are bytes,
    | count/dimension values are integers, and `0` disables the relevant limit.
    |
    */
    'uploads' => [
        'disk' => env('FILESYSTEM_UPLOAD_DISK', 'uploads'),
        'directory' => env('FILESYSTEM_UPLOAD_DIRECTORY', ''),
        'temp_directory' => env('FILESYSTEM_UPLOAD_TEMP_DIRECTORY'),
        'use_date_directories' => env('FILESYSTEM_UPLOAD_USE_DATE_DIRECTORIES', false),
        'validation_profile' => env('FILESYSTEM_UPLOAD_VALIDATION_PROFILE'),
        'allowed_file_types' => [],
        'allowed_extensions' => [],
        'blocked_extensions' => ['php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com'],
        'max_file_size' => env('FILESYSTEM_UPLOAD_MAX_FILE_SIZE', 5 * 1024 * 1024),
        'max_chunk_count' => env('FILESYSTEM_UPLOAD_MAX_CHUNK_COUNT', 0),
        'max_chunk_size' => env('FILESYSTEM_UPLOAD_MAX_CHUNK_SIZE', 0),
        'max_image_width' => env('FILESYSTEM_UPLOAD_MAX_IMAGE_WIDTH', 0),
        'max_image_height' => env('FILESYSTEM_UPLOAD_MAX_IMAGE_HEIGHT', 0),
        'naming_strategy' => env('FILESYSTEM_UPLOAD_NAMING_STRATEGY', 'hash'),
        'require_malware_scan' => env('FILESYSTEM_UPLOAD_REQUIRE_MALWARE_SCAN', false),
        'strict_content_type_validation' => env('FILESYSTEM_UPLOAD_STRICT_CONTENT_TYPE_VALIDATION', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Download Policy
    |--------------------------------------------------------------------------
    |
    | "disk" and "directory" select the source. "allowed_roots" constrains
    | resolved paths; extension allow/block lists restrict served file types.
    | "block_hidden_files" rejects dotfiles. "chunk_size" is the streaming read
    | size in bytes and "default_name" is used when no download name is given.
    |
    | "force_attachment" controls Content-Disposition. "max_size" is a byte
    | ceiling where zero means unlimited, and "range_requests" enables partial
    | content responses for resumable or seekable downloads.
    |
    | Disk example: `uploads`; directory/root example: `exports`; extension
    | example: `pdf`; chunk example: `8192` bytes; filename example:
    | `report.pdf`. Boolean switches accept `true|false`; max size is bytes and
    | `0` disables that ceiling.
    |
    */
    'downloads' => [
        'disk' => env('FILESYSTEM_DOWNLOAD_DISK', 'uploads'),
        'directory' => env('FILESYSTEM_DOWNLOAD_DIRECTORY', ''),
        'allowed_roots' => [],
        'allowed_extensions' => [],
        'blocked_extensions' => ['php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com'],
        'block_hidden_files' => env('FILESYSTEM_DOWNLOAD_BLOCK_HIDDEN', true),
        'chunk_size' => env('FILESYSTEM_DOWNLOAD_CHUNK_SIZE', 8192),
        'default_name' => env('FILESYSTEM_DOWNLOAD_DEFAULT_NAME', 'download.bin'),
        'force_attachment' => env('FILESYSTEM_DOWNLOAD_FORCE_ATTACHMENT', true),
        'max_size' => env('FILESYSTEM_DOWNLOAD_MAX_SIZE', 0),
        'range_requests' => env('FILESYSTEM_DOWNLOAD_RANGE_REQUESTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web-Server Offload
    |--------------------------------------------------------------------------
    |
    | Enable "x_sendfile.enabled" for a trusted X-Sendfile-capable server or
    | "x_accel_redirect.enabled" for an Nginx internal-location deployment.
    | Leave both disabled until the corresponding server mapping is configured.
    | Both enable keys accept `true|false`.
    |
    */
    'offload' => [
        'x_sendfile' => [
            'enabled' => env('FILESYSTEM_OFFLOAD_X_SENDFILE', false),
        ],
        'x_accel_redirect' => [
            'enabled' => env('FILESYSTEM_OFFLOAD_X_ACCEL_REDIRECT', false),
        ],
    ],
];
