<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Foundation names application disks; Pathwise/Flysystem own their storage
    | engines and operations. This value selects the configured disk injected
    | when a caller requests the native FilesystemOperator without a disk name.
    |
    | It must match a key in "disks" below. Shipped values are
    | `local|public|uploads`; custom configured disk names are also valid.
    |
    */
    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | These are application-level Pathwise storage configurations. Foundation
    | resolves relative local roots against the application base path and mounts
    | each disk by name; Pathwise StorageFactory owns driver aliases, adapters,
    | Flysystem options and third-party driver construction.
    |
    | "local" stores private application data, "public" stores publishable
    | files, and "uploads" isolates user-provided content. Additional drivers
    | use Pathwise's native configuration and their corresponding Flysystem
    | adapter packages rather than Foundation-specific wrappers.
    |
    */
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => 'storage/app',
        ],
        'public' => [
            'driver' => 'local',
            'root' => 'storage/app/public',
        ],
        'uploads' => [
            'driver' => 'local',
            'root' => 'storage/uploads',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public Storage Links
    |--------------------------------------------------------------------------
    |
    | Each key is an application-relative link path and each value is its
    | application-relative target. StorageLinkManager resolves both through the
    | active application's PathManager, then enforces public/storage boundaries.
    |
    */
    'links' => [
        'public/storage' => 'storage/app/public',
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload Policy
    |--------------------------------------------------------------------------
    |
    | Foundation maps this application policy directly onto a native Pathwise
    | UploadProcessor. Upload validation, chunk handling, naming, malware hooks,
    | content inspection and final storage remain Pathwise behavior.
    |
    | "disk" and "directory" select the destination; "temp_directory" controls
    | staging and "use_date_directories" partitions final files by date.
    | "validation_profile" selects a native Pathwise validation profile.
    |
    | "allowed_file_types" contains accepted media types and
    | "allowed_extensions" is an extension allowlist. "blocked_extensions" is
    | always denied. Empty allowlists mean no additional allowlist restriction.
    | "max_file_size" and "max_chunk_size" are bytes; zero chunk count/size or
    | image dimensions means no configured limit for that constraint.
    |
    | "naming_strategy" selects generated filenames. Malware scanning must be
    | available when "require_malware_scan" is true. Strict content validation
    | is enabled by default and rejects MIME/extension or magic-signature
    | mismatches; disable it only for a deliberately relaxed upload policy.
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
        'strict_content_type_validation' => env('FILESYSTEM_UPLOAD_STRICT_CONTENT_TYPE_VALIDATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Download Policy
    |--------------------------------------------------------------------------
    |
    | Foundation maps this application policy onto a native Pathwise
    | DownloadProcessor. Path validation, metadata, ranges and stream copying
    | remain Pathwise behavior; Webrick conditional responses are composed by
    | Foundation's HTTP bridge.
    |
    | "disk" and "directory" select the source. "allowed_roots" constrains
    | resolved paths; extension allow/block lists restrict served file types.
    | "block_hidden_files" rejects dotfiles. "chunk_size" is the streaming read
    | size in bytes and "default_name" is used when no download name is given.
    | "force_attachment" controls Content-Disposition. "max_size" is a byte
    | ceiling where zero means unlimited, and "range_requests" enables partial
    | content responses for resumable or seekable downloads.
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
    | Offload headers are Foundation/Webrick application policy. Calling the
    | corresponding response method while its switch is disabled fails rather
    | than emitting a server-trusted header accidentally.
    |
    | Enable "x_sendfile.enabled" only for a trusted X-Sendfile-capable server;
    | X-Sendfile accepts local paths only. Enable "x_accel_redirect.enabled"
    | only after configuring the matching Nginx internal location.
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
