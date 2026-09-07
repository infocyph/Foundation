<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAuthorizationCodeStore;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorizationCode;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer;

it('allows exactly one authorization-code redemption across two independent processes', function (): void {
    DB::purge();
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/foundation-oauth-code-race-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create OAuth concurrency fixture directory.');
    }

    $database = $directory . '/oauth.sqlite';
    $barrier = $directory . '/start';
    $script = $directory . '/consume.php';
    $resultA = $directory . '/a.result';
    $resultB = $directory . '/b.result';
    $clientId = 'oc_concurrent';
    $redirectHash = hash('sha256', 'https://client.example.test/callback');
    $challenge = str_repeat('A', 43);
    $plainCode = 'authorization-code-concurrency-sentinel';
    $codeHash = hash('sha256', $plainCode);
    $now = time();

    $config = new ConfigRepository([
        'database' => [
            'default' => 'setup',
            'connections' => ['setup' => ['driver' => 'sqlite', 'database' => $database]],
        ],
    ]);
    $factory = new DBLayerFactory(new DatabaseConnectionResolver($config), RuntimeStateContainer::execution());
    $tables = new AuthTables();
    $connection = $factory->connection();
    $runner = new MigrationRunner($connection, [new AuthOAuthRevisionSchema($tables)]);
    $store = new DBLayerOAuthAuthorizationCodeStore($factory, $tables);

    $child = <<<'PHP'
<?php

declare(strict_types=1);

require $argv[1] . '/vendor/autoload.php';

use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAuthorizationCodeStore;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer;

[$database, $barrier, $result, $name, $codeHash, $clientId, $redirectHash, $challenge, $now] = array_slice($argv, 2);
DB::purge();
$config = new ConfigRepository([
    'database' => [
        'default' => $name,
        'connections' => [$name => ['driver' => 'sqlite', 'database' => $database]],
    ],
]);
$factory = new DBLayerFactory(new DatabaseConnectionResolver($config), RuntimeStateContainer::execution());
$factory->connection()->setQueryTimeoutMs(5000);
$store = new DBLayerOAuthAuthorizationCodeStore($factory, new AuthTables());
while (!is_file($barrier)) {
    usleep(1000);
}
try {
    $status = $store->consume($codeHash, $clientId, $redirectHash, $challenge, (int) $now)->status->value;
    file_put_contents($result, $status);
} catch (Throwable $exception) {
    file_put_contents($result, 'error:' . $exception::class . ':' . $exception->getMessage());
    exit(2);
} finally {
    DB::purge();
}
PHP;

    try {
        $runner->run();
        $store->save(new OAuthAuthorizationCode(
            id: 'code-1',
            codeHash: $codeHash,
            clientId: $clientId,
            accountId: 'account-1',
            authorizationId: 'authorization-1',
            redirectUriHash: $redirectHash,
            pkceChallenge: $challenge,
            scopes: ['profile.read'],
            audiences: ['https://api.example.test'],
            issuedAt: $now - 10,
            expiresAt: $now + 300,
        ));
        DB::purge();
        file_put_contents($script, $child);

        $processA = oauth21StartContentionProcess([
            PHP_BINARY, $script, $root, $database, $barrier, $resultA, 'contender-a',
            $codeHash, $clientId, $redirectHash, $challenge, (string) $now,
        ]);
        $processB = oauth21StartContentionProcess([
            PHP_BINARY, $script, $root, $database, $barrier, $resultB, 'contender-b',
            $codeHash, $clientId, $redirectHash, $challenge, (string) $now,
        ]);

        touch($barrier);
        $exitA = proc_close($processA);
        $exitB = proc_close($processB);
        $statuses = [
            oauth21ReadContentionResult($resultA),
            oauth21ReadContentionResult($resultB),
        ];
        sort($statuses, SORT_STRING);

        expect([$exitA, $exitB])->toBe([0, 0])
            ->and($statuses)->toBe(['consumed', 'reused']);

        $verify = new DBLayerFactory(new DatabaseConnectionResolver(new ConfigRepository([
            'database' => [
                'default' => 'verify',
                'connections' => ['verify' => ['driver' => 'sqlite', 'database' => $database]],
            ],
        ])), RuntimeStateContainer::execution());
        $rows = $verify->connection()->select(
            'SELECT consumed_at FROM ' . $tables->oauthAuthorizationCodes() . ' WHERE code_hash = ?',
            [$codeHash],
        );
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['consumed_at'] ?? null)->not->toBeNull();
    } finally {
        DB::purge();
        oauth21CleanupContentionFiles([
            $barrier,
            $resultA,
            $resultB,
            $script,
            $database,
            $database . '-shm',
            $database . '-wal',
        ], $directory);
    }
});

function oauth21ReadContentionResult(string $path): string
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException(sprintf('Unable to read OAuth contention result "%s".', $path));
    }

    return trim($contents);
}

/**
 * @param list<string> $files
 */
function oauth21CleanupContentionFiles(array $files, string $directory): void
{
    foreach ($files as $file) {
        if (is_file($file) && !unlink($file)) {
            throw new RuntimeException(sprintf('Unable to remove OAuth contention fixture "%s".', $file));
        }
    }
    if (is_dir($directory) && !rmdir($directory)) {
        throw new RuntimeException(sprintf('Unable to remove OAuth contention directory "%s".', $directory));
    }
}

/**
 * @param list<string> $command
 * @return resource
 */
function oauth21StartContentionProcess(array $command)
{
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start OAuth contention process.');
    }
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    return $process;
}
