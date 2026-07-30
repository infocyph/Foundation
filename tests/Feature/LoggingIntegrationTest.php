<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Logging\HttpExceptionLogger;
use Infocyph\Foundation\Logging\ExceptionReporter;
use Infocyph\Foundation\Logging\JsonLogger;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class FoundationCollectingLogger extends AbstractLogger
{
    /** @var list<array{level:mixed,message:string,context:array<string,mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

it('writes structured records at the configured level and redacts nested secrets', function (): void {
    $directory = sys_get_temp_dir() . '/foundation-logging-' . bin2hex(random_bytes(6));
    $path = $directory . '/foundation.log';
    $logger = new JsonLogger(
        driver: 'file',
        minimumLevel: 'warning',
        path: $path,
        redactedKeys: ['password', 'token'],
    );

    try {
        $logger->info('ignored');
        $logger->error('request failed', [
            'credentials' => [
                'password' => 'do-not-write',
                'access_token' => 'also-secret',
            ],
            'exception' => new RuntimeException('exception-secret'),
        ]);

        $records = array_values(array_filter(explode("\n", trim((string) file_get_contents($path)))));
        $record = json_decode($records[0], true, flags: JSON_THROW_ON_ERROR);

        expect($records)->toHaveCount(1)
            ->and($record['level'])->toBe('error')
            ->and($record['context']['credentials'])->toBe([
                'password' => '[REDACTED]',
                'access_token' => '[REDACTED]',
            ])
            ->and($record['context']['exception']['class'])->toBe(RuntimeException::class)
            ->and(json_encode($record, JSON_THROW_ON_ERROR))->not->toContain('do-not-write')
            ->not->toContain('also-secret')
            ->not->toContain('exception-secret')
            ->not->toContain('trace');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('uses an application supplied PSR logger at the HTTP reporting boundary', function (): void {
    $logger = new FoundationCollectingLogger();
    $provider = new class($logger) extends ServiceProvider {
        public function __construct(private readonly LoggerInterface $logger) {}

        public function register(Application $app): void
        {
            $app->container()->bind(LoggerInterface::class, $this->logger, LifetimeEnum::Singleton);
        }
    };
    $app = Foundation::web([
        'providers' => ['web' => [$provider]],
        'logging' => [
            'exceptions' => [
                'include_message' => false,
            ],
        ],
    ]);

    $app->make(HttpExceptionLogger::class)->error('ignored', [
        'status' => 503,
        'method' => 'GET',
        'path' => '/health',
        'exception' => new RuntimeException('database-password=secret'),
    ]);

    expect($app->make(LoggerInterface::class))->toBe($logger)
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toBe('[http:503] ' . RuntimeException::class)
        ->and($logger->records[0]['context']['path'])->toBe('/health')
        ->and($logger->records[0]['context']['exception'])->not->toHaveKey('message')
        ->and(json_encode($logger->records, JSON_THROW_ON_ERROR))
        ->not->toContain('database-password=secret');
});

it('resolves relative log paths from the application and includes exception detail only when enabled', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-logging-app-' . bin2hex(random_bytes(6));
    mkdir($basePath, 0775, true);
    $path = $basePath . '/storage/logs/runtime.log';
    $app = Foundation::web([
        'app' => ['base_path' => $basePath],
        'logging' => [
            'driver' => 'file',
            'level' => 'error',
            'path' => 'storage/logs/runtime.log',
            'exceptions' => [
                'include_message' => true,
                'include_trace' => true,
            ],
        ],
    ]);

    try {
        $app->make(LoggerInterface::class)->error('request failed', [
            'exception' => new RuntimeException('safe-in-this-test'),
        ]);

        $record = json_decode(trim((string) file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);

        expect($record['context']['exception']['message'])->toBe('safe-in-this-test')
            ->and($record['context']['exception'])->toHaveKey('trace');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        if (is_dir($basePath . '/storage/logs')) {
            rmdir($basePath . '/storage/logs');
        }
        if (is_dir($basePath . '/storage')) {
            rmdir($basePath . '/storage');
        }
        if (is_dir($basePath)) {
            rmdir($basePath);
        }
    }
});

it('keeps CLI logging deferred until a logging dependent service is selected', function (): void {
    $app = Foundation::console();

    expect($app->container()->has(LoggerInterface::class))->toBeFalse()
        ->and($app->has(LoggerInterface::class))->toBeTrue()
        ->and($app->container()->has(LoggerInterface::class))->toBeFalse()
        ->and($app->make(LoggerInterface::class))->toBeInstanceOf(LoggerInterface::class)
        ->and($app->container()->has(LoggerInterface::class))->toBeTrue();
});

it('supports exception exclusions, sampling, and bounded repeated reporting', function (): void {
    $logger = new FoundationCollectingLogger();
    $reporter = new ExceptionReporter(
        logger: $logger,
        ignoredExceptions: [InvalidArgumentException::class],
        sampleRate: 1.0,
        throttleSeconds: 60,
        throttleLimit: 1,
    );
    $ignored = new InvalidArgumentException('expected');
    $repeated = new RuntimeException('repeat');

    $reporter->report('error', ['exception' => $ignored, 'status' => 422]);
    $reporter->report('error', ['exception' => $repeated, 'status' => 500]);
    $reporter->report('error', ['exception' => $repeated, 'status' => 500]);

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toBe('[http:500] ' . RuntimeException::class);

    $sampledOut = new ExceptionReporter(logger: $logger, sampleRate: 0.0);
    $sampledOut->report('error', ['exception' => new RuntimeException('discarded')]);

    expect($logger->records)->toHaveCount(1);
});
