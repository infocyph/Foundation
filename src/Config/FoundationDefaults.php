<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

final class FoundationDefaults
{
    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return [
            'app' => [
                'base_path' => getcwd() ?: '.',
                'container_alias' => null,
                'container' => [
                    'alias' => null,
                    'compiled' => null,
                    'debug_tracing' => [
                        'enabled' => false,
                        'level' => 'node',
                    ],
                    'environment' => null,
                    'lazy_loading' => false,
                    'request_scope' => true,
                ],
                'config_cache' => [
                    'type' => ConfigLoader::TYPE_SHARDED,
                ],
                'debug' => false,
                'env' => 'local',
                'env_files' => ['.env', '.env.local'],
                'load_env' => true,
                'name' => 'Foundation Application',
            ],
            'cache' => [
                'default' => 'memory',
                'prefix' => 'foundation:',
                'stores' => [
                    'file' => [
                        'driver' => 'file',
                        'namespace' => 'foundation-file',
                    ],
                    'local' => [
                        'driver' => 'local',
                        'namespace' => 'foundation-local',
                    ],
                    'memory' => [
                        'driver' => 'memory',
                        'namespace' => 'foundation-memory',
                    ],
                ],
            ],
            'database' => [
                'default' => null,
                'connections' => [],
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
                ],
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
                    'max_file_size' => 30720,
                    'max_image_height' => 0,
                    'max_image_width' => 0,
                    'naming_strategy' => 'hash',
                    'require_malware_scan' => false,
                    'strict_content_type_validation' => false,
                    'temp_directory' => null,
                    'use_date_directories' => false,
                    'validation_profile' => null,
                ],
            ],
            'ids' => [
                'default' => 'uuid7',
                'sequence' => [
                    'driver' => 'filesystem',
                    'directory' => null,
                    'wait_time' => 1000,
                    'max_attempts' => 1000,
                ],
                'ulid' => [
                    'mode' => 'monotonic',
                ],
                'nanoid' => [
                    'length' => 21,
                ],
                'cuid2' => [
                    'length' => 24,
                ],
                'opaque' => [
                    'length' => 12,
                    'salt' => '',
                ],
                'deterministic' => [
                    'length' => 24,
                    'namespace' => 'default',
                ],
                'snowflake' => [
                    'datacenter_id' => 0,
                    'worker_id' => 0,
                    'custom_epoch' => null,
                    'clock_backward_policy' => 'wait',
                    'output' => 'string',
                    'sequence' => [],
                ],
                'sonyflake' => [
                    'machine_id' => 0,
                    'custom_epoch' => null,
                    'clock_backward_policy' => 'wait',
                    'output' => 'string',
                    'sequence' => [],
                ],
                'tbsl' => [
                    'machine_id' => 0,
                    'sequenced' => false,
                    'clock_backward_policy' => 'wait',
                    'output' => 'string',
                    'sequence' => [],
                ],
                'randflake' => [
                    'node_id' => 0,
                    'lease_start' => 0,
                    'lease_end' => 0,
                    'secret' => 'change-me',
                    'output' => 'string',
                    'sequence' => [],
                ],
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
            'notifications' => [
                'auth' => [
                    'critical_types' => [
                        'password_reset_requested',
                        'email_verification_requested',
                        'passwordless_login_requested',
                        'mfa_challenge_requested',
                    ],
                    'fail_silently' => false,
                    'from' => null,
                    'dkim' => [
                        'algorithm' => 'rsa-sha256',
                        'domain' => null,
                        'enabled' => false,
                        'headers' => ['from', 'to', 'subject', 'date', 'message-id', 'mime-version', 'content-type'],
                        'private_key' => null,
                        'private_key_path' => null,
                        'selector' => null,
                    ],
                    'fallback' => [
                        'transports' => [],
                    ],
                    'rate_limit' => [
                        'enabled' => false,
                        'max_requests' => 60,
                        'per_seconds' => 60,
                    ],
                    'retry' => [
                        'delay_ms' => 250,
                        'enabled' => false,
                        'max_attempts' => 3,
                        'policy' => 'fixed',
                    ],
                    'transports' => [
                        'fake' => [],
                        'log' => [
                            'dailyFiles' => true,
                            'directory' => null,
                            'filenamePrefix' => 'auth',
                            'maxMessageBytes' => null,
                        ],
                        'mail' => [],
                        'null' => [],
                        'sendmail' => [
                            'extraArguments' => ['-t', '-i'],
                            'maxMessageBytes' => null,
                            'path' => '/usr/sbin/sendmail',
                            'timeoutSeconds' => 15,
                        ],
                        'smtp' => [
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
                            'directory' => 'storage/mail',
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
                    'templates' => [],
                    'transport' => 'null',
                ],
                'default_channel' => 'email',
                'channels' => [],
                'email' => [
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
            'providers' => [],
            'router' => [
                'auto_slash_redirect' => false,
                'cache' => null,
                'expose_url_services' => false,
                'files' => [
                    'web.php',
                    'api.php',
                    'auth.php',
                ],
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
            'security' => [
                'epicrypt' => [
                    'csrf' => [
                        'secret' => null,
                        'ttl_seconds' => 3600,
                    ],
                    'profile' => 'modern',
                    'files' => [
                        'chunk_size' => 8192,
                    ],
                    'integrity' => [
                        'algorithm' => 'sha256',
                    ],
                    'key_rings' => [],
                    'signed_urls' => [
                        'expires_param' => 'ep_exp',
                        'secret' => null,
                        'signature_param' => 'ep_sig',
                        'options' => [
                            'allow_absolute_urls' => true,
                            'allow_array_parameters' => false,
                            'allow_relative_urls' => false,
                            'allowed_hosts' => null,
                            'bind_host' => true,
                            'bind_scheme' => true,
                            'method' => null,
                        ],
                    ],
                    'tokens' => [
                        'secret' => null,
                        'ttl_seconds' => 900,
                    ],
                ],
                'signed_urls' => true,
            ],
            'validation' => [
                'database_connection' => null,
                'extend' => [],
                'fail_fast' => true,
                'schemas' => [],
            ],
        ];
    }
}
