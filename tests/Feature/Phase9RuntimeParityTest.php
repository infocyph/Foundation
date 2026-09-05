<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Routing\WebReleaseCompiler;
use Infocyph\Foundation\Routing\WebReleaseRuntime;
use Infocyph\Foundation\Runtime\GeneratedRuntime;
use Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler;
use Infocyph\Foundation\Runtime\NonWebGraphFactory;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class Phase9ParityLeaf
{
    public function __construct(public string $value) {}
}

final readonly class Phase9ParityNode
{
    public function __construct(
        public Phase9ParityLeaf $leaf,
        public RuntimeMode $runtime,
    ) {}
}

final class Phase9ParityScopedProbe
{
    private static int $next = 0;

    public readonly int $sequence;

    public function __construct()
    {
        $this->sequence = ++self::$next;
    }
}

final class Phase9ParityProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);

        $builder->bind(
            Phase9ParityLeaf::class,
            FactoryDefinition::construct(Phase9ParityLeaf::class, ['phase-9-parity']),
            LifetimeEnum::Singleton,
        );
        $builder->bind(
            Phase9ParityNode::class,
            FactoryDefinition::construct(Phase9ParityNode::class, [
                new ServiceReference(Phase9ParityLeaf::class),
                new ServiceReference(RuntimeMode::class),
            ]),
            LifetimeEnum::Transient,
        );
        $builder->bind(
            Phase9ParityScopedProbe::class,
            FactoryDefinition::construct(Phase9ParityScopedProbe::class),
            LifetimeEnum::Scoped,
        );
    }
}

final readonly class Phase9WebParityHandler
{
    public static function show(string $name): Response
    {
        return Response::json(['name' => $name, 'runtime' => 'web']);
    }
}

it('keeps InterMix development and generated production semantics aligned for non-web runtimes', function (): void {
    $root = phase9ParityProject();

    try {
        foreach ([RuntimeMode::Cli, RuntimeMode::Worker, RuntimeMode::Scheduler] as $mode) {
            $runtimeRoot = $root . '/' . $mode->value;
            mkdir($runtimeRoot . '/bootstrap/cache', 0777, true);
            $config = [
                'app' => [
                    'base_path' => $runtimeRoot,
                    'env' => 'production',
                    'debug' => false,
                ],
                '_config_cache' => false,
                'providers' => [
                    'common' => [Phase9ParityProvider::class],
                ],
            ];

            $development = new NonWebGraphFactory()->compose($config, $mode);
            $developmentNode = $development->application->make(Phase9ParityNode::class);
            $developmentScopes = phase9ParityScopes($development->application);

            $artifact = $runtimeRoot . '/bootstrap/cache/container.php';
            $report = new GeneratedRuntimeCompiler()->compile($config, $mode, $artifact);
            expect($report['skipped'])->toBe([]);
            $production = GeneratedRuntime::load($config, $mode, $artifact);
            $productionNode = $production->application->make(Phase9ParityNode::class);
            $productionScopes = phase9ParityScopes($production->application);

            expect([
                $developmentNode->leaf->value,
                $developmentNode->runtime->value,
                $developmentScopes['same_within_scope'],
                $developmentScopes['isolated_between_scopes'],
            ])->toBe([
                $productionNode->leaf->value,
                $productionNode->runtime->value,
                $productionScopes['same_within_scope'],
                $productionScopes['isolated_between_scopes'],
            ])->and($production->container->has('foundation.http'))->toBeFalse();
        }
    } finally {
        phase9ParityRemove($root);
    }
});

it('keeps Webrick development and compiled production route behavior aligned', function (): void {
    $root = phase9ParityProject();
    mkdir($root . '/web/routes', 0777, true);
    mkdir($root . '/web/bootstrap/cache', 0777, true);
    file_put_contents($root . '/web/routes/web.php', <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Facade\Router;

Router::get('/parity/{name}', [Phase9WebParityHandler::class, 'show']);
PHP);

    $config = [
        'app' => [
            'base_path' => $root . '/web',
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => [
                'globals' => [
                    'pre' => [],
                    'post' => [],
                ],
            ],
        ],
    ];
    $request = Request::fake(
        headers: ['Host' => 'parity.test'],
        uri: 'https://parity.test/parity/Foundation',
    );

    try {
        $development = Foundation::web($config)->boot()->handle($request);

        $manifest = $root . '/web/bootstrap/cache/release.json';
        $release = new WebReleaseCompiler()->compile(
            $config,
            $root . '/web/bootstrap/cache/intermix.php',
            $root . '/web/bootstrap/cache/router.php',
            $manifest,
            [],
        );
        expect($release['intermix']['skipped'] ?? null)->toBe([]);

        $productionRuntime = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            (string) $release['release_runtime_manifest_sha256'],
            foundationCapabilities: [],
        );
        $production = $productionRuntime->kernel->handle($request);

        expect($production->getStatusCode())->toBe($development->getStatusCode())
            ->and((string) $production->getBody())->toBe((string) $development->getBody())
            ->and((string) $production->getBody())->toBe('{"name":"Foundation","runtime":"web"}');
    } finally {
        foundationResetWebrickProductionRegistries();
        phase9ParityRemove($root);
    }
});

/** @return array{same_within_scope:bool,isolated_between_scopes:bool} */
function phase9ParityScopes(\Infocyph\Foundation\Application\Application $application): array
{
    $first = $application->execution()->run(static function () use ($application): array {
        $left = $application->make(Phase9ParityScopedProbe::class);
        $right = $application->make(Phase9ParityScopedProbe::class);

        return [$left->sequence, $right->sequence];
    });
    $second = $application->execution()->run(
        static fn(): int => $application->make(Phase9ParityScopedProbe::class)->sequence,
    );

    return [
        'same_within_scope' => $first[0] === $first[1],
        'isolated_between_scopes' => $first[0] !== $second,
    ];
}

function phase9ParityProject(): string
{
    $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-phase9-parity-' . bin2hex(random_bytes(5));
    mkdir($root, 0777, true);

    return $root;
}

function phase9ParityRemove(string $directory): void
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
