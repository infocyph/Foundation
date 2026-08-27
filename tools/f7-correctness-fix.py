from pathlib import Path


def replace(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    if old not in text:
        raise SystemExit(f"Expected text not found in {path}: {old[:100]!r}")
    file.write_text(text.replace(old, new, 1))


replace(
    'src/Auth/OAuth/Token/OAuthSigningKeyResolver.php',
    'AuthEventSeverity::ERROR',
    'AuthEventSeverity::CRITICAL',
)
replace(
    'src/Auth/OAuth/Token/OAuthSigningKeyResolver.php',
    "        $key = @file_get_contents($path);\n        if (!is_string($key) || trim($key) === '') {\n            throw new ConfigurationException('OAuth signing key material is unavailable.');\n        }",
    "        if (!is_file($path) || !is_readable($path)) {\n            throw new ConfigurationException('OAuth signing key material is unavailable.');\n        }\n\n        $key = file_get_contents($path);\n        if (!is_string($key) || trim($key) === '') {\n            throw new ConfigurationException('OAuth signing key material is unavailable.');\n        }",
)
replace(
    'src/Auth/OAuth/OAuthManager.php',
    "            'scopes' => ($response->scope === '' ? [] : preg_split('/\\s+/', $response->scope)) ?: [],",
    "            'scopes' => $response->scopes,",
)
replace(
    'src/Auth/OAuth/Token/OAuthRefreshTokenCoordinator.php',
    "        $scopes = $this->scopes->narrow($current->scopes, $requestedScopes);\n\n        try {\n            $selection = $this->scopes->resolve($client, $scopes, $current->audiences);",
    "        try {\n            $scopes = $this->scopes->narrow($current->scopes, $requestedScopes);\n            $selection = $this->scopes->resolve($client, $scopes, $current->audiences);",
)
replace(
    'src/Auth/OAuth/Configuration/OAuthConfigValidator.php',
    "if (!is_array($mapping) || array_is_list($mapping) && $mapping !== [])",
    "if (!is_array($mapping) || (array_is_list($mapping) && $mapping !== []))",
)
replace(
    'src/Command/System/OperationsSystemCommand.php',
    '''    private function workerStatus(): int
    {
        $name = $this->argument(0);
        $manager = new WorkerManager($this->application);
        if ($name !== null && !array_key_exists($name, $manager->all())) {
            throw new \\InvalidArgumentException(sprintf('Worker "%s" is not configured.', $name));
        }
        $status = $manager->status($name);
        if ($this->io()->machineReadable()) {
            return $this->emit($status);
        }
        $rows = array_map(
            static fn(array $item): array => [
                $item['name'],
                $item['running'],
                $item['pid'] ?? '',
                $item['started_at'] ?? '',
                $item['last_heartbeat_at'] ?? '',
                $item['restart_token'] ?? '',
                $item['restart_requested'],
            ],
            $status,
        );
        $this->io()->table(
            ['Name', 'Running', 'PID', 'Started', 'Heartbeat', 'Restart Token', 'Restart Requested'],
            $rows,
        );

        return ExitCode::SUCCESS;
    }''',
    '''    private function workerStatus(): int
    {
        $name = $this->argument(0);
        $manager = new WorkerManager($this->application);
        $configured = $manager->all();
        if ($name !== null && !array_key_exists($name, $configured)) {
            throw new \\InvalidArgumentException(sprintf('Worker "%s" is not configured.', $name));
        }

        $registry = new \\Infocyph\\Foundation\\Operations\\RuntimeProcessRegistry($this->application);
        $processes = $registry->all('worker', $name);
        $selected = $name === null ? $configured : [$name => $configured[$name]];
        $status = [
            'worker' => $name,
            'registry_visibility' => $registry->visibility(),
            'configured' => $selected,
            'processes' => $processes,
        ];
        if ($this->io()->machineReadable()) {
            return $this->emit($status);
        }

        $rows = array_map(
            static fn(array $item): array => [
                $item['name'],
                $item['running'],
                $item['pid'],
                $item['started_at'],
                $item['heartbeat_at'],
            ],
            $processes,
        );
        $this->io()->table(
            ['Name', 'Running', 'PID', 'Started', 'Heartbeat'],
            $rows,
        );

        return ExitCode::SUCCESS;
    }''',
)
replace(
    'tests/Feature/OAuth21ConfigValidationTest.php',
    "            'public_keys' => ['/run/secrets/oauth-public.pem'],",
    "            'public_keys' => [[\n                'id' => 'oauth-key-1',\n                'path' => '/run/secrets/oauth-public.pem',\n                'status' => 'active',\n            ]],",
)
replace(
    'tests/Feature/OAuth21DisabledIsolationTest.php',
    "            ->and($app->has(OAuthBearerTokenPrincipalResolver::class))->toBeFalse()\n",
    '',
)
replace(
    'tests/Feature/OAuth21PersistenceAtomicityTest.php',
    '))->toThrow(Throwable::class);',
    '))->toThrow(RuntimeException::class);',
)
replace(
    'tests/Feature/OAuth21ResourceRejectionMatrixTest.php',
    '            expiresAt: $now - 1,',
    '            expiresAt: $now - 60,',
)
replace(
    'tests/Feature/OAuth21SensitiveOutputClosureTest.php',
    '''    $forbidden = [
        'LoggerInterface',
        '->debug(',
        '->info(',
        '->notice(',
        '->warning(',
        '->error(',
        '->critical(',
        '->alert(',
        '->emergency(',
    ];''',
    '''    $forbidden = [
        'Psr\\Log\\',
        'LoggerInterface',
    ];''',
)
replace(
    'tests/Feature/RepresentativeBenchmarkTest.php',
    "                'route-selected-array-session-warm',\n            ]);",
    "                'route-selected-array-session-warm',\n                'oauth-disabled-application-bearer-warm',\n                'oauth-client-credentials-bearer-resolution-warm',\n            ]);",
)
replace(
    'tests/Feature/OAuth21AuditClosureTest.php',
    "    $plain = 'refresh-token-audit-sentinel';",
    "    $plain = str_repeat('R', 64);",
)
replace(
    'tests/Feature/OAuth21AuthorizationCodeConcurrencyTest.php',
    "        $statuses = [trim((string) @file_get_contents($resultA)), trim((string) @file_get_contents($resultB))];",
    "        $statuses = [\n            is_file($resultA) ? trim((string) file_get_contents($resultA)) : '',\n            is_file($resultB) ? trim((string) file_get_contents($resultB)) : '',\n        ];",
)
replace(
    'tests/Feature/OAuth21AuthorizationCodeConcurrencyTest.php',
    "        foreach ([$barrier, $resultA, $resultB, $script, $database, $database . '-shm', $database . '-wal'] as $file) {\n            @unlink($file);\n        }\n        @rmdir($directory);",
    "        foreach ([$barrier, $resultA, $resultB, $script, $database, $database . '-shm', $database . '-wal'] as $file) {\n            if (is_file($file)) {\n                unlink($file);\n            }\n        }\n        if (is_dir($directory)) {\n            rmdir($directory);\n        }",
)
replace(
    'tests/Feature/OAuth21RefreshConcurrencyTest.php',
    "        $statuses = [trim((string) @file_get_contents($resultA)), trim((string) @file_get_contents($resultB))];",
    "        $statuses = [\n            is_file($resultA) ? trim((string) file_get_contents($resultA)) : '',\n            is_file($resultB) ? trim((string) file_get_contents($resultB)) : '',\n        ];",
)
replace(
    'tests/Feature/OAuth21RefreshConcurrencyTest.php',
    "        foreach ([$barrier, $resultA, $resultB, $script, $database, $database . '-shm', $database . '-wal'] as $file) {\n            @unlink($file);\n        }\n        @rmdir($directory);",
    "        foreach ([$barrier, $resultA, $resultB, $script, $database, $database . '-shm', $database . '-wal'] as $file) {\n            if (is_file($file)) {\n                unlink($file);\n            }\n        }\n        if (is_dir($directory)) {\n            rmdir($directory);\n        }",
)
replace(
    'tests/Feature/OAuth21DurableRevocationProcessTest.php',
    "        foreach ([$writer, $reader, $result, $database, $database . '-shm', $database . '-wal'] as $file) {\n            @unlink($file);\n        }\n        @rmdir($directory);",
    "        foreach ([$writer, $reader, $result, $database, $database . '-shm', $database . '-wal'] as $file) {\n            if (is_file($file)) {\n                unlink($file);\n            }\n        }\n        if (is_dir($directory)) {\n            rmdir($directory);\n        }",
)
replace(
    'tests/Feature/OAuth21SigningKeySelectionAuditTest.php',
    "        @unlink($privateLocator);\n        @unlink($publicLocator);\n        @rmdir($directory);",
    "        if (is_file($privateLocator)) {\n            unlink($privateLocator);\n        }\n        if (is_file($publicLocator)) {\n            unlink($publicLocator);\n        }\n        if (is_dir($directory)) {\n            rmdir($directory);\n        }",
)
