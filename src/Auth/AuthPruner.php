<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth;

use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class AuthPruner
{
    public function __construct(
        private DBLayerFactory $database,
        private AuthTables $tables,
        private AuthSchemaInstaller $schema,
    ) {}

    /** @return array<string,int> */
    public function prune(?string $connection = null, int $revokedRetentionHours = 24): array
    {
        if ($revokedRetentionHours < 0) {
            throw new \InvalidArgumentException('Auth prune retention hours cannot be negative.');
        }
        $readiness = $this->schema->readiness($connection);
        if (!$readiness['installed']) {
            throw new \RuntimeException(
                'Authentication schema is not installed; run "php infbyte module:schema:install auth" first.',
            );
        }

        $db = $this->database->connection($connection);
        $now = time();
        $revokedBefore = $now - ($revokedRetentionHours * 3600);
        $table = fn(string $name): string => $this->table($db, $name);

        return [
            'sessions' => $db->delete(
                'DELETE FROM ' . $table($this->tables->sessions()) . ' WHERE expires_at < ?',
                [$now],
            ),
            'password_resets' => $db->delete(
                'DELETE FROM ' . $table($this->tables->passwordResets()) . ' WHERE expires_at < ?',
                [$now],
            ),
            'email_verifications' => $db->delete(
                'DELETE FROM ' . $table($this->tables->emailVerifications()) . ' WHERE expires_at < ?',
                [$now],
            ),
            'remember_tokens' => $db->delete(
                'DELETE FROM ' . $table($this->tables->rememberTokens())
                . ' WHERE expires_at < ? OR (revoked_at IS NOT NULL AND revoked_at < ?)',
                [$now, $revokedBefore],
            ),
            'refresh_tokens' => $db->delete(
                'DELETE FROM ' . $table($this->tables->refreshTokens())
                . ' WHERE expires_at < ? OR (revoked_at IS NOT NULL AND revoked_at < ?)',
                [$now, $revokedBefore],
            ),
            'grants' => $db->delete(
                'DELETE FROM ' . $table($this->tables->grants())
                . ' WHERE (expires_at IS NOT NULL AND expires_at < ?) OR (revoked_at IS NOT NULL AND revoked_at < ?)',
                [$now, $revokedBefore],
            ),
            'lockouts' => $db->delete(
                'DELETE FROM ' . $table($this->tables->lockouts()) . ' WHERE until_at IS NOT NULL AND until_at < ?',
                [$now],
            ),
        ];
    }

    private function table(\Infocyph\DBLayer\Connection\Connection $connection, string $table): string
    {
        $prefix = $connection->getTablePrefix();
        $physical = $prefix !== '' && !str_starts_with($table, $prefix) ? $prefix . $table : $table;
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $physical) !== 1) {
            throw new \RuntimeException(sprintf('Unsafe authentication table identifier "%s".', $physical));
        }

        return $physical;
    }
}
