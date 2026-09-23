<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenManager;
use Infocyph\Epicrypt\Certificate\KeyPairGenerator;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;

it('uses Epicrypt PATs with authoritative serialized DB state and never persists raw JWTs', function (): void {
    $base = sys_get_temp_dir() . '/foundation-pat-' . bin2hex(random_bytes(6));
    mkdir($base . '/keys', 0700, true);
    $database = $base . '/pat.sqlite';
    $pair = KeyPairGenerator::ec()->generate();
    file_put_contents($base . '/keys/pat-private.pem', $pair['private']);
    file_put_contents($base . '/keys/pat-public.pem', $pair['public']);

    $app = Foundation::web([
        'app' => [
            'base_path' => $base,
            'env' => 'testing',
        ],
        '_config_cache' => false,
        'database' => [
            'default' => 'pat',
            'connections' => [
                'pat' => ['driver' => 'sqlite', 'database' => $database],
            ],
        ],
        'auth' => [
            'personal_access_tokens' => [
                'enabled' => true,
                'issuer' => 'https://issuer.example.test',
                'audience' => 'https://api.example.test',
                'default_lifetime_seconds' => 3600,
                'maximum_lifetime_seconds' => 86400,
                'wildcard_policy' => 'disabled',
                'last_used_write_interval_seconds' => 60,
                'signing' => [
                    'algorithm' => 'ES256',
                    'active_key_id' => 'pat-active',
                    'private_key' => 'keys/pat-private.pem',
                    'public_keys' => [[
                        'id' => 'pat-active',
                        'path' => 'keys/pat-public.pem',
                        'status' => 'active',
                    ]],
                ],
            ],
        ],
    ]);

    try {
        $app->boot();
        $app->make(AuthSchemaInstaller::class)->install();

        $tokens = $app->make(PersonalAccessTokenManager::class);
        $first = $tokens->issue('account-1', 'automation', ['orders.read']);
        $verified = $tokens->verify($first->token);

        expect($verified->accepted())->toBeTrue()
            ->and($verified->allows('orders.read'))->toBeTrue()
            ->and($verified->allows('orders.write'))->toBeFalse()
            ->and($tokens->list('account-1'))->toHaveCount(1);

        $tables = $app->make(AuthTables::class);
        $connection = $app->make(DBLayerFactory::class)->connection();
        $row = $connection->select(
            sprintf('SELECT * FROM %s WHERE token_id = ?', $tables->personalAccessTokens()),
            [$first->record->tokenId],
        )[0] ?? null;
        expect($row)->toBeArray()
            ->and(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain($first->token);

        $revoked = $tokens->revoke($first->record->tokenId, 'account-1');
        $again = $tokens->revoke($first->record->tokenId, 'account-1');
        expect($revoked?->revokedAt)->not->toBeNull()
            ->and($again?->revokedAt)->toBe($revoked?->revokedAt)
            ->and($tokens->verify($first->token)->accepted())->toBeFalse();

        $beforeA = $tokens->issue('account-1', 'before-a', ['orders.read']);
        $beforeB = $tokens->issue('account-1', 'before-b', ['orders.read']);
        expect($tokens->revokeAll('account-1'))->toBe(2)
            ->and($tokens->revokeAll('account-1'))->toBe(0)
            ->and($tokens->verify($beforeA->token)->accepted())->toBeFalse()
            ->and($tokens->verify($beforeB->token)->accepted())->toBeFalse();

        $after = $tokens->issue('account-1', 'after-revoke-all', ['orders.read']);
        expect($tokens->verify($after->token)->accepted())->toBeTrue();

        $state = $connection->select(
            sprintf('SELECT revision FROM %s WHERE subject_hash = ?', $tables->personalAccessTokenSubjects()),
            [hash('sha3-256', "foundation.personal-access-token.subject\0account-1")],
        )[0] ?? null;
        expect($state)->toBeArray()
            ->and((int) ($state['revision'] ?? 0))->toBeGreaterThanOrEqual(5);
    } finally {
        unset($app);
        foundationPatRemove($base);
    }
});

function foundationPatRemove(string $directory): void
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
