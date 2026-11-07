<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Security\RememberToken as EpicryptRememberToken;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerPasswordResetStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerRefreshTokenStore;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenRecord;
use Infocyph\Foundation\Auth\Support\SystemClock;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationRequest;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetRequest;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenStatus;
use Infocyph\Foundation\Auth\Contract\Storage\RefreshTokenStoreInterface;
use Infocyph\Foundation\Auth\Internal\AuthSecretResolver;
use Infocyph\Foundation\Auth\Support\InMemoryEmailVerificationStore;
use Infocyph\Foundation\Auth\Support\InMemoryPasswordResetStore;
use Infocyph\Foundation\Foundation;

it('atomically consumes a password reset once and restores it when account mutation fails', function (): void {
    $store = new InMemoryPasswordResetStore();
    $now = time();
    $store->save(new PasswordResetRequest('reset', 'account', $now, $now + 300));
    $count = 0;

    expect($store->consumeAndApply('reset', static function () use (&$count): void {
        $count++;
    }))->toBeTrue()
        ->and($store->consumeAndApply('reset', static function (): void {}))->toBeFalse()
        ->and($count)->toBe(1);

    $store->save(new PasswordResetRequest('retry', 'account', $now, $now + 300));
    expect(fn() => $store->consumeAndApply('retry', static function (): void {
        throw new RuntimeException('account persistence failed');
    }))->toThrow(RuntimeException::class, 'account persistence failed')
        ->and($store->wasConsumed('retry'))->toBeFalse()
        ->and($store->consumeAndApply('retry', static function (): void {}))->toBeTrue();
});

it('prevents duplicate email verification consumption and rolls back failed mutation', function (): void {
    $store = new InMemoryEmailVerificationStore();
    $now = time();
    $store->save(new EmailVerificationRequest('email', 'account', 'a@example.test', $now, $now + 300));

    expect(fn() => $store->consumeAndApply('email', static function (): void {
        throw new RuntimeException('account update failed');
    }))->toThrow(RuntimeException::class)
        ->and($store->find('email')?->isConsumed())->toBeFalse()
        ->and($store->consumeAndApply('email', static function (): void {}))->toBeTrue()
        ->and($store->consumeAndApply('email', static function (): void {}))->toBeFalse();
});

it('rejects application refresh replay after an atomic rotation', function (): void {
    $app = Foundation::web(['app' => ['base_path' => sys_get_temp_dir()], 'router' => ['files' => []]]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $tokens = $app->make(AuthServices::class)->tokens();
        $issued = $tokens->issueRefreshToken('account');
        $old = $issued->refreshToken;
        expect($old)->not->toBeNull();
        if ($old === null) {
            return;
        }

        $first = $tokens->rotateRefreshToken($old);
        expect($first->successful())->toBeTrue();
        $stored = $app->make(RefreshTokenStoreInterface::class)->find($old->id);
        expect($stored?->rotatedAt)->not->toBeNull()
            ->and($tokens->rotateRefreshToken($old)->failed())->toBeTrue()
            ->and($tokens->rotateRefreshToken($stored ?? $old)->failed())->toBeTrue();
    });
});

it('invalidates a rotated remember-me credential and denies replay', function (): void {
    $app = Foundation::web(['app' => ['base_path' => sys_get_temp_dir()], 'router' => ['files' => []]]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $remember = $app->make(AuthServices::class)->rememberMe();
        $issued = $remember->issue('account', 'device');
        $old = $issued->record;
        expect($old)->not->toBeNull();
        if ($old === null || $issued->token === null) {
            return;
        }

        $rotated = $remember->rotate($old);
        expect($rotated->status)->toBe(RememberTokenStatus::ROTATED)
            ->and($remember->verify($issued->token->value)->status)->toBe(RememberTokenStatus::REUSED)
            ->and($remember->rotate($old)->status)->toBe(RememberTokenStatus::REUSED);
    });
});

it('delegates remember-me signatures and account-device binding to native Epicrypt', function (): void {
    $app = Foundation::web(['app' => ['base_path' => sys_get_temp_dir()], 'router' => ['files' => []]]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $issued = $app->make(AuthServices::class)->rememberMe()->issue('owner', 'device-1');
        $value = $issued->token?->value ?? '';
        $parts = explode('.', $value, 2);
        expect($parts)->toHaveCount(2);

        $secrets = $app->make(AuthSecretResolver::class);
        $native = new EpicryptRememberToken($secrets->tokenSecret(32));

        expect($native->verify($parts[1], 'owner', 'device-1'))->toBeTrue()
            ->and($native->verify($parts[1], 'wrong-owner', 'device-1'))->toBeFalse()
            ->and($native->verify($parts[1], 'owner', 'wrong-device'))->toBeFalse();
    });
});

it('persists single-use reset and refresh transitions through native DBLayer transactions', function (): void {
    DB::purge();
    $configuration = new ConfigRepository([
        'database' => [
            'default' => 'auth',
            'connections' => ['auth' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ],
    ]);
    $factory = new DBLayerFactory(new DatabaseConnectionResolver($configuration), RuntimeStateContainer::execution());
    $tables = new AuthTables();
    $clock = new SystemClock();
    $now = $clock->now();

    try {
        new MigrationRunner($factory->connection(), [new AuthSchema($tables)])->run();
        $resets = new DBLayerPasswordResetStore(new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $factory]), $tables, $clock);
        $resets->save(new PasswordResetRequest('reset-db', 'account', $now, $now + 300));

        expect(fn() => $resets->consumeAndApply('reset-db', static function (): void {
            throw new RuntimeException('account persistence failed');
        }))->toThrow(RuntimeException::class, 'account persistence failed');

        expect($resets->wasConsumed('reset-db'))->toBeFalse()
            ->and($resets->consumeAndApply('reset-db', static function (): void {}))->toBeTrue()
            ->and($resets->consumeAndApply('reset-db', static function (): void {}))->toBeFalse()
            ->and($resets->wasConsumed('reset-db'))->toBeTrue();

        $refresh = new DBLayerRefreshTokenStore(new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $factory]), $tables, $clock);
        $current = new RefreshTokenRecord(
            tokenHash: hash('sha256', 'refresh-1'),
            clientId: null,
            deviceId: null,
            id: 'refresh-db-a',
            accountId: 'account',
            familyId: 'family-db',
            issuedAt: $now,
            expiresAt: $now + 300,
        );
        $replacement = new RefreshTokenRecord(
            tokenHash: hash('sha256', 'refresh-2'),
            clientId: null,
            deviceId: null,
            id: 'refresh-db-b',
            accountId: 'account',
            familyId: 'family-db',
            issuedAt: $now,
            expiresAt: $now + 300,
        );
        $refresh->save($current);
        expect($refresh->rotateIfActive($current, $replacement))->toBeTrue()
            ->and($refresh->rotateIfActive($current, $replacement))->toBeFalse()
            ->and($refresh->find($current->id)?->rotatedAt)->toBeInt()
            ->and($refresh->find($replacement->id)?->id)->toBe($replacement->id);

        $refresh->revokeFamily('family-db');
        expect($refresh->wasFamilyRevoked('family-db'))->toBeTrue();
    } finally {
        DB::purge();
    }
});

it('rolls back token rotation instead of overwriting an existing replacement identity', function (string $kind): void {
    $config = new ConfigRepository(['database' => [
        'default' => 'collision',
        'connections' => ['collision' => ['driver' => 'sqlite', 'database' => ':memory:']],
    ]]);
    $factory = new DBLayerFactory(new DatabaseConnectionResolver($config), RuntimeStateContainer::execution());
    $tables = new AuthTables();
    new MigrationRunner($factory->connection(), [new AuthSchema($tables)])->run();
    $now = time();
    $make = static function (string $id, string $account, string $family) use ($kind, $now): mixed {
        return $kind === 'refresh'
            ? new RefreshTokenRecord(hash('sha256', $id), null, null, $id, $account, $family, $now, $now + 300)
            : new \Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenRecord(
                'device', $id, hash('sha256', $id), $id, $account, $family, $now, $now + 300,
            );
    };
    $store = $kind === 'refresh'
        ? new DBLayerRefreshTokenStore(new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $factory]), $tables, new SystemClock())
        : new \Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerRememberTokenStore(new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $factory]), $tables, new SystemClock());
    $current = $make('source', 'owner', 'family');
    $occupied = $make('occupied', 'other-account', 'other-family');
    $store->save($current);
    $store->save($occupied);

    expect(fn() => $store->rotateIfActive($current, $make('occupied', 'owner', 'family')))
        ->toThrow(\Infocyph\DBLayer\Exceptions\TransactionException::class)
        ->and($store->find('source')?->rotatedAt)->toBeNull()
        ->and($store->find('occupied'))->toEqual($occupied);
})->with(['refresh', 'remember']);
