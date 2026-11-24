<?php

declare(strict_types=1);

namespace Infocyph;

use FilesystemIterator;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Command\CommandContext;
use Infocyph\Foundation\Command\CommandDefinition;
use Infocyph\Foundation\Command\CommandDispatcher;
use Infocyph\Foundation\Command\CommandHandlerInterface;
use Infocyph\Foundation\Command\CommandIO;
use Infocyph\Foundation\Command\CommandRegistry;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Release\ActiveGeneration;
use Infocyph\Foundation\Release\FoundationReleaseBootstrap;
use Infocyph\Foundation\Release\FoundationReleaseCompiler;
use Infocyph\Foundation\Release\FoundationReleaseRuntime;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Scheduling\SchedulerRuntime;
use Infocyph\Foundation\Tests\Fixtures\EnvironmentSecretStageObserver;
use Infocyph\Foundation\Worker\WorkerManager;
use Infocyph\Foundation\Worker\WorkerProvider;
use Infocyph\Foundation\Worker\WorkerRuntime;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use RuntimeException;

Router::get('/phase8', FoundationPhase8ReleaseWebHandler::class, ['name' => 'phase8.show']);
PHP,
    );

    return $project;
}

/** @throws \UnexpectedValueException */
function foundationPhase8ReleaseRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        \PHPUnit\Framework\Assert::assertInstanceOf(\SplFileInfo::class, $file);

        $file->isLink() || !$file->isDir()
            ? unlink($file->getPathname())
            : rmdir($file->getPathname());
    }
    rmdir($directory);
}

it('uses only process environment to select a trusted release generation', function (): void {
    $rootName = FoundationReleaseBootstrap::RELEASE_ROOT_ENV;
    $digestName = FoundationReleaseBootstrap::MANIFEST_SHA256_ENV;
    $previous = [];

    foreach ([$rootName, $digestName] as $name) {
        $previous[$name] = [
            'process' => getenv($name),
            'envExists' => array_key_exists($name, $_ENV),
            'env' => $_ENV[$name] ?? null,
            'serverExists' => array_key_exists($name, $_SERVER),
            'server' => $_SERVER[$name] ?? null,
        ];
    }

    try {
        putenv($rootName);
        putenv($digestName);
        $_ENV[$rootName] = '/request-controlled/one';
        $_SERVER[$rootName] = '/request-controlled/two';
        $_ENV[$digestName] = str_repeat('a', 64);
        $_SERVER[$digestName] = str_repeat('b', 64);

        expect(FoundationReleaseBootstrap::fromEnvironment())->toBeNull();

        $trustedRoot = sys_get_temp_dir() . '/foundation-process-root-' . bin2hex(random_bytes(6));
        $trustedDigest = str_repeat('c', 64);
        putenv($rootName . '=' . $trustedRoot);
        putenv($digestName . '=' . $trustedDigest);

        $bootstrap = FoundationReleaseBootstrap::fromEnvironment();
        if (!$bootstrap instanceof FoundationReleaseBootstrap) {
            throw new \RuntimeException('Expected trusted release bootstrap from process environment.');
        }
        expect($bootstrap->releaseRoot)->toBe($trustedRoot)
            ->and($bootstrap->trustedFoundationManifestSha256)->toBe($trustedDigest);
    } finally {
        foreach ($previous as $name => $state) {
            putenv($state['process'] === false ? $name : $name . '=' . $state['process']);
            if ($state['envExists']) {
                $_ENV[$name] = $state['env'];
            } else {
                unset($_ENV[$name]);
            }
            if ($state['serverExists']) {
                $_SERVER[$name] = $state['server'];
            } else {
                unset($_SERVER[$name]);
            }
        }
    }
});

/** @throws \InvalidArgumentException */
function foundationPhase8ReleaseSetEnvironment(string $name, ?string $value): void
{
    if ($name === '') {
        throw new \InvalidArgumentException('Environment keys must not be empty.');
    }
    if ($value === null) {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);

        return;
    }

    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
    putenv($name . '=' . $value);
}
