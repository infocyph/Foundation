<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks;

use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Http\Resolver\RequestPrincipalResolver;
use Infocyph\Foundation\Module\ModuleArchitectureCatalog;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

/** Representative Foundation auth/permission/PostgreSQL/Redis path, with disposable fixture state. */
final class Foundation4AuthenticatedWorkload
{
    /** @return array<string, mixed> */
    public static function configuration(string $directory): array
    {
        $features = [
            'data.database' => true, 'security.crypto' => true,
            'security.mfa' => true, 'execution.communication' => true,
        ];
        // Preserve the frozen pre-Core baseline's native host selection only.
        if (isset(new ModuleArchitectureCatalog()->all()['execution']['features']['runtime'])) {
            $features['execution.runtime'] = getenv('FOUNDATION_HOST_RUNWIRE') === '1';
        }

        return [
            'app' => ['topology' => 'single_node'],
            'modules' => ['features' => $features],
            'database' => [
                'default' => 'host',
                'connections' => ['host' => [
                    'driver' => 'pgsql', 'host' => (($__foundation154_40 = getenv('FOUNDATION_HOST_PG_HOST')) !== false && $__foundation154_40 !== '' && $__foundation154_40 !== '0' ? $__foundation154_40 : '127.0.0.1'),
                    'port' => (int) ((($__foundation154_41 = getenv('FOUNDATION_HOST_PG_PORT')) !== false && $__foundation154_41 !== '' && $__foundation154_41 !== '0' ? $__foundation154_41 : 5432)),
                    'database' => (($__foundation154_42 = getenv('FOUNDATION_HOST_PG_DATABASE')) !== false && $__foundation154_42 !== '' && $__foundation154_42 !== '0' ? $__foundation154_42 : 'foundation_host'),
                    'username' => (($__foundation154_43 = getenv('FOUNDATION_HOST_PG_USER')) !== false && $__foundation154_43 !== '' && $__foundation154_43 !== '0' ? $__foundation154_43 : 'phpforge'),
                    'password' => (($__foundation154_44 = getenv('FOUNDATION_HOST_PG_PASSWORD')) !== false && $__foundation154_44 !== '' && $__foundation154_44 !== '0' ? $__foundation154_44 : 'Phpforge_123!'),
                    'security' => ['max_param_bytes' => 16_384],
                ]],
            ],
            'auth' => [
                'drivers' => [
                    'storage' => 'database', 'cache' => 'cache', 'tokens' => 'security',
                    'passwords' => 'security', 'mfa' => 'otp', 'passkey' => 'disabled', 'notifications' => 'talkingbytes',
                ],
                'token_secret' => null, 'token_secret_environment' => 'FOUNDATION_HOST_AUTH_SECRET',
                'password_policy' => ['min_length' => 12, 'max_length' => 1024],
                'http' => ['principal_resolvers' => ['bearer']],
                'otp' => [
                    'replay' => ['store' => 'host'],
                    'secret_protection' => ['keys' => [[
                        'id' => 'host-fixture', 'environment' => 'FOUNDATION_HOST_MFA_SECRET', 'status' => 'active',
                    ]]],
                ],
            ],
            'security' => ['jwt' => [
                'algorithm' => 'HS256', 'issuer' => 'foundation-host', 'audience' => 'foundation-host',
                'maximum_lifetime_seconds' => 10_800,
            ]],
            'cache' => [
                'default' => 'host', 'default_counter' => 'host',
                'prefix' => 'foundation-host:' . ((($__foundation154_69 = getenv('FOUNDATION_HOST_PG_DATABASE')) !== false && $__foundation154_69 !== '' && $__foundation154_69 !== '0' ? $__foundation154_69 : basename($directory))) . ':',
                'connections' => ['host' => ['driver' => 'redis', 'dsn' => (($__foundation154_70 = getenv('FOUNDATION_HOST_REDIS_DSN')) !== false && $__foundation154_70 !== '' && $__foundation154_70 !== '0' ? $__foundation154_70 : 'redis://127.0.0.1:6379')]],
                'stores' => ['host' => [
                    'driver' => 'redis', 'connection' => 'host', 'fail_open' => false,
                    'security' => ['integrity_key' => getenv('FOUNDATION_HOST_AUTH_SECRET')],
                    'serialization' => ['allow_object_payloads' => false],
                ]],
                'counters' => ['host' => ['driver' => 'redis', 'connection' => 'host']],
                'lock' => ['store' => 'host'],
            ],
            'communication' => ['webhooks' => ['inbound' => ['default' => [
                'secret' => getenv('FOUNDATION_HOST_AUTH_SECRET'),
                'replay' => ['enabled' => true, 'store' => 'host'],
            ]]]],
            'notifications' => ['email' => [
                'senders' => ['auth' => ['transport' => 'host']],
                'transports' => ['host' => ['driver' => 'log', 'path' => $directory . '/storage/email.log']],
            ]],
        ];
    }

    /**
     * @throws \Infocyph\Foundation\Exception\ConfigurationException
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public static function read(
        Request $request,
        RequestPrincipalResolver $resolver,
        AuthServices $auth,
        DBLayerFactory $database,
        CacheManager $cache,
    ): Response {
        $connection = $database->connection();
        $before = $connection->getStats()['queries'];
        $principal = $resolver->resolve($request);
        if ($principal === null) {
            return Response::plaintext('Authentication required.', 401);
        }
        $auth->authorizer()->authorize($principal, 'benchmark.read');
        $value = $connection->scalar('SELECT item_value FROM benchmark_items WHERE id = ?', [42]);
        $cached = $cache->store()->get('item-42');
        if (!is_array($cached) || $cached['owner'] !== $principal->accountId() || $cached['value'] !== $value || $value !== 'value-42') {
            throw new RuntimeException('Authenticated host data integrity validation failed.');
        }

        return Response::json(['ok' => true])
            ->withHeader('X-Foundation-Benchmark-Queries', (string) ($connection->getStats()['queries'] - $before))
            ->withHeader('X-Foundation-Benchmark-Cache-Hits', '1')
            ->withHeader('X-Foundation-Benchmark-Worker', (string) getmypid());
    }

    /**
     * @param array<string, mixed> $configuration
     * @throws \Exception
     * @throws \Infocyph\Foundation\Exception\ConfigurationException
     * @throws \Infocyph\Foundation\Exception\ServiceResolutionException
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \Throwable
     */
    public static function seed(array $configuration, string $directory): void
    {
        $app = Foundation::cli($configuration + ['_config_cache' => false])->boot();
        $app->execution()->run(static function () use ($app, $directory): void {
            $app->make(AuthSchemaInstaller::class)->install();
            $auth = $app->make(AuthServices::class);
            $account = new \Infocyph\Foundation\Auth\Account\Account(
                'host-account',
                'host@example.test',
                \Infocyph\Foundation\Auth\Account\AccountStatus::ACTIVE,
            );
            $app->make(\Infocyph\Foundation\Auth\Contract\Storage\AccountStoreInterface::class)->save($account);
            $assignments = $app->make(\Infocyph\Foundation\Auth\Authorization\Permission\PermissionAssignmentStoreInterface::class);
            $assignments->save(new \Infocyph\Foundation\Auth\Authorization\Permission\Permission('host-permission', 'benchmark.read'));
            $assignments->assignPermissionToAccount($account->id(), 'host-permission');
            $db = $app->make(DBLayerFactory::class)->connection();
            $db->statement('CREATE TABLE IF NOT EXISTS benchmark_items (id INTEGER PRIMARY KEY, item_value TEXT NOT NULL)');
            $db->table('benchmark_items')->upsert(['id' => 42, 'item_value' => 'value-42'], ['id'], ['item_value']);
            $app->make(CacheManager::class)->store()->set('item-42', ['owner' => $account->id(), 'value' => 'value-42'], 10_800);
            $now = time();
            $token = $auth->tokens()->issueAccessToken(new AccessTokenClaims($account->id(), null, $now, $now + 10_800))->token;
            if ($token === null || file_put_contents($directory . '/authorization.txt', 'Bearer ' . $token, LOCK_EX) === false) {
                throw new RuntimeException('Unable to issue host workload access token.');
            }
        });
    }
}
