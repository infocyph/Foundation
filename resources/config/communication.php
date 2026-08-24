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
    | Keep "verifyPeer" and "verifyHost" enabled outside controlled tests.
    | "maxResponseBytes" may be null for the library default; set a bounded
    | value before processing responses from untrusted or variable-size sources.
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
                'userAgent' => env('COMMUNICATION_HTTP_USER_AGENT'),
                'maxResponseBytes' => env('COMMUNICATION_HTTP_MAX_RESPONSE_BYTES'),
                'defaultHeaders' => [],
                'auth' => [
                    'driver' => env('COMMUNICATION_HTTP_AUTH_DRIVER', 'none'),
                    'header' => env('COMMUNICATION_HTTP_AUTH_HEADER', 'X-Api-Key'),
                    'value' => env('COMMUNICATION_HTTP_AUTH_VALUE'),
                    'query_key' => env('COMMUNICATION_HTTP_AUTH_QUERY_KEY', 'api_key'),
                    'token' => env('COMMUNICATION_HTTP_AUTH_TOKEN'),
                    'username' => env('COMMUNICATION_HTTP_AUTH_USERNAME'),
                    'password' => env('COMMUNICATION_HTTP_AUTH_PASSWORD'),
                ],
                'cookies' => [
                    'enabled' => env('COMMUNICATION_HTTP_COOKIES_ENABLED', false),
                ],
                'retry' => [
                    'enabled' => env('COMMUNICATION_HTTP_RETRY_ENABLED', false),
                    'attempts' => env('COMMUNICATION_HTTP_RETRY_ATTEMPTS', 3),
                    'base_delay_ms' => env('COMMUNICATION_HTTP_RETRY_BASE_DELAY_MS', 250),
                    'max_retry_after_seconds' => env('COMMUNICATION_HTTP_RETRY_MAX_RETRY_AFTER_SECONDS', 30),
                ],
                'rate_limit' => [
                    'enabled' => env('COMMUNICATION_HTTP_RATE_LIMIT_ENABLED', false),
                    'max_requests' => env('COMMUNICATION_HTTP_RATE_LIMIT_MAX_REQUESTS', 60),
                    'per_seconds' => env('COMMUNICATION_HTTP_RATE_LIMIT_PER_SECONDS', 60),
                ],
                'circuit_breaker' => [
                    'enabled' => env('COMMUNICATION_HTTP_CIRCUIT_BREAKER_ENABLED', false),
                    'failure_threshold' => env('COMMUNICATION_HTTP_CIRCUIT_BREAKER_FAILURE_THRESHOLD', 5),
                    'cool_down_seconds' => env('COMMUNICATION_HTTP_CIRCUIT_BREAKER_COOL_DOWN_SECONDS', 30),
                ],
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
    | Outbound profiles select an HTTP profile, optional signing secret, and
    | retry policy. Inbound profiles select one secret (or a secret list in
    | application config) and a maximum accepted signature age. Replay state is
    | composed through CacheLayer when enabled; production WebhookReceiver
    | activation requires replay protection even if this development default is
    | false. Replace the development secret before accepting production traffic.
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
                'replay' => [
                    'enabled' => env_bool('COMMUNICATION_WEBHOOK_REPLAY_ENABLED', false),
                    'store' => env('COMMUNICATION_WEBHOOK_REPLAY_STORE'),
                    'ttl_seconds' => env_int('COMMUNICATION_WEBHOOK_REPLAY_TTL_SECONDS', 86_400),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | gRPC
    |--------------------------------------------------------------------------
    |
    | Outbound profiles configure TalkingBytes retry behavior. Foundation does
    | not own a gRPC transport: callers supply a native/generated invoker or a
    | callable and receive a native TalkingBytes GrpcClient.
    |
    | "inbound.handlers" is an application composition map. Keys are normalized
    | gRPC method names such as `/billing.Invoice/Get`; values are InterMix
    | service identifiers. Resolved services must be callable or implement
    | TalkingBytes GrpcInboundHandlerInterface. Keep this map scalar/class-string
    | based so configuration remains cache- and fork-safe.
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
        'inbound' => [
            'handlers' => [],
        ],
    ],
];
