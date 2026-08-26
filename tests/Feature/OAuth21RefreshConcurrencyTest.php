<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthRefreshTokenStore;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshRotationStatus;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshTokenRecord;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;

it('allows exactly one refresh rotation and revokes the family after concurrent replay', function (): void {
    DB::purge();
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/foundation-oauth-refresh-race-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create OAuth refresh contention fixture directory.');
    }

    $database = $directory . '/oauth.sqlite';
    $barrier = $directory . '/start';
    $script = $directory . '/rotate.php';
    $resultA = $directory . '/a.result';
    $resultB = $directory . '/b.result';
    $currentHash = hash('sha256', 'refresh-concurrency-current');
    $familyId = 'family-concurrent';
    $now = time();

    $factory = oauth21RefreshConcurrencyFactory($database, 'setup');
    $tables = new AuthTables();
    $runner = new MigrationRunner($factory->connection(), [new AuthOAuthRevisionSchema($tables)]);
    $store = new DBLayerOAuthRefreshTokenStore($factory, $tables);

    $child = <<<'PHP'
<?php

declare(strict_types=1);

require $argv[1] . '/vendor/autoload.php';

use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthRefreshTokenStore;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshRotationStatus;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshTokenRecord;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;

[$database, $barrier, $result, $name, $currentHash, $replacementHash, $replacementId, $familyId, $now] = array_slice($argv, 2);
DB::purge();
$config = new ConfigRepository([
    'database' => [
        'default' => $name,
        'connections' => [$name => ['driver' => 'sqlite', 'database' => $database]],
    ],
]);
$factory = new DBLayerFactory(new DatabaseConnectionResolver($config), new RuntimeContextTracker());
$store = new DBLayerOAuthRefreshTokenStore($factory, new AuthTables());
$replacement = new OAuthRefreshTokenRecord(
    id: $replacementId,
    tokenHash: $replacementHash,
    familyId: $familyId,
    clientId: 'oc_concurrent',
    accountId: 'account-1',
    deviceId: 'device-1',
    authorizationId: 'authorization-1',
    scopes: ['profile.read'],
    audiences: ['https://api.example.test'],
    issuedAt: (int) $now,
    expiresAt: (int) $now + 3600,
);
while (!is_file($barrier)) {
    usleep(1000);
}
try {
    $rotation = $store->rotate($currentHash, $replacement, (int) $now);
    if ($rotation->status === OAuthRefreshRotationStatus::Reused) {
        $store->revokeFamily($familyId, (int) $now + 1);
    }
    file_put_contents($result, $rotation->status->value);
} catch (Throwable $exception) {
    file_put_contents($result, 'error:' . $exception::class . ':' . $exception->getMessage());
    exit(2);
} finally {
    DB::purge();
}
PHP;

    try {
        $runner->run();
        $store->save(new OAuthRefreshTokenRecord(
            id: 'refresh-current',
            tokenHash: $currentHash,
            familyId: $familyId,
            clientId: 'oc_concurrent',
            accountId: 'account-1',
            deviceId: 'device-1',
            authorizationId: 'authorization-1',
            scopes: ['profile.read'],
            audiences: ['https://api.example.test'],
            issuedAt: $now - 10,
            expiresAt: $now + 3600,
        ));
        DB::purge();
        file_put_contents($script, $child);

        $replacementA = hash('sha256', 'refresh-concurrency-a');
        $replacementB = hash('sha256', 'refresh-concurrency-b');
        $processA = oauth21StartRefreshContentionProcess([
            PHP_BINARY, $script, $root, $database, $barrier, $resultA, 'refresh-a',
            $currentHash, $replacementA, 'refresh-a', $familyId, (string) $now,
        ]);
        $processB = oauth21StartRefreshContentionProcess([
            PHP_BINARY, $script, $root, $database, $barrier, $resultB, 'refresh-b',
            $currentHash, $replacementB, 'refresh-b', $familyId, (string) $now,
        ]);

        touch($barrier);
        $exitA = proc_close($processA);
        $exitB = proc_close($processB);
        $statuses = [
            oauth21ReadRefreshContentionResult($resultA),
            oauth21ReadRefreshContentionResult($resultB),
        ];
        sort($statuses, SORT_STRING);

        expect([$exitA, $exitB])->toBe([0, 0])
            ->and($statuses)->toBe(['reused', 'rotated']);

        $verify = oauth21RefreshConcurrencyFactory($database, 'verify');
        $rows = $verify->connection()->select(
            'SELECT id, rotated_at, revoked_at FROM ' . $tables->oauthRefreshTokens() . ' WHERE family_id = ? ORDER BY id ASC',
            [$familyId],
        );
        expect($rows)->toHaveCount(2);

        $current = array_values(array_filter($rows, static fn(array $row): bool => ($row['id'] ?? null) === 'refresh-current'))[0] ?? null;
        $replacement = array_values(array_filter($rows, static fn(array $row): bool => ($row['id'] ?? null) !== 'refresh-current'))[0] ?? null;
        expect($current)->not->toBeNull()
            ->and($current['rotated_at'] ?? null)->not->toBeNull()
            ->and($current['revoked_at'] ?? null)->not->toBeNull()
            ->and($replacement)->not->toBeNull()
            ->and($replacement['revoked_at'] ?? null)->not->toBeNull();
    } finally {
        DB::purge();
        oauth21CleanupRefreshContentionFiles([
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

function oauth21RefreshConcurrencyFactory(string $database, string $name): DBLayerFactory
{
    return new DBLayerFactory(new DatabaseConnectionResolver(new ConfigRepository([
        'database' => [
            'default' => $name,
            'connections' => [$name => ['driver' => 'sqlite', 'database' => $database]],
        ],
    ])), new RuntimeContextTracker());
}

function oauth21ReadRefreshContentionResult(string $path): string
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException(sprintf('Unable to read OAuth refresh contention result "%s".', $path));
    }

    return trim($contents);
}

/** @param list<string> $files */
function oauth21CleanupRefreshContentionFiles(array $files, string $directory): void
{
    foreach ($files as $file) {
        if (is_file($file) && !unlink($file)) {
            throw new RuntimeException(sprintf('Unable to remove OAuth refresh contention fixture "%s".', $file));
        }
    }
    if (is_dir($directory) && !rmdir($directory)) {
        throw new RuntimeException(sprintf('Unable to remove OAuth refresh contention directory "%s".', $directory));
    }
}

/**
 * @param list<string> $command
 * @return resource
 */
function oauth21StartRefreshContentionProcess(array $command)
{
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start OAuth refresh contention process.');
    }
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    return $process;
}
