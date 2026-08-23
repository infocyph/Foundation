<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

/**
 * Dependency-free Foundation runtime defaults.
 *
 * Keep this graph limited to Foundation-owned policy and the minimal native
 * specialist descriptors required for a usable local application. Published
 * config files may expose additional optional backends, but publishing config
 * must not change the semantics of the defaults represented here.
 */
final class FoundationDefaults
{
    /** @return array<string, mixed> */
    public static function all(): array
    {
        return [
            'app' => [
                'base_path' => getcwd() ?: '.',
                'container' => [
                    'alias' => null,
                    'compiled' => 'bootstrap/cache/container.php',
                    'compiled_activation' => 'off',
                    'debug_tracing' => [
                        'enabled' => false,
                        'level' => 'node',
                    ],
                    'environment' => null,
                    'lazy_loading' => true,
                ],
                'config_cache' => [
                    'type' => ConfigLoader::TYPE_SHARDED,
                ],
                'debug' => false,
                'env' => 'local',
                'env_files' => ['.env', '.env.local'],
                'load_env' => true,
                'name' => 'Foundation Application',
                'topology' => DeploymentTopology::SINGLE_NODE->value,
            ],
            'cache' => [
                'default' => 'local',
                'default_counter' => null,
                'prefix' => 'foundation:cache:',
                'lock' => [
                    'driver' => null,
                    'store' => null,
                    'path' => 'storage/cache/locks',
                    'prefix' => 'foundation:cache:lock:',
                    'retry_sleep_micros' => 50_000,
                ],
                'compression' => [
                    'threshold_bytes' => null,
                    'level' => 6,
                ],
                'security' => [
                    'integrity_key' => null,
                    'max_payload_bytes' => 8_388_608,
                ],
                'serialization' => [
                    'allow_closure_payloads' => false,
                    'allow_object_payloads' => false,
                ],
                'connections' => [
                    'redis' => [
                        'driver' => 'redis',
                        'dsn' => 'redis://127.0.0.1:6379',
                    ],
                    'valkey' => [
                        'driver' => 'valkey',
                        'dsn' => 'valkey://127.0.0.1:6379',
                    ],
                ],
                'stores' => [
                    'file' => [
                        'driver' => 'file',
                        'path' => 'storage/cache/file',
                    ],
                    'local' => [
                        'driver' => 'local',
                        'path' => 'storage/cache/local',
                    ],
                    'memory' => [
                        'driver' => 'memory',
                    ],
                    'null' => [
                        'driver' => 'null',
                    ],
                ],
                'counters' => [],
                'transports' => [],
                'clusters' => [],
            ],
            'communication' => [
                'http' => [
                    'default_client' => 'default',
                    'clients' => [
                        'default' => [
                            'timeoutSeconds' => 10,
                            'connectTimeoutSeconds' => 10,
                            'followRedirects' => false,
                            'maxRedirects' => 5,
                            'verifyPeer' => true,
                            'verifyHost' => true,
                            'caBundle' => null,
                            'proxy' => null,
                            'proxyUsername' => null,
                            'proxyPassword' => null,
                            'userAgent' => null,
                            'maxResponseBytes' => null,
                            'defaultHeaders' => [],
                            'auth' => [
                                'driver' => 'none',
                                'header' => 'X-Api-Key',
                                'value' => null,
                                'query_key' => 'api_key',
                                'token' => null,
                                'username' => null,
                                'password' => null,
                            ],
                            'cookies' => [
                                'enabled' => false,
                            ],
                            'retry' => [
                                'enabled' => false,
                                'attempts' => 3,
                                'base_delay_ms' => 250,
                                'max_retry_after_seconds' => 30,
                            ],
                            'rate_limit' => [
                                'enabled' => false,
                                'max_requests' => 60,
                                'per_seconds' => 60,
                            ],
                            'circuit_breaker' => [
                                'enabled' => false,
                                'failure_threshold' => 5,
                                'cool_down_seconds' => 30,
                            ],
                            'idempotency' => [
                                'enabled' => false,
                                'header' => 'Idempotency-Key',
                            ],
                        ],
                    ],
                ],
                'webhooks' => [
                    'default_outbound' => 'default',
                    'default_inbound' => 'default',
                    'outbound' => [
                        'default' => [
                            'http_client' => 'default',
                            'signing_secret' => null,
                            'retry' => [
                                'enabled' => false,
                                'attempts' => 3,
                                'base_delay_ms' => 250,
                                'max_retry_after_seconds' => 30,
                            ],
                        ],
                    ],
                    'inbound' => [
                        'default' => [
                            'secret' => 'change-me',
                            'max_age_seconds' => 300,
                            'replay' => [
                                'enabled' => false,
                                'store' => null,
                                'ttl_seconds' => 86_400,
                            ],
                        ],
                    ],
                ],
                'grpc' => [
                    'default_profile' => 'default',
                    'profiles' => [
                        'default' => [
                            'retry' => [
                                'enabled' => false,
                                'attempts' => 3,
                                'base_delay_ms' => 100,
                                'max_delay_ms' => null,
                                'jitter_ratio' => 0.0,
                            ],
                        ],
                    ],
                    'inbound' => [
                        'handlers' => [],
                    ],
                ],
            ],
            'database' => [
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => [
                        'driver' => 'sqlite',
                        'database' => 'database/database.sqlite',
                    ],
                ],
                'migrations' => [
                    'classes' => [],
                    'table' => 'migrations',
                    'lock_store' => null,
                    'lock_wait_seconds' => 10.0,
                    'lock_lease_seconds' => 300.0,
                ],
                'seeders' => [],
            ],
            'filesystem' => [
                'default' => 'local',
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
                'links' => [
                    'public/storage' => 'storage/app/public',
                ],
                'downloads' => [
                    'allowed_extensions' => [],
                    'allowed_roots' => [],
                    'block_hidden_files' => true,
                    'blocked_extensions' => ['php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com'],
                    'chunk_size' => 8192,
                    'default_name' => 'download.bin',
                    'directory' => '',
                    'disk' => 'uploads',
                    'force_attachment' => true,
                    'max_size' => 0,
                    'range_requests' => true,
                ],
                'offload' => [
                    'x_accel_redirect' => [
                        'enabled' => false,
                    ],
                    'x_sendfile' => [
                        'enabled' => false,
                    ],
                ],
                'uploads' => [
                    'allowed_extensions' => [],
                    'allowed_file_types' => [],
                    'blocked_extensions' => ['php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'com'],
                    'directory' => '',
                    'disk' => 'uploads',
                    'max_chunk_count' => 0,
                    'max_chunk_size' => 0,
                    'max_file_size' => 5 * 1024 * 1024,
                    'max_image_height' => 0,
                    'max_image_width' => 0,
                    'naming_strategy' => 'hash',
                    'require_malware_scan' => false,
                    'strict_content_type_validation' => true,
                    'temp_directory' => null,
                    'use_date_directories' => false,
                    'validation_profile' => null,
                ],
            ],
            'ids' => [
                'auth' => [
                    'account' => 'uuid7',
                    'audit_event' => 'uuid7',
                    'challenge' => 'uuid7',
                    'correlation' => 'ulid',
                    'credential' => 'uuid7',
                    'device' => 'uuid7',
                    'grant' => 'uuid7',
                    'permission' => 'uuid7',
                    'role' => 'uuid7',
                    'session' => 'uuid7',
                ],
            ],
            'logging' => [
                'driver' => 'null',
                'level' => 'warning',
                'path' => null,
                'redact' => [
                    'authorization',
                    'cookie',
                    'password',
                    'secret',
                    'token',
                ],
                'exceptions' => [
                    'include_message' => false,
                    'include_trace' => false,
                    'ignore' => [],
                    'sample_rate' => 1.0,
                    'throttle_seconds' => 0,
                    'throttle_limit' => 1,
                ],
            ],
            'messaging' => [
                'default_route' => [
                    'transport' => 'sync',
                    'queue' => 'default',
                    'delay_seconds' => 0.0,
                ],
                'routes' => [],
                'handlers' => [],
                'handler_middleware' => [],
                'job_middleware' => [],
                'listeners' => [],
                'scheduled_messages' => [],
                'consumer' => [
                    'transport' => 'memory',
                ],
                'retry' => [
                    'maximum_attempts' => 3,
                    'initial_delay_seconds' => 1.0,
                    'multiplier' => 2.0,
                    'maximum_delay_seconds' => 60.0,
                    'jitter_ratio' => 0.0,
                ],
                'workers' => [],
                'forward_auth_events' => false,
            ],
            'notifications' => [
                'auth' => [
                    'critical_types' => [],
                    'fail_silently' => false,
                    'from' => null,
                    'sender' => 'auth',
                    'templates' => [],
                ],
                'email' => [
                    'default_sender' => 'default',
                    'senders' => [
                        'default' => self::emailSenderDefaults('null'),
                        'auth' => self::emailSenderDefaults('null'),
                    ],
                    'transports' => [
                        'fake' => [
                            'driver' => 'fake',
                        ],
                        'log' => [
                            'driver' => 'log',
                            'dailyFiles' => true,
                            'directory' => null,
                            'filenamePrefix' => 'email',
                            'maxMessageBytes' => null,
                        ],
                        'mail' => [
                            'driver' => 'mail',
                        ],
                        'null' => [
                            'driver' => 'null',
                        ],
                        'sendmail' => [
                            'driver' => 'sendmail',
                            'extraArguments' => ['-t', '-i'],
                            'maxMessageBytes' => null,
                            'path' => '/usr/sbin/sendmail',
                            'timeoutSeconds' => 15,
                        ],
                        'smtp' => [
                            'driver' => 'smtp',
                            'allowEightBitMime' => true,
                            'authMechanism' => 'auto',
                            'captureTranscript' => false,
                            'credentials' => [
                                'password' => null,
                                'username' => null,
                            ],
                            'host' => '',
                            'localDomain' => 'localhost',
                            'maxMessageBytes' => null,
                            'port' => 587,
                            'security' => 'starttls-required',
                            'timeoutSeconds' => 10,
                            'utf8Policy' => 'auto',
                        ],
                        'spool' => [
                            'driver' => 'spool',
                            'directory' => 'storage/mail/outbound',
                            'extension' => 'eml',
                            'lockBeforeRead' => false,
                            'maxMessageBytes' => null,
                            'maxMessages' => 20,
                            'newerThanSeconds' => null,
                            'olderThanSeconds' => null,
                            'processingDirectory' => null,
                            'writeMetadata' => true,
                        ],
                    ],
                    'parsing' => [
                        'limits' => [
                            'maxAttachmentBytes' => 25 * 1024 * 1024,
                            'maxAttachmentCount' => 500,
                            'maxDecodedBodyBytes' => 10 * 1024 * 1024,
                            'maxHeaderBytes' => 131072,
                            'maxHeaderCount' => 2000,
                            'maxMessageBytes' => 10 * 1024 * 1024,
                            'maxMimeDepth' => 20,
                            'maxMimeParts' => 500,
                        ],
                    ],
                    'receivers' => [
                        'spool' => [
                            'default' => [
                                'deleteAfterRead' => false,
                                'directory' => 'storage/mail/inbound',
                                'extension' => 'eml',
                                'failedDirectory' => 'storage/mail/failed',
                                'lockBeforeRead' => false,
                                'maxMessageBytes' => null,
                                'maxMessages' => 20,
                                'moveAfterRead' => 'storage/mail/processed',
                                'newerThanSeconds' => null,
                                'olderThanSeconds' => null,
                                'processingDirectory' => 'storage/mail/processing',
                                'writeMetadata' => true,
                            ],
                        ],
                    ],
                    'mailboxes' => [
                        'imap' => [
                            'default' => [
                                'defaultFolder' => 'INBOX',
                                'host' => '',
                                'password' => '',
                                'port' => 993,
                                'security' => 'ssl',
                                'timeoutSeconds' => 10,
                                'username' => '',
                            ],
                        ],
                        'pop3' => [
                            'default' => [
                                'host' => '',
                                'password' => '',
                                'port' => 110,
                                'security' => 'none',
                                'timeoutSeconds' => 10,
                                'username' => '',
                            ],
                        ],
                    ],
                ],
            ],
            'operations' => [
                'history' => [
                    'enabled' => false,
                    'path' => 'storage/logs/executions.jsonl',
                    'max_bytes' => 16_777_216,
                    'retained_files' => 7,
                ],
                'maintenance' => [
                    'driver' => 'file',
                    'path' => 'storage/framework/maintenance.json',
                    'store' => null,
                    'key' => 'foundation:maintenance',
                ],
                'runtime_control' => [
                    'driver' => 'file',
                    'path' => 'storage/framework/runtime-control.json',
                    'store' => null,
                    'key' => 'foundation:runtime-control',
                ],
                'runtime_registry' => [
                    'path' => 'storage/framework/runtime',
                    'stale_seconds' => 15,
                ],
            ],
            'paths' => [
                'app' => 'app',
                'auto_create_runtime_directories' => false,
                'bootstrap' => 'bootstrap',
                'cache' => 'storage/cache',
                'config' => 'config',
                'database' => 'database',
                'logs' => 'storage/logs',
                'providers' => 'bootstrap/providers.php',
                'public' => 'public',
                'resources' => 'resources',
                'routes' => 'routes',
                'sessions' => 'storage/sessions',
                'storage' => 'storage',
                'uploads' => 'storage/uploads',
            ],
            'providers' => [
                'common' => [],
                'web' => [],
                'cli' => [],
                'worker' => [],
                'scheduler' => [],
            ],
            'router' => [
                'auto_slash_redirect' => false,
                'cache' => null,
                'expose_url_services' => false,
                'files' => ['web.php', 'api.php', 'auth.php'],
                'attributes' => [
                    'enabled' => false,
                    'classes' => [],
                    'controller_file_filter' => true,
                    'directories' => [],
                ],
                'matcher' => 'fused',
                'middleware' => [
                    'aliases' => [
                        'signed' => 'verify_signed_url',
                        'throttle' => 'throttle',
                    ],
                    'definitions' => [],
                    'globals' => [
                        'post' => [],
                        'pre' => [],
                    ],
                    'groups' => [],
                ],
                'signed_urls' => [
                    'default_ttl' => null,
                    'key' => null,
                    'options' => [],
                ],
                'url_base_uri' => '',
            ],
            'responses' => [
                'json_dispatch' => [
                    'vendor' => 'infocyph',
                    'application_version' => '1.0.0',
                    'tunnel_errors' => false,
                ],
            ],
            'security' => [
                'password' => [
                    'algorithm' => 'argon2id',
                    'cost' => 12,
                    'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
                    'threads' => PASSWORD_ARGON2_DEFAULT_THREADS,
                    'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
                ],
                'jwt' => [
                    'algorithm' => 'HS256',
                    'audience' => null,
                    'issuer' => null,
                    'maximum_lifetime_seconds' => 1_209_600,
                    'leeway_seconds' => 0,
                ],
            ],
            'session' => [
                'driver' => 'file',
                'lifetime' => 7_200,
                'max_payload_bytes' => 65_536,
                'cookie' => [
                    'name' => 'foundation_session',
                    'path' => '/',
                    'domain' => null,
                    'secure' => true,
                    'http_only' => true,
                    'same_site' => 'Lax',
                ],
                'stores' => [
                    'file' => [
                        'path' => 'storage/sessions',
                    ],
                    'cache' => [
                        'store' => null,
                    ],
                    'database' => [
                        'connection' => null,
                        'table' => 'sessions',
                    ],
                ],
                'lock' => [
                    'enabled' => false,
                    'store' => null,
                    'wait' => 2.0,
                    'lease' => 30.0,
                ],
                'csrf' => [
                    'header' => 'X-CSRF-Token',
                    'field' => '_token',
                    'check_origin' => true,
                    'origin' => null,
                ],
            ],
            'validation' => [
                'database_connection' => null,
                'fail_fast' => true,
                'defaults' => [
                    'allow_unknown' => true,
                    'strip_unknown' => false,
                    'strict' => false,
                    'nested' => false,
                    'nested_mode' => 'all',
                    'throw_on_failure' => false,
                    'locale' => null,
                    'locale_packs' => [],
                    'messages' => [],
                    'aliases' => [],
                    'sanitizers' => [],
                    'casts' => [],
                    'dto' => null,
                    'limits' => [
                        'max_depth' => 32,
                        'max_fields' => 10_000,
                        'max_wildcard_expansions' => 10_000,
                        'max_flattened_paths' => 10_000,
                    ],
                ],
                'schemas' => [],
                'extend' => [],
                'overrides' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function emailSenderDefaults(string $transport): array
    {
        return [
            'transport' => $transport,
            'fallback' => [
                'transports' => [],
            ],
            'retry' => [
                'enabled' => false,
                'policy' => 'fixed',
                'max_attempts' => 3,
                'delay_ms' => 250,
            ],
            'rate_limit' => [
                'enabled' => false,
                'max_requests' => 60,
                'per_seconds' => 60,
            ],
            'dkim' => [
                'enabled' => false,
                'domain' => null,
                'selector' => null,
                'private_key' => null,
                'private_key_path' => null,
                'headers' => ['from', 'to', 'subject', 'date', 'message-id', 'mime-version', 'content-type'],
                'algorithm' => 'rsa-sha256',
            ],
        ];
    }
}
