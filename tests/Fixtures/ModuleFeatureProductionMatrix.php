<?php

declare(strict_types=1);

/**
 * Runs inside an isolated Composer --no-dev production consumer. This probe
 * deliberately loads only that consumer's vendor/autoload.php, never the
 * Foundation checkout's developer dependencies.
 */
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceRegistry;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\FoundationGraph;
use Infocyph\Foundation\Module\ModuleArchitectureCatalog;
use Infocyph\Foundation\Runtime\GeneratedRuntime;
use Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler;

require getcwd() . '/vendor/autoload.php';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['core', 'all'], true)) {
    throw new InvalidArgumentException('Pass core or all production feature mode.');
}

$capabilities = [
    'auth', 'cache', 'communication', 'database', 'filesystem', 'messaging',
    'notifications', 'otp', 'passkeys', 'runwire', 'security', 'session', 'validation',
];
$owners = [
    'data.database' => \Infocyph\Foundation\Database\DatabaseServiceProvider::class,
    'data.filesystem' => \Infocyph\Foundation\Filesystem\FilesystemServiceProvider::class,
    'security.crypto' => \Infocyph\Foundation\Security\SecurityServiceProvider::class,
    'security.validation' => \Infocyph\Foundation\Validation\ValidationServiceProvider::class,
    'execution.messaging' => \Infocyph\Foundation\Messaging\MessagingServiceProvider::class,
    'execution.communication' => \Infocyph\Foundation\Communication\CommunicationServiceProvider::class,
];
$all = [];
foreach (new ModuleArchitectureCatalog()->all() as $module => $definition) {
    foreach (array_keys($definition['features']) as $feature) {
        $all[] = $module . '.' . $feature;
    }
}
if (count($all) !== 9) {
    throw new RuntimeException('Unexpected Foundation 4 optional feature catalog size.');
}

$cases = [[]];
if ($mode === 'all') {
    foreach ($all as $id) {
        $cases[] = [$id];
    }
    $cases[] = ['data.database', 'execution.messaging'];
    $cases[] = ['security.mfa', 'security.passkey'];
    $cases[] = $all;
}

$root = sys_get_temp_dir() . '/foundation-no-dev-module-matrix-' . bin2hex(random_bytes(5));
mkdir($root . '/bootstrap/cache', 0775, true);

try {
    foreach ($cases as $index => $selectedIds) {
        $flags = array_fill_keys($all, false);
        foreach ($selectedIds as $id) {
            $flags[$id] = true;
        }
        $config = [
            'app' => ['base_path' => $root, 'env' => 'production', 'capabilities' => $capabilities],
            '_config_cache' => false,
            'modules' => ['features' => $flags],
        ];
        $context = FoundationBuildContext::fromConfig(
            new ConfigRepository($config),
            RuntimeMode::Cli,
            $capabilities,
        );
        $providers = new ServiceRegistry();
        new Bootstrapper()->compose(FoundationGraph::compose($context), $context, $providers);
        $actual = $providers->classes();

        foreach ($all as $id) {
            if ($context->hasFeature($id) !== in_array($id, $selectedIds, true)) {
                throw new RuntimeException('Feature-state mismatch: ' . $id);
            }
        }
        foreach ($owners as $id => $provider) {
            if (in_array($provider, $actual, true) !== in_array($id, $selectedIds, true)) {
                throw new RuntimeException('Provider selection mismatch: ' . $id);
            }
        }
        if (in_array(\Infocyph\Foundation\Routing\RoutingServiceProvider::class, $actual, true)
            || in_array(\Infocyph\Foundation\Http\HttpServiceProvider::class, $actual, true)
        ) {
            throw new RuntimeException('Webrick-owned graph providers leaked into CLI compilation.');
        }

        // Validate immutable compilation for Core-only, a selected specialist,
        // and the complete optional topology. The full provider-selection
        // matrix above still runs for every individual feature in --no-dev.
        if ($index === 0 || ($mode === 'all' && (count($selectedIds) === 1 && $selectedIds[0] === 'data.database' || count($selectedIds) === 9))) {
            $artifact = $root . '/bootstrap/cache/matrix-' . $index . '.php';
            $report = new GeneratedRuntimeCompiler()->compile($config, RuntimeMode::Cli, $artifact, $capabilities);
            if ($report['skipped'] !== []) {
                throw new RuntimeException('The selected native InterMix graph skipped static definitions.');
            }
            $runtime = GeneratedRuntime::load($config, RuntimeMode::Cli, $artifact, $capabilities);
            if ($runtime->application->runtimeMode() !== RuntimeMode::Cli) {
                throw new RuntimeException('The trusted compiled graph booted a different runtime.');
            }
        }
    }

    fwrite(STDOUT, sprintf("verified %d no-dev selected-feature configurations (%s)\n", count($cases), $mode));
} finally {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
