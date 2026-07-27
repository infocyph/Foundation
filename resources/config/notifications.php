<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Notification Channel
    |--------------------------------------------------------------------------
    |
    | This value selects the channel used when notification code does not name
    | one explicitly. The shipped application uses the email channel.
    | Shipped value: `email`; custom registered channel names are also valid.
    |
    */
    'default_channel' => env('NOTIFICATIONS_DEFAULT_CHANNEL', 'email'),

    /*
    |--------------------------------------------------------------------------
    | Authentication Notifications
    |--------------------------------------------------------------------------
    |
    | `fail_silently` suppresses delivery exceptions after they are handled.
    | Keep it false when callers must know delivery failed. "from" overrides the
    | sender address and "transport" selects fake, log, mail, null, sendmail,
    | SMTP, or spool delivery from the profiles below. Silent failure accepts
    | `true|false`; sender example: `Security <security@example.com>`; transport:
    | `fake|log|mail|null|sendmail|smtp|spool`.
    |
    */
    'auth' => [
        'fail_silently' => env('NOTIFICATIONS_AUTH_FAIL_SILENTLY', false),
        'from' => env('NOTIFICATIONS_AUTH_FROM'),
        'transport' => env('NOTIFICATIONS_AUTH_TRANSPORT', env('APP_ENV', 'local') === 'production' ? 'log' : 'null'),

        /*
        |--------------------------------------------------------------------------
        | Authentication Mail Transports
        |--------------------------------------------------------------------------
        |
        | "fake" captures mail for assertions, "mail" uses PHP mail(), and
        | "null" discards messages. Log transport controls daily rotation,
        | output "directory", "filenamePrefix", and maximum message bytes.
        |
        | Sendmail defines executable "path", fixed "extraArguments", timeout
        | seconds, and maximum bytes. SMTP defines host/port, connection security,
        | timeout, EHLO local domain, auth mechanism, transcript capture, UTF-8
        | policy, 8BITMIME permission, maximum bytes, and username/password.
        |
        | Spool transport defines pending and optional processing directories,
        | metadata writing, file extension, read locking, batch maximum, optional
        | lower/upper age bounds in seconds, and maximum message bytes.
        |
        | Boolean values accept `true|false`. Log examples: `storage/logs/mail`,
        | prefix `auth`, limit `10485760` bytes. Sendmail path/arguments example:
        | `/usr/sbin/sendmail` with `['-t', '-i']`; timeout example: `15` seconds.
        |
        | SMTP host/port example: `smtp.example.com:587`. Security:
        | `none|ssl|starttls-optional|starttls-required`. Auth mechanism:
        | `auto|login|plain`. UTF-8 policy: `auto|reject|require`. Local domain
        | example: `mail.example.com`; credentials are free-form secrets.
        |
        | Example spool paths are `storage/mail` and `storage/mail/processing`.
        | The extension may be `eml` and a batch may contain 20 messages. Age and
        | byte limits are null or positive integers such as 3600 and 10485760.
        |
        */
        'transports' => [
            'fake' => [],
            'log' => [
                'dailyFiles' => env('NOTIFICATIONS_AUTH_LOG_DAILY_FILES', true),
                'directory' => env('NOTIFICATIONS_AUTH_LOG_DIRECTORY'),
                'filenamePrefix' => env('NOTIFICATIONS_AUTH_LOG_FILENAME_PREFIX', 'auth'),
                'maxMessageBytes' => env('NOTIFICATIONS_AUTH_LOG_MAX_MESSAGE_BYTES'),
            ],
            'mail' => [],
            'null' => [],
            'sendmail' => [
                'path' => env('NOTIFICATIONS_AUTH_SENDMAIL_PATH', '/usr/sbin/sendmail'),
                'extraArguments' => ['-t', '-i'],
                'timeoutSeconds' => env('NOTIFICATIONS_AUTH_SENDMAIL_TIMEOUT', 15),
                'maxMessageBytes' => env('NOTIFICATIONS_AUTH_SENDMAIL_MAX_MESSAGE_BYTES'),
            ],
            'smtp' => [
                'host' => env('NOTIFICATIONS_AUTH_SMTP_HOST', ''),
                'port' => env('NOTIFICATIONS_AUTH_SMTP_PORT', 587),
                'security' => env('NOTIFICATIONS_AUTH_SMTP_SECURITY', 'starttls-required'),
                'timeoutSeconds' => env('NOTIFICATIONS_AUTH_SMTP_TIMEOUT', 10),
                'localDomain' => env('NOTIFICATIONS_AUTH_SMTP_LOCAL_DOMAIN', 'localhost'),
                'authMechanism' => env('NOTIFICATIONS_AUTH_SMTP_AUTH_MECHANISM', 'auto'),
                'captureTranscript' => env('NOTIFICATIONS_AUTH_SMTP_CAPTURE_TRANSCRIPT', false),
                'utf8Policy' => env('NOTIFICATIONS_AUTH_SMTP_UTF8_POLICY', 'auto'),
                'allowEightBitMime' => env('NOTIFICATIONS_AUTH_SMTP_ALLOW_EIGHT_BIT_MIME', true),
                'maxMessageBytes' => env('NOTIFICATIONS_AUTH_SMTP_MAX_MESSAGE_BYTES'),
                'credentials' => [
                    'username' => env('NOTIFICATIONS_AUTH_SMTP_USERNAME'),
                    'password' => env('NOTIFICATIONS_AUTH_SMTP_PASSWORD'),
                ],
            ],
            'spool' => [
                'directory' => env('NOTIFICATIONS_AUTH_SPOOL_DIRECTORY', storage_path('mail')),
                'processingDirectory' => env('NOTIFICATIONS_AUTH_SPOOL_PROCESSING_DIRECTORY'),
                'writeMetadata' => env('NOTIFICATIONS_AUTH_SPOOL_WRITE_METADATA', true),
                'extension' => env('NOTIFICATIONS_AUTH_SPOOL_EXTENSION', 'eml'),
                'lockBeforeRead' => env('NOTIFICATIONS_AUTH_SPOOL_LOCK_BEFORE_READ', false),
                'maxMessages' => env('NOTIFICATIONS_AUTH_SPOOL_MAX_MESSAGES', 20),
                'olderThanSeconds' => env('NOTIFICATIONS_AUTH_SPOOL_OLDER_THAN_SECONDS'),
                'newerThanSeconds' => env('NOTIFICATIONS_AUTH_SPOOL_NEWER_THAN_SECONDS'),
                'maxMessageBytes' => env('NOTIFICATIONS_AUTH_SPOOL_MAX_MESSAGE_BYTES'),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Delivery Fallbacks
        |--------------------------------------------------------------------------
        |
        | "transports" is the ordered list attempted after the primary transport
        | fails. Keep it empty when fallback delivery is not explicitly required.
        | Transport names are `fake|log|mail|null|sendmail|smtp|spool`. For example,
        | place `smtp` first and `log` second to obtain an ordered fallback pair.
        |
        */
        'fallback' => [
            'transports' => [],
        ],

        /*
        |--------------------------------------------------------------------------
        | Delivery Retries
        |--------------------------------------------------------------------------
        |
        | "enabled" activates retry handling. "policy" accepts the configured
        | fixed or exponential/backoff mode, "max_attempts" bounds attempts, and
        | "delay_ms" controls the base delay. Retry only safely repeatable sends.
        | Enabled accepts `true|false`; policy accepts `fixed|backoff|exponential`.
        | Typical limits are 3 attempts and a 250 millisecond delay.
        |
        */
        'retry' => [
            'enabled' => env('NOTIFICATIONS_AUTH_RETRY_ENABLED', false),
            'policy' => env('NOTIFICATIONS_AUTH_RETRY_POLICY', 'fixed'),
            'max_attempts' => env('NOTIFICATIONS_AUTH_RETRY_MAX_ATTEMPTS', 3),
            'delay_ms' => env('NOTIFICATIONS_AUTH_RETRY_DELAY_MS', 250),
        ],

        /*
        |--------------------------------------------------------------------------
        | Delivery Rate Limit
        |--------------------------------------------------------------------------
        |
        | When "enabled", no more than "max_requests" notifications may be sent
        | during each "per_seconds" window by this notification service.
        | Enabled accepts `true|false`; example: `60` requests per `60` seconds.
        |
        */
        'rate_limit' => [
            'enabled' => env('NOTIFICATIONS_AUTH_RATE_LIMIT_ENABLED', false),
            'max_requests' => env('NOTIFICATIONS_AUTH_RATE_LIMIT_MAX_REQUESTS', 60),
            'per_seconds' => env('NOTIFICATIONS_AUTH_RATE_LIMIT_PER_SECONDS', 60),
        ],

        /*
        |--------------------------------------------------------------------------
        | DKIM Signing
        |--------------------------------------------------------------------------
        |
        | "enabled" signs outbound email using "domain" and "selector". Supply
        | either inline "private_key" or "private_key_path", never both unless
        | implementing deliberate key precedence. "headers" lists signed fields
        | and "algorithm" selects a TalkingBytes-supported DKIM algorithm.
        | Enabled accepts `true|false`; domain example: `example.com`; selector:
        | `mail2026`; key path: `/run/secrets/dkim.pem`; algorithm:
        | `rsa-sha256|ed25519-sha256`; header examples: `from|to|subject|date`.
        |
        */
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

    /*
    |--------------------------------------------------------------------------
    | General Email Services
    |--------------------------------------------------------------------------
    |
    | These settings bound inbound parsing and configure named spool, IMAP, and
    | POP3 profiles. Limits protect workers from oversized or deeply nested mail.
    | Profile names are free-form strings, for example `default` or `support`.
    |
    */
    'email' => [

        /*
        |--------------------------------------------------------------------------
        | MIME Parsing Limits
        |--------------------------------------------------------------------------
        |
        | Message, attachment, decoded-body, and header byte keys are hard size
        | ceilings. Attachment/header counts bound repeated fields. "maxMimeDepth"
        | limits nesting and "maxMimeParts" limits the complete MIME part count.
        | Every value is a positive integer. Byte/count/depth examples in order:
        | `10485760`, `26214400`, `500`, `10485760`, `20`, `500`, `131072`, `2000`.
        |
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
        | Spool Receivers
        |--------------------------------------------------------------------------
        |
        | Each profile defines inbound and processing directories, metadata and
        | locking behavior, extension, batch size, optional message age bounds,
        | and maximum bytes. `deleteAfterRead` removes successful inputs.
        | Otherwise "moveAfterRead" receives them. "failedDirectory" receives
        | messages that cannot be parsed or processed.
        |
        | Directory examples: `storage/mail/inbound`, `processing`, `processed`,
        | and `failed`; extension example: `eml`; boolean keys accept
        | `true|false`; batch example: `20`; age/byte limits are null or positive
        | integers, for example `3600` seconds and `10485760` bytes.
        |
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
        | IMAP And POP3 Mailboxes
        |--------------------------------------------------------------------------
        |
        | Both profile types define host, port, transport security, username,
        | password, and timeout seconds. IMAP additionally selects the
        | "defaultFolder" opened when callers omit a mailbox name. Keep mailbox
        | credentials in the environment and require encrypted transport.
        | Example hosts are `imap.example.com` and `pop3.example.com`, commonly
        | paired with ports 993 and 110. Security accepts
        | `none|ssl|starttls-optional|starttls-required`. A username may be
        | `inbox@example.com`, the timeout may be 10 seconds, and an IMAP folder
        | may be `INBOX`. Passwords are free-form secrets.
        |
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
