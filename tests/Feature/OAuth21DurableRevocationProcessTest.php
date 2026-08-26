<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAuthorizationStore;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorization;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;

it('persists access and authorization revocation across fresh processes without cache state', function (): void {
    DB::purge();
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/foundation-oauth-durable-revocation-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create OAuth durable-revocation fixture directory.');
    }

    $database = $directory . '/oauth.sqlite';
    $writer = $directory . '/writer.php';
    $reader = $directory . '/reader.php';
    $result = $directory . '/result.json';
    $now = time();
    $tokenId = 'access-token-id-durable';
    $authorizationId = 'authorization-durable';
    $tables = new AuthTables();
    $factory = oauth21DurableRevocationFactory($database, 'setup');
    $runner = new MigrationRunner($factory->connection(), [new AuthOAuthRevisionSchema($tables)]);
    $authorizations = new DBLayerOAuthAuthorizationStore($factory, $tables);

    $writerScript = <<<'PHP'
<?php

declare(strict_types=1);

require $argv[1] . '/vendor/autoload.php';

use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAccessRevocationStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAuthorizationStore;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenRevocation;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;

[$database, $tokenId, $authorizationId, $now] = array_slice($argv, 2);
DB::purge();
$config = new ConfigRepository([
    'database' => [
        'default' => 'writer',
        'connections' => ['writer' => ['driver' => 'sqlite', 'database' => $database]],
    ],
]);
$factory = new DBLayerFactory(new DatabaseConnectionResolver($config), new RuntimeContextTracker());
$tables = new AuthTables();
$access = new DBLayerOAuthAccessRevocationStore($factory, $tables);
$authorizations = new DBLayerOAuthAuthorizationStore($factory, $tables);
$access->revoke(new OAuthAccessTokenRevocation(
    tokenId: $tokenId,
    clientId: 'oc_durable',
    authorizationId: $authorizationId,
    expiresAt: (int) $now + 3600,
    revokedAt: (int) $now,
    reason: 'test',
));
if (!$authorizations->revoke($authorizationId, (int) $now)) {
    exit(3);
}
DB::purge();
PHP;

    $readerScript = <<<'PHP'
<?php

declare(strict_types=1);

require $argv[1] . '/vendor/autoload.php';

use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAccessRevocationStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAuthorizationStore;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;

[$database, $tokenId, $authorizationId, $now, $result] = array_slice($argv, 2);
DB::purge();
$config = new ConfigRepository([
    'database' => [
        'default' => 'reader',
        'connections' => ['reader' => ['driver' => 'sqlite', 'database' => $database]],
    ],
]);
$factory = new DBLayerFactory(new DatabaseConnectionResolver($config), new RuntimeContextTracker());
$tables = new AuthTables();
$access = new DBLayerOAuthAccessRevocationStore($factory, $tables);
$authorization = new DBLayerOAuthAuthorizationStore($factory, $tables)->find($authorizationId);
file_put_contents($result, json_encode([
    'access_revoked' => $access->isRevoked($tokenId, (int) $now + 1),
    'authorization_revoked_at' => $authorization?->revokedAt,
], JSON_THROW_ON_ERROR));
DB::purge();
PHP;

    try {
        $runner->run();
        $authorizations->save(new OAuthAuthorization(
            id: $authorizationId,
            clientId: 'oc_durable',
            accountId: 'account-1',
            scopes: ['profile.read'],
            audiences: ['https://api.example.test'],
            createdAt: $now - 10,
            expiresAt: $now + 3600,
        ));
        DB::purge();
        file_put_contents($writer, $writerScript);
        file_put_contents($reader, $readerScript);

        $writerExit = oauth21RunDurableRevocationProcess([
            PHP_BINARY, $writer, $root, $database, $tokenId, $authorizationId, (string) $now,
        ]);
        expect($writerExit)->toBe(0);

        $readerExit = oauth21RunDurableRevocationProcess([
            PHP_BINARY, $reader, $root, $database, $tokenId, $authorizationId, (string) $now, $result,
        ]);
        expect($readerExit)->toBe(0);

        $payload = json_decode((string) file_get_contents($result), true, flags: JSON_THROW_ON_ERROR);
        expect($payload)->toBe([
            'access_revoked' => true,
            'authorization_revoked_at' => $now,
        ]);
    } finally {
        DB::purge();
        oauth21CleanupDurableRevocationFiles([
            $writer,
            $reader,
            $result,
            $database,
            $database . '-shm',
            $database . '-wal',
        ], $directory);
    }
});

function oauth21DurableRevocationFactory(string $database, string $name): DBLayerFactory
{
    return new DBLayerFactory(new DatabaseConnectionResolver(new ConfigRepository([
        'database' => [
            'default' => $name,
            'connections' => [$name => ['driver' => 'sqlite', 'database' => $database]],
        ],
    ])), new RuntimeContextTracker());
}

/** @param list<string> $files */
function oauth21CleanupDurableRevocationFiles(array $files, string $directory): void
{
    foreach ($files as $file) {
        if (is_file($file) && !unlink($file)) {
            throw new RuntimeException(sprintf('Unable to remove OAuth durable-revocation fixture "%s".', $file));
        }
    }
    if (is_dir($directory) && !rmdir($directory)) {
        throw new RuntimeException(sprintf('Unable to remove OAuth durable-revocation directory "%s".', $directory));
    }
}

/** @param list<string> $command */
function oauth21RunDurableRevocationProcess(array $command): int
{
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start OAuth durable-revocation process.');
    }
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    return proc_close($process);
}
