<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Log Driver
    |--------------------------------------------------------------------------
    |
    | "driver" accepts `null|file|error_log`. `null` discards records and is
    | the dependency-free development default. `file` appends one JSON object
    | per line to "path". `error_log` sends the same JSON records through
    | PHP's configured error log. Applications may replace LoggerInterface in
    | a provider to use any other PSR-3 implementation.
    |
    | "path" is null to use `storage/logs/foundation.log`, or an absolute or
    | application-resolved writable filename such as
    | `/var/log/infbyte/application.jsonl`. It is read only by the `file`
    | driver.
    |
    */
    'driver' => env_string('LOG_DRIVER', 'null'),
    'path' => env('LOG_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Minimum Severity
    |--------------------------------------------------------------------------
    |
    | "level" accepts the PSR-3 levels
    | `debug|info|notice|warning|error|critical|alert|emergency`. Records below
    | this threshold are skipped before context normalization. Typical values
    | are `debug` locally and `warning` or `error` in production.
    |
    */
    'level' => env_string('LOG_LEVEL', 'warning'),

    /*
    |--------------------------------------------------------------------------
    | Context Redaction
    |--------------------------------------------------------------------------
    |
    | "redact" is a list of case-insensitive key fragments. Matching context
    | values at any nesting level become `[REDACTED]`. Add application-specific
    | fragments such as `api_key`, `credential`, or `private_key`; never place
    | actual secret values in this list.
    |
    */
    'redact' => [
        'authorization',
        'cookie',
        'password',
        'secret',
        'token',
    ],

    /**
     * Exception Detail
     *
     * "include_message" and "include_trace" accept `true|false` and default to
     * false. "include_message" permits exception messages in operational logs.
     * leave it false when a message may contain request or database values.
     * "include_trace" records a stack trace and should normally be enabled only
     * temporarily.
     *
     * "ignore" is a list of available Throwable class names; subclasses are
     * ignored.
     *
     * Example:
     * `[App\Exception\ExpectedProbeFailure::class]`
     * "sample_rate" accepts a finite number from `0.0` (discard all) through
     * `1.0` (report all) and defaults to `1.0`.
     *
     * "throttle_seconds" is a non-negative integer window in seconds. `0`
     * disables repeated-exception throttling. "throttle_limit" is the positive
     * maximum reports for one exception class/file/line/status signature in
     * that window and defaults to `1`. The per-process signature table is
     * bounded. HTTP responses remain controlled separately by `app.debug`.
     */
    'exceptions' => [
        'include_message' => env_bool('LOG_EXCEPTION_MESSAGES', false),
        'include_trace' => env_bool('LOG_EXCEPTION_TRACES', false),
        'ignore' => [],
        'sample_rate' => 1.0,
        'throttle_seconds' => 0,
        'throttle_limit' => 1,
    ],
];
