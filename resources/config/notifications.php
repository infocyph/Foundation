<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Notification Channel
    |--------------------------------------------------------------------------
    */
    'default_channel' => env('NOTIFICATIONS_DEFAULT_CHANNEL', 'email'),

    /*
    |--------------------------------------------------------------------------
    | Authentication Notification Policy
    |--------------------------------------------------------------------------
    |
    | Authentication owns notification semantics, not email transport. "sender"
    | selects one named profile from email.senders. "from" may override the
    | mapped message sender and "fail_silently" controls non-critical failures.
    | Critical types may be listed by AuthNotificationType value.
    |
    */
    'auth' => [
        'fail_silently' => env('NOTIFICATIONS_AUTH_FAIL_SILENTLY', false),
        'from' => env('NOTIFICATIONS_AUTH_FROM'),
        'sender' => env('NOTIFICATIONS_AUTH_SENDER', 'auth'),
        'critical_types' => [],
    ],

    'email' => [
        /*
        |--------------------------------------------------------------------------
        | Outbound Sender Profiles
        |--------------------------------------------------------------------------
        |
        | "default_sender" is injected as TalkingBytes Emailer when no profile is
        | selected. A sender chooses one transport and may wrap it with fallback,
        | retry, rate limiting and DKIM policy. Fallback entries name transports,
        | not sender profiles.
        |
        */
        'default_sender' => env('NOTIFICATIONS_EMAIL_DEFAULT_SENDER', 'default'),
        'senders' => [
            'default' => [
                'transport' => env('NOTIFICATIONS_EMAIL_TRANSPORT', 'null'),
                'fallback' => [
                    'transports' => [],
                ],
                'retry' => [
                    'enabled' => env('NOTIFICATIONS_EMAIL_RETRY_ENABLED', false),
                    'policy' => env('NOTIFICATIONS_EMAIL_RETRY_POLICY', 'fixed'),
                    'max_attempts' => env('NOTIFICATIONS_EMAIL_RETRY_MAX_ATTEMPTS', 3),
                    'delay_ms' => env('NOTIFICATIONS_EMAIL_RETRY_DELAY_MS', 250),
                ],
                'rate_limit' => [
                    'enabled' => env('NOTIFICATIONS_EMAIL_RATE_LIMIT_ENABLED', false),
                    'max_requests' => env('NOTIFICATIONS_EMAIL_RATE_LIMIT_MAX_REQUESTS', 60),
                    'per_seconds' => env('NOTIFICATIONS_EMAIL_RATE_LIMIT_PER_SECONDS', 60),
                ],
                'dkim' => [
                    'enabled' => env('NOTIFICATIONS_EMAIL_DKIM_ENABLED', false),
                    'domain' => env('NOTIFICATIONS_EMAIL_DKIM_DOMAIN'),
                    'selector' => env('NOTIFICATIONS_EMAIL_DKIM_SELECTOR'),
                    'private_key' => env('NOTIFICATIONS_EMAIL_DKIM_PRIVATE_KEY'),
                    'private_key_path' => env('NOTIFICATIONS_EMAIL_DKIM_PRIVATE_KEY_PATH'),
                    'headers' => ['from', 'to', 'subject', 'date', 'message-id', 'mime-version', 'content-type'],
                    'algorithm' => env('NOTIFICATIONS_EMAIL_DKIM_ALGORITHM', 'rsa-sha256'),
                ],
            ],
            'auth' => [
                'transport' => env('NOTIFICATIONS_AUTH_TRANSPORT', 'null'),
                'fallback' => [
                    'transports' => [],
                ],
                'retry' => [
                    'enabled' => env('NOTIFICATIONS_AUTH_RETRY_ENABLED', false),
                    'policy' => env('NOTIFICATIONS_AUTH_RETRY_POLICY', 'fixed'),
                    'max_attempts' => env('NOTIFICATIONS_AUTH_RETRY_MAX_ATTEMPTS', 3),
                    'delay_ms' => env('NOTIFICATIONS_AUTH_RETRY_DELAY_MS', 250),
                ],
                'rate_limit' => [
                    'enabled' => env('NOTIFICATIONS_AUTH_RATE_LIMIT_ENABLED', false),
                    'max_requests' => env('NOTIFICATIONS_AUTH_RATE_LIMIT_MAX_REQUESTS', 60),
                    'per_seconds' => env('NOTIFICATIONS_AUTH_RATE_LIMIT_PER_SECONDS', 60),
                ],
                'dkim' => [
                    'enabled' => env('NOTIFICATIONS_AUTH_DKIM_ENABLED', false),
                    'domain' => env('NOTIFICATIONS_AUTH_DKIM_DOMAIN'),
                    'selector' => env('NOTIFICATIONS_AUTH_DKIM_SELECTOR'),
                    'private_key' => env('NOTIFICATIONS_AUTH_DKIM_PRIVATE_KEY'),
                    'private_key_path' => env('NOTIFICATIONS_AUTH_DKIM_PRIVATE_KEY_PATH'),
                    'headers' => ['from', 'to', 'subject', 'date', 'message-id', 'mime-version', 'content-type'],
                    'algorithm' => env('NOTIFICATIONS_AUTH_DKIM_ALGORITHM', 'rsa-sha256'),
                ],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Outbound Transports
        |--------------------------------------------------------------------------
        |
        | Transport names are application-owned. "driver" selects the native
        | TalkingBytes implementation, so applications may define multiple SMTP,
        | spool or log transports without duplicating sender policy.
        |
        | Drivers: fake|log|mail|null|sendmail|smtp|spool.
        |
        */
        'transports' => [
            'fake' => [
                'driver' => 'fake',
            ],
            'log' => [
                'driver' => 'log',
                'dailyFiles' => env('NOTIFICATIONS_EMAIL_LOG_DAILY_FILES', true),
                'directory' => env('NOTIFICATIONS_EMAIL_LOG_DIRECTORY'),
                'filenamePrefix' => env('NOTIFICATIONS_EMAIL_LOG_FILENAME_PREFIX', 'email'),
                'maxMessageBytes' => env('NOTIFICATIONS_EMAIL_LOG_MAX_MESSAGE_BYTES'),
            ],
            'mail' => [
                'driver' => 'mail',
            ],
            'null' => [
                'driver' => 'null',
            ],
            'sendmail' => [
                'driver' => 'sendmail',
                'path' => env('NOTIFICATIONS_EMAIL_SENDMAIL_PATH', '/usr/sbin/sendmail'),
                'extraArguments' => ['-t', '-i'],
                'timeoutSeconds' => env('NOTIFICATIONS_EMAIL_SENDMAIL_TIMEOUT', 15),
                'maxMessageBytes' => env('NOTIFICATIONS_EMAIL_SENDMAIL_MAX_MESSAGE_BYTES'),
            ],
            'smtp' => [
                'driver' => 'smtp',
                'host' => env('NOTIFICATIONS_EMAIL_SMTP_HOST', ''),
                'port' => env('NOTIFICATIONS_EMAIL_SMTP_PORT', 587),
                'security' => env('NOTIFICATIONS_EMAIL_SMTP_SECURITY', 'starttls-required'),
                'timeoutSeconds' => env('NOTIFICATIONS_EMAIL_SMTP_TIMEOUT', 10),
                'localDomain' => env('NOTIFICATIONS_EMAIL_SMTP_LOCAL_DOMAIN', 'localhost'),
                'authMechanism' => env('NOTIFICATIONS_EMAIL_SMTP_AUTH_MECHANISM', 'auto'),
                'captureTranscript' => env('NOTIFICATIONS_EMAIL_SMTP_CAPTURE_TRANSCRIPT', false),
                'utf8Policy' => env('NOTIFICATIONS_EMAIL_SMTP_UTF8_POLICY', 'auto'),
                'allowEightBitMime' => env('NOTIFICATIONS_EMAIL_SMTP_ALLOW_EIGHT_BIT_MIME', true),
                'maxMessageBytes' => env('NOTIFICATIONS_EMAIL_SMTP_MAX_MESSAGE_BYTES'),
                'credentials' => [
                    'username' => env('NOTIFICATIONS_EMAIL_SMTP_USERNAME'),
                    'password' => env('NOTIFICATIONS_EMAIL_SMTP_PASSWORD'),
                ],
            ],
            'spool' => [
                'driver' => 'spool',
                'directory' => env('NOTIFICATIONS_EMAIL_OUTBOUND_SPOOL_DIRECTORY', storage_path('mail/outbound')),
                'processingDirectory' => env('NOTIFICATIONS_EMAIL_OUTBOUND_SPOOL_PROCESSING_DIRECTORY'),
                'writeMetadata' => env('NOTIFICATIONS_EMAIL_OUTBOUND_SPOOL_WRITE_METADATA', true),
                'extension' => env('NOTIFICATIONS_EMAIL_OUTBOUND_SPOOL_EXTENSION', 'eml'),
                'lockBeforeRead' => env('NOTIFICATIONS_EMAIL_OUTBOUND_SPOOL_LOCK_BEFORE_READ', false),
                'maxMessages' => env('NOTIFICATIONS_EMAIL_OUTBOUND_SPOOL_MAX_MESSAGES', 20),
                'olderThanSeconds' => env('NOTIFICATIONS_EMAIL_OUTBOUND_SPOOL_OLDER_THAN_SECONDS'),
                'newerThanSeconds' => env('NOTIFICATIONS_EMAIL_OUTBOUND_SPOOL_NEWER_THAN_SECONDS'),
                'maxMessageBytes' => env('NOTIFICATIONS_EMAIL_OUTBOUND_SPOOL_MAX_MESSAGE_BYTES'),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | MIME Parsing Limits
        |--------------------------------------------------------------------------
        */
        'parsing' => [
            'limits' => [
                'maxMessageBytes' => env('NOTIFICATIONS_EMAIL_MAX_MESSAGE_BYTES', 10 * 1024 * 1024),
                'maxAttachmentBytes' => env('NOTIFICATIONS_EMAIL_MAX_ATTACHMENT_BYTES', 25 * 1024 * 1024),
                'maxAttachmentCount' => env('NOTIFICATIONS_EMAIL_MAX_ATTACHMENT_COUNT', 500),
                'maxDecodedBodyBytes' => env('NOTIFICATIONS_EMAIL_MAX_DECODED_BODY_BYTES', 10 * 1024 * 1024),
                'maxMimeDepth' => env('NOTIFICATIONS_EMAIL_MAX_MIME_DEPTH', 20),
                'maxMimeParts' => env('NOTIFICATIONS_EMAIL_MAX_MIME_PARTS', 500),
                'maxHeaderBytes' => env('NOTIFICATIONS_EMAIL_MAX_HEADER_BYTES', 131072),
                'maxHeaderCount' => env('NOTIFICATIONS_EMAIL_MAX_HEADER_COUNT', 2000),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Inbound Spool Receivers
        |--------------------------------------------------------------------------
        */
        'receivers' => [
            'spool' => [
                'default' => [
                    'directory' => env('NOTIFICATIONS_EMAIL_SPOOL_DIRECTORY', storage_path('mail/inbound')),
                    'processingDirectory' => env('NOTIFICATIONS_EMAIL_SPOOL_PROCESSING_DIRECTORY', storage_path('mail/processing')),
                    'writeMetadata' => env('NOTIFICATIONS_EMAIL_SPOOL_WRITE_METADATA', true),
                    'extension' => env('NOTIFICATIONS_EMAIL_SPOOL_EXTENSION', 'eml'),
                    'lockBeforeRead' => env('NOTIFICATIONS_EMAIL_SPOOL_LOCK_BEFORE_READ', false),
                    'maxMessages' => env('NOTIFICATIONS_EMAIL_SPOOL_MAX_MESSAGES', 20),
                    'olderThanSeconds' => env('NOTIFICATIONS_EMAIL_SPOOL_OLDER_THAN_SECONDS'),
                    'newerThanSeconds' => env('NOTIFICATIONS_EMAIL_SPOOL_NEWER_THAN_SECONDS'),
                    'maxMessageBytes' => env('NOTIFICATIONS_EMAIL_SPOOL_MAX_MESSAGE_BYTES'),
                    'deleteAfterRead' => env('NOTIFICATIONS_EMAIL_SPOOL_DELETE_AFTER_READ', false),
                    'moveAfterRead' => env('NOTIFICATIONS_EMAIL_SPOOL_MOVE_AFTER_READ', storage_path('mail/processed')),
                    'failedDirectory' => env('NOTIFICATIONS_EMAIL_SPOOL_FAILED_DIRECTORY', storage_path('mail/failed')),
                ],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | IMAP and POP3 Mailbox Profiles
        |--------------------------------------------------------------------------
        */
        'mailboxes' => [
            'imap' => [
                'default' => [
                    'host' => env('NOTIFICATIONS_EMAIL_IMAP_HOST', ''),
                    'port' => env('NOTIFICATIONS_EMAIL_IMAP_PORT', 993),
                    'security' => env('NOTIFICATIONS_EMAIL_IMAP_SECURITY', 'ssl'),
                    'username' => env('NOTIFICATIONS_EMAIL_IMAP_USERNAME', ''),
                    'password' => env('NOTIFICATIONS_EMAIL_IMAP_PASSWORD', ''),
                    'timeoutSeconds' => env('NOTIFICATIONS_EMAIL_IMAP_TIMEOUT', 10),
                    'defaultFolder' => env('NOTIFICATIONS_EMAIL_IMAP_DEFAULT_FOLDER', 'INBOX'),
                ],
            ],
            'pop3' => [
                'default' => [
                    'host' => env('NOTIFICATIONS_EMAIL_POP3_HOST', ''),
                    'port' => env('NOTIFICATIONS_EMAIL_POP3_PORT', 110),
                    'security' => env('NOTIFICATIONS_EMAIL_POP3_SECURITY', 'none'),
                    'username' => env('NOTIFICATIONS_EMAIL_POP3_USERNAME', ''),
                    'password' => env('NOTIFICATIONS_EMAIL_POP3_PASSWORD', ''),
                    'timeoutSeconds' => env('NOTIFICATIONS_EMAIL_POP3_TIMEOUT', 10),
                ],
            ],
        ],
    ],
];
