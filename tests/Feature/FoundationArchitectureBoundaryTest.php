<?php

declare(strict_types=1);

it('enforces Foundation production ownership boundaries', function (): void {
    $root = dirname(__DIR__, 2) . '/src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    $forbiddenEverywhere = [
        'Infocyph\\Infbyte\\',
        'Infocyph\\InfByte\\',
        'Infocyph\\PHPForge\\',
        'Infocyph\\PHPProbe\\',
    ];
    $executionRoots = [
        $root . '/Auth/',
        $root . '/Http/',
        $root . '/Messaging/',
        $root . '/Scheduling/',
        $root . '/Session/',
        $root . '/Worker/',
    ];

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $source = file_get_contents($file->getPathname());
        expect($source)->toBeString();

        foreach ($forbiddenEverywhere as $namespace) {
            expect($source)->not->toContain($namespace);
        }

        if (!str_contains($path, '/src/Testing/')) {
            expect($source)->not->toContain('Infocyph\\Foundation\\Testing\\');
        }

        if (array_any($executionRoots, static fn(string $prefix): bool => str_starts_with($path, str_replace('\\', '/', $prefix)))) {
            expect($source)
                ->not->toContain('FoundationReleaseCompiler')
                ->not->toContain('WebReleaseCompiler')
                ->not->toContain('GeneratedRuntimeCompiler');
        }
    }
});

it('keeps optional specialist packages out of the Foundation runtime requirement set', function (): void {
    $composer = json_decode(
        file_get_contents(dirname(__DIR__, 2) . '/composer.json') ?: '',
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $required = array_keys(is_array($composer['require'] ?? null) ? $composer['require'] : []);

    expect($required)->toContain(
        'infocyph/arraykit',
        'infocyph/cachelayer',
        'infocyph/intermix',
        'infocyph/uid',
        'infocyph/webrick',
    )->not->toContain(
        'infocyph/dblayer',
        'infocyph/epicrypt',
        'infocyph/omnibus',
        'infocyph/otp',
        'infocyph/pathwise',
        'infocyph/reqshield',
        'infocyph/runwire',
        'infocyph/talkingbytes',
        'web-auth/webauthn-lib',
    );
});


it('keeps specialist native owners selected at Foundation integration seams', function (): void {
    $root = dirname(__DIR__, 2);

    $cacheProvider = file_get_contents($root . '/src/Cache/CacheServiceProvider.php');
    $authCacheRegistrar = file_get_contents($root . '/src/Auth/Internal/AuthCacheRegistrar.php');
    $oauthRegistrar = file_get_contents($root . '/src/Auth/Internal/AuthOAuthRegistrar.php');

    expect($cacheProvider)->toBeString()
        ->toContain('Webrick\\Interop\\CacheLayer\\AtomicCounterAdapter')
        ->not->toContain('WebrickAtomicCounter::class')
        ->and($authCacheRegistrar)->toBeString()
        ->toContain('AtomicCounterStore::class')
        ->not->toContain('CacheLayerCounterStore::class')
        ->and($oauthRegistrar)->toBeString()
        ->toContain('DBLayerEpicryptRefreshTokenStore::class')
        ->not->toContain('DBLayerOAuthRefreshTokenStore::class')
        ->not->toContain('OAuthRefreshTokenCoordinator::class');
});

it('removes superseded native counters and OAuth refresh implementations', function (): void {
    $root = dirname(__DIR__, 2);
    $obsolete = [
        'src/Auth/Adapter/WebAuthn/WebAuthnRuntime.php',
        'src/Cache/WebrickAtomicCounter.php',
        'src/Auth/Adapter/CacheLayer/CacheLayerCounterStore.php',
        'src/Auth/OAuth/Token/OAuthRefreshTokenCoordinator.php',
        'src/Auth/OAuth/Token/OAuthRefreshTokenIssue.php',
        'src/Auth/OAuth/Token/OAuthRefreshTokenRecord.php',
        'src/Auth/OAuth/Token/OAuthRefreshRotationResult.php',
        'src/Auth/OAuth/Token/OAuthRefreshRotationStatus.php',
        'src/Auth/OAuth/Contract/OAuthRefreshTokenStoreInterface.php',
        'src/Auth/Adapter/DBLayer/OAuth/DBLayerOAuthRefreshTokenStore.php',
    ];

    foreach ($obsolete as $path) {
        expect($root . '/' . $path)->not->toBeFile();
    }
});


it('keeps application-owned named cache consumers on the CacheManager registry', function (): void {
    $root = dirname(__DIR__, 2);
    $paths = [
        'src/Auth/Internal/AuthMfaGraphFactory.php',
        'src/Auth/Internal/AuthPasskeyGraphFactory.php',
        'src/Communication/CommunicationGraphFactory.php',
        'src/Database/DBLayerFactory.php',
        'src/Database/DatabaseMigrationManager.php',
        'src/Scheduling/ScheduleManager.php',
        'src/Session/SessionGraphFactory.php',
        'src/Worker/WorkerManager.php',
    ];

    foreach ($paths as $path) {
        $source = file_get_contents($root . '/' . $path);
        expect($source)->toBeString()
            ->toContain('CacheManager')
            ->not->toContain('CacheLayerFactory');
    }
});


it('keeps TalkingBytes typed HTTP composition on the native 2.3 path', function (): void {
    $source = file_get_contents(
        dirname(__DIR__, 2) . '/src/Communication/CommunicationProfiles.php',
    );

    expect($source)->toBeString()
        ->toContain('HttpClientConfig::fromArray($array)')
        ->toContain('baseConfig: $config')
        ->not->toContain('return HttpClient::fromResolvedConfig($array);');
});


it('keeps specialist benchmark commands bound to maintained entrypoint paths', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        file_get_contents($root . '/composer.json') ?: '',
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $scripts = $composer['scripts'] ?? [];

    foreach (['cachelayer', 'dblayer', 'epicrypt', 'omnibus', 'otp', 'pathwise', 'reqshield', 'talkingbytes'] as $package) {
        $path = 'benchmarks/' . $package . '-utilization.php';
        expect($scripts['benchmark:' . $package] ?? null)->toBe('@php ' . $path)
            ->and(is_file($root . '/' . $path))->toBeTrue();
    }
});

it('uses deterministic registrations without inspecting unfinished provider graphs', function (): void {
    $root = dirname(__DIR__, 2);
    $providers = [
        'Auth/Internal/AuthCoreRegistrar.php',
        'Communication/CommunicationServiceProvider.php',
        'Logging/LoggingServiceProvider.php',
        'Messaging/MessagingServiceProvider.php',
        'Notifications/NotificationServiceProvider.php',
        'Security/SecurityServiceProvider.php',
    ];

    foreach ($providers as $path) {
        $source = file_get_contents($root . '/src/' . $path);
        expect($source)->toBeString()
            ->not->toContain('->definitions()->has(');
    }
});
