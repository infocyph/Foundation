<?php

declare(strict_types=1);

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    // Pathwise StorageContext owns named filesystem lifecycle. Foundation only
    // resolves relative local roots against the active application base path.
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

    // Foundation supplies application layout; Pathwise SafeSymlinkManager owns
    // containment, link creation/status/removal and mismatch protection.
    'links' => [
        'public/storage' => 'storage/app/public',
    ],

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
        // Pathwise 4.1 UNTRUSTED_DATA requires finite chunk limits. Explicit
        // zero values remain safe: the trust profile restores bounded defaults.
        'max_chunk_count' => env('FILESYSTEM_UPLOAD_MAX_CHUNK_COUNT', 1_000),
        'max_chunk_size' => env('FILESYSTEM_UPLOAD_MAX_CHUNK_SIZE', 8 * 1024 * 1024),
        'max_image_width' => env('FILESYSTEM_UPLOAD_MAX_IMAGE_WIDTH', 0),
        'max_image_height' => env('FILESYSTEM_UPLOAD_MAX_IMAGE_HEIGHT', 0),

        // off | when_configured | required. The default scans whenever an
        // application scanner is configured without making scanner software a
        // requirement for every Foundation installation.
        'malware_scan' => [
            'mode' => env('FILESYSTEM_UPLOAD_MALWARE_SCAN_MODE', 'when_configured'),
            // null auto-discovers a MalwareScannerInterface binding; "service"
            // requires one; "clamav" uses Pathwise's bounded clamd INSTREAM.
            'driver' => env('FILESYSTEM_UPLOAD_MALWARE_SCAN_DRIVER'),
            'clamav' => [
                'endpoint' => env('FILESYSTEM_UPLOAD_CLAMAV_ENDPOINT', 'unix:///run/clamav/clamd.ctl'),
                'connect_timeout_seconds' => env('FILESYSTEM_UPLOAD_CLAMAV_CONNECT_TIMEOUT', 2.0),
                'io_timeout_seconds' => env('FILESYSTEM_UPLOAD_CLAMAV_IO_TIMEOUT', 30.0),
                'chunk_size' => env('FILESYSTEM_UPLOAD_CLAMAV_CHUNK_SIZE', 65_536),
                'max_response_bytes' => env('FILESYSTEM_UPLOAD_CLAMAV_MAX_RESPONSE_BYTES', 8_192),
                'max_stream_bytes' => env('FILESYSTEM_UPLOAD_CLAMAV_MAX_STREAM_BYTES', 268_435_456),
                'allow_remote_tcp' => env('FILESYSTEM_UPLOAD_CLAMAV_ALLOW_REMOTE_TCP', false),
            ],
        ],
    ],

    // Foundation selects the public root; Pathwise 4.1 owns containment.
    'public_files' => [
        'root' => env('FILESYSTEM_PUBLIC_ROOT', 'public'),
        'symlink_policy' => env('FILESYSTEM_PUBLIC_SYMLINK_POLICY', 'reject'),
    ],

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

    'offload' => [
        'x_sendfile' => [
            'enabled' => env('FILESYSTEM_OFFLOAD_X_SENDFILE', false),
        ],
        'x_accel_redirect' => [
            'enabled' => env('FILESYSTEM_OFFLOAD_X_ACCEL_REDIRECT', false),
        ],
    ],
];
