<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Cache\FoundationCacheKey;
use Infocyph\Foundation\Config\ConfigurationRedactor;
use Infocyph\Foundation\Logging\JsonLogger;
use Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler;

it('keeps communication secrets out of runtime identity metadata logs and cache keys', function (): void {
    $root = sys_get_temp_dir() . '/foundation-talkingbytes-21-secrets-' . bin2hex(random_bytes(6));
    $artifact = $root . '/bootstrap/cache/worker.php';
    $log = $root . '/storage/logs/communication.log';

    $httpToken = 'http-token-sentinel-' . bin2hex(random_bytes(8));
    $webhookSecret = 'webhook-secret-sentinel-' . bin2hex(random_bytes(8));
    $smtpPassword = 'smtp-password-sentinel-' . bin2hex(random_bytes(8));
    $deliveryId = 'delivery-sentinel-' . bin2hex(random_bytes(8));

    mkdir(dirname($artifact), 0775, true);

    $config = [
        'app' => [
            'base_path' => $root,
            'env' => 'local',
        ],
        '_config_cache' => false,
        'communication' => [
            'http' => [
                'default_client' => 'default',
                'clients' => [
                    'default' => [
                        'auth' => [
                            'driver' => 'bearer',
                            'token' => $httpToken,
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
                        'signing_secret' => $webhookSecret,
                    ],
                ],
                'inbound' => [
                    'default' => [
                        'secret' => $webhookSecret,
                        'replay' => ['enabled' => false],
                    ],
                ],
            ],
        ],
        'notifications' => [
            'email' => [
                'default_sender' => 'default',
                'senders' => [
                    'default' => ['transport' => 'smtp'],
                ],
                'transports' => [
                    'smtp' => [
                        'driver' => 'smtp',
                        'host' => 'smtp.example.test',
                        'credentials' => [
                            'username' => 'mailer@example.test',
                            'password' => $smtpPassword,
                        ],
                    ],
                ],
            ],
        ],
    ];

    try {
        $report = new GeneratedRuntimeCompiler()->compile(
            $config,
            RuntimeMode::Worker,
            $artifact,
            ['communication'],
        );

        foreach ([
            $artifact . '.foundation.json',
            $artifact . '.meta.json',
        ] as $metadataPath) {
            $metadata = (string) file_get_contents($metadataPath);
            expect($metadata)->not->toContain($httpToken)
                ->not->toContain($webhookSecret)
                ->not->toContain($smtpPassword);
        }

        $logger = new JsonLogger(
            driver: 'file',
            minimumLevel: 'warning',
            path: $log,
            redactedKeys: ['authorization', 'cookie', 'password', 'secret', 'token'],
        );
        $logger->error('communication failure', [
            'authorization' => 'Bearer ' . $httpToken,
            'webhook' => ['signing_secret' => $webhookSecret],
            'email' => ['smtp_password' => $smtpPassword],
            'cookie' => 'session=' . $httpToken,
        ]);

        $logged = (string) file_get_contents($log);
        expect($logged)->not->toContain($httpToken)
            ->not->toContain($webhookSecret)
            ->not->toContain($smtpPassword);

        $redacted = json_encode(
            new ConfigurationRedactor()->redact($config),
            JSON_THROW_ON_ERROR,
        );
        expect($redacted)->not->toContain($httpToken)
            ->not->toContain($webhookSecret)
            ->not->toContain($smtpPassword);

        $logical = json_encode(['partner', $deliveryId], JSON_THROW_ON_ERROR);
        $cacheKey = FoundationCacheKey::security(
            'wr',
            'foundation.webhook.replay.v1',
            $logical,
        );
        expect($cacheKey)->not->toContain($deliveryId)
            ->not->toContain($webhookSecret);

        expect($report['metadata_path'])->toBe($artifact . '.foundation.json');
    } finally {
        foundationTalkingBytes21SecretRemove($root);
    }
});

function foundationTalkingBytes21SecretRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
