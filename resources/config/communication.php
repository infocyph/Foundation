<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | HTTP Clients
    |--------------------------------------------------------------------------
    |
    | "default_client" names the profile used when callers omit one. Every
    | client profile may define total and connection timeouts in seconds,
    | redirect handling, TLS verification, an optional CA bundle and proxy,
    | proxy credentials, User-Agent, maximum response bytes, and default headers.
    |
    | Keep "verifyPeer" and "verifyHost" enabled outside controlled tests.
    | "maxResponseBytes" may be null for the library default; set a bounded
    | value before processing responses from untrusted or variable-size sources.
    |
    | A profile may be named `default`. Typical total timeout, connection timeout,
    | and redirect limit values are 10, 10, and 5. Booleans accept `true|false`.
    | Example CA and proxy locations are `/etc/ssl/cacert.pem` and
    | `http://proxy.internal:8080`. A user agent may be `Acme/1.0`; a response
    | limit may be 10485760 bytes; a default Accept header may use
    | `application/json`.
    |
    */
    'http' => [
        'default_client' => env('COMMUNICATION_HTTP_DEFAULT_CLIENT', 'default'),
        'clients' => [
            'default' => [
                'timeoutSeconds' => env('COMMUNICATION_HTTP_TIMEOUT', 10),
                'connectTimeoutSeconds' => env('COMMUNICATION_HTTP_CONNECT_TIMEOUT', 10),
                'followRedirects' => env('COMMUNICATION_HTTP_FOLLOW_REDIRECTS', false),
                'maxRedirects' => env('COMMUNICATION_HTTP_MAX_REDIRECTS', 5),
                'verifyPeer' => env('COMMUNICATION_HTTP_VERIFY_PEER', true),
                'verifyHost' => env('COMMUNICATION_HTTP_VERIFY_HOST', true),
                'caBundle' => env('COMMUNICATION_HTTP_CA_BUNDLE'),
                'proxy' => env('COMMUNICATION_HTTP_PROXY'),
                'proxyUsername' => env('COMMUNICATION_HTTP_PROXY_USERNAME'),
                'proxyPassword' => env('COMMUNICATION_HTTP_PROXY_PASSWORD'),
                'userAgent' => env('COMMUNICATION_HTTP_USER_AGENT', 'Infbyte/1.0'),
                'maxResponseBytes' => env('COMMUNICATION_HTTP_MAX_RESPONSE_BYTES'),
                'defaultHeaders' => [],

                /*
                |------------------------------------------------------------------
                | HTTP Authentication
                |------------------------------------------------------------------
                |
                | "driver" selects none, header/API-key, query-key, bearer-token,
                | or basic authentication as supported by TalkingBytes. "header"
                | and "value" configure header credentials; "query_key" names the
                | query parameter; "token" supplies bearer credentials; and
                | "username"/"password" supply basic credentials.
                |
                | Drivers: `none|api_key|api_key_header|api-key-header|header|`
                | `api_key_query|api-key-query|query|basic|bearer`. Header example:
                | `X-Api-Key`; query key: `api_key`; credential values are secrets.
                |
                */
                'auth' => [
                    'driver' => env('COMMUNICATION_HTTP_AUTH_DRIVER', 'none'),
                    'header' => env('COMMUNICATION_HTTP_AUTH_HEADER', 'X-Api-Key'),
                    'value' => env('COMMUNICATION_HTTP_AUTH_VALUE'),
                    'query_key' => env('COMMUNICATION_HTTP_AUTH_QUERY_KEY', 'api_key'),
                    'token' => env('COMMUNICATION_HTTP_AUTH_TOKEN'),
                    'username' => env('COMMUNICATION_HTTP_AUTH_USERNAME'),
                    'password' => env('COMMUNICATION_HTTP_AUTH_PASSWORD'),
                ],

                /*
                |------------------------------------------------------------------
                | HTTP Cookies
                |------------------------------------------------------------------
                |
                | "enabled" allows the client profile to retain and send cookies.
                | Leave it disabled for stateless integrations. Values: `true|false`.
                |
                */
                'cookies' => [
                    'enabled' => env('COMMUNICATION_HTTP_COOKIES_ENABLED', false),
                ],

                /*
                |------------------------------------------------------------------
                | HTTP Retry Policy
                |------------------------------------------------------------------
                |
                | "enabled" activates bounded retries, "attempts" limits total
                | tries, "base_delay_ms" controls the initial delay, and
                | "max_retry_after_seconds" caps a remote Retry-After instruction.
                | Only retry operations that are safe or explicitly idempotent.
                | Enabled is `true|false`; numeric examples: `3`, `250`, and `30`.
                |
                */
                'retry' => [
                    'enabled' => env('COMMUNICATION_HTTP_RETRY_ENABLED', false),
                    'attempts' => env('COMMUNICATION_HTTP_RETRY_ATTEMPTS', 3),
                    'base_delay_ms' => env('COMMUNICATION_HTTP_RETRY_BASE_DELAY_MS', 250),
                    'max_retry_after_seconds' => env('COMMUNICATION_HTTP_RETRY_MAX_RETRY_AFTER_SECONDS', 30),
                ],

                /*
                |------------------------------------------------------------------
                | Client-Side Rate Limit
                |------------------------------------------------------------------
                |
                | When "enabled", permit at most "max_requests" during each
                | "per_seconds" window for this client profile. Enabled accepts
                | `true|false`; positive integer example: `60` requests per `60` seconds.
                |
                */
                'rate_limit' => [
                    'enabled' => env('COMMUNICATION_HTTP_RATE_LIMIT_ENABLED', false),
                    'max_requests' => env('COMMUNICATION_HTTP_RATE_LIMIT_MAX_REQUESTS', 60),
                    'per_seconds' => env('COMMUNICATION_HTTP_RATE_LIMIT_PER_SECONDS', 60),
                ],

                /*
                |------------------------------------------------------------------
                | Circuit Breaker
                |------------------------------------------------------------------
                |
                | `failure_threshold` opens the breaker after consecutive failures.
                | "cool_down_seconds" controls when recovery may be attempted.
                | The policy is inactive while "enabled" is false. Enabled accepts
                | `true|false`; examples: threshold `5`, cool-down `30` seconds.
                |
                */
                'circuit_breaker' => [
                    'enabled' => env('COMMUNICATION_HTTP_CIRCUIT_BREAKER_ENABLED', false),
                    'failure_threshold' => env('COMMUNICATION_HTTP_CIRCUIT_BREAKER_FAILURE_THRESHOLD', 5),
                    'cool_down_seconds' => env('COMMUNICATION_HTTP_CIRCUIT_BREAKER_COOL_DOWN_SECONDS', 30),
                ],

                /*
                |------------------------------------------------------------------
                | Idempotency
                |------------------------------------------------------------------
                |
                | "enabled" adds an idempotency key to supported requests and
                | "header" selects the outbound header name. Enabled accepts
                | `true|false`; header example: `Idempotency-Key`.
                |
                */
                'idempotency' => [
                    'enabled' => env('COMMUNICATION_HTTP_IDEMPOTENCY_ENABLED', false),
                    'header' => env('COMMUNICATION_HTTP_IDEMPOTENCY_HEADER', 'Idempotency-Key'),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | "default_outbound" and "default_inbound" select named profiles. Outbound
    | profiles name an "http_client", provide a "signing_secret", and may use
    | bounded retry keys: "enabled", "attempts", "base_delay_ms", and
    | "max_retry_after_seconds". Use a unique secret per integration.
    |
    | Inbound profiles use "secret" to verify signatures and
    | "max_age_seconds" to reject stale deliveries and limit replay exposure.
    | Replace the development placeholder before accepting production webhooks.
    | A profile and client may be named `default`; secrets are random high-entropy
    | strings. Enabled accepts `true|false`. Typical retry settings are 3 attempts,
    | a 250 millisecond base delay, and a 30 second Retry-After ceiling. An inbound
    | maximum age may be 300 seconds.
    |
    */
    'webhooks' => [
        'default_outbound' => env('COMMUNICATION_WEBHOOK_DEFAULT_OUTBOUND', 'default'),
        'default_inbound' => env('COMMUNICATION_WEBHOOK_DEFAULT_INBOUND', 'default'),
        'outbound' => [
            'default' => [
                'http_client' => env('COMMUNICATION_WEBHOOK_HTTP_CLIENT', env('COMMUNICATION_HTTP_DEFAULT_CLIENT', 'default')),
                'signing_secret' => env('COMMUNICATION_WEBHOOK_SIGNING_SECRET'),
                'retry' => [
                    'enabled' => env('COMMUNICATION_WEBHOOK_RETRY_ENABLED', false),
                    'attempts' => env('COMMUNICATION_WEBHOOK_RETRY_ATTEMPTS', 3),
                    'base_delay_ms' => env('COMMUNICATION_WEBHOOK_RETRY_BASE_DELAY_MS', 250),
                    'max_retry_after_seconds' => env('COMMUNICATION_WEBHOOK_RETRY_MAX_RETRY_AFTER_SECONDS', 30),
                ],
            ],
        ],
        'inbound' => [
            'default' => [
                'secret' => env('COMMUNICATION_WEBHOOK_SECRET', 'change-me'),
                'max_age_seconds' => env('COMMUNICATION_WEBHOOK_MAX_AGE_SECONDS', 300),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | gRPC Profiles
    |--------------------------------------------------------------------------
    |
    | "default_profile" selects the profile used implicitly. Each retry policy
    | has an "enabled" switch, bounded "attempts", an initial "base_delay_ms",
    | optional "max_delay_ms", and a 0-to-1 "jitter_ratio" used to distribute
    | concurrent retries. Retry only idempotent gRPC operations. Profile example:
    | `default`; enabled is `true|false`; attempts/delays example: `3`, `100`,
    | `5000` milliseconds; jitter is a decimal from `0.0` to `1.0`.
    |
    */
    'grpc' => [
        'default_profile' => env('COMMUNICATION_GRPC_DEFAULT_PROFILE', 'default'),
        'profiles' => [
            'default' => [
                'retry' => [
                    'enabled' => env('COMMUNICATION_GRPC_RETRY_ENABLED', false),
                    'attempts' => env('COMMUNICATION_GRPC_RETRY_ATTEMPTS', 3),
                    'base_delay_ms' => env('COMMUNICATION_GRPC_RETRY_BASE_DELAY_MS', 100),
                    'max_delay_ms' => env('COMMUNICATION_GRPC_RETRY_MAX_DELAY_MS'),
                    'jitter_ratio' => env('COMMUNICATION_GRPC_RETRY_JITTER_RATIO', 0.0),
                ],
            ],
        ],
    ],
];
