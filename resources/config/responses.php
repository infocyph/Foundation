<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | JsonDispatch 3.0.0
    |--------------------------------------------------------------------------
    |
    | Foundation pins its envelope implementation to JsonDispatch `3.0.0`.
    | "vendor" is a lowercase media-type token such as `infocyph` or `acme`
    | and produces `application/vnd.<vendor>.jd.v3+json`.
    |
    | "application_version" is the exact application API version returned in
    | `X-Api-Version-Selected`, for example `1.0.0` or `2026-07-30`.
    |
    | "tunnel_errors" accepts `true|false` and defaults false. When false,
    | fail/error envelopes use their native `4xx|5xx` HTTP status. Enable true
    | only for a controlled transport that cannot carry those statuses. The
    | response then uses HTTP 200 plus matching `status_code`,
    | `X-JD-Status-Code`, and `Cache-Control: no-store` values as required by
    | the restricted-transport profile. Success responses are never tunneled.
    |
    */
    'json_dispatch' => [
        'vendor' => env_string('JSON_DISPATCH_VENDOR', 'infocyph'),
        'application_version' => env_string('API_VERSION', '1.0.0'),
        'tunnel_errors' => env_bool('JSON_DISPATCH_TUNNEL_ERRORS', false),
    ],
];
