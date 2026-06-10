<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\DBLayer;

use Infocyph\AuthLayer\Contract\Clock\ClockInterface;
use Infocyph\AuthLayer\Contract\Storage\LockoutReason;
use Infocyph\AuthLayer\Contract\Storage\LockoutStoreInterface;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class DBLayerLockoutStore extends DBLayerStore implements LockoutStoreInterface
{
    public function __construct(
        DBLayerFactory $db,
        AuthTables $tables,
        private ClockInterface $clock,
        ?string $connection = null,
    ) {
        parent::__construct($db, $tables, $connection);
    }

    public function isLocked(string $accountId): bool
    {
        $row = $this->first(
            sprintf('SELECT until_at FROM %s WHERE account_id = ?', $this->table('lockouts')),
            [$accountId],
        );

        if ($row === null) {
            return false;
        }

        $until = $this->intOrNull($row['until_at'] ?? null);
        if ($until !== null && $until <= $this->clock->now()) {
            $this->unlock($accountId);

            return false;
        }

        return true;
    }

    public function lock(string $accountId, LockoutReason $reason, ?int $until = null): void
    {
        if ($this->first(
            sprintf('SELECT account_id FROM %s WHERE account_id = ?', $this->table('lockouts')),
            [$accountId],
        ) !== null) {
            $this->execute(
                sprintf('UPDATE %s SET reason = ?, until_at = ? WHERE account_id = ?', $this->table('lockouts')),
                [$reason->value, $until, $accountId],
            );

            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (account_id, reason, until_at) VALUES (?, ?, ?)', $this->table('lockouts')),
            [$accountId, $reason->value, $until],
        );
    }

    public function unlock(string $accountId): void
    {
        $this->execute(
            sprintf('DELETE FROM %s WHERE account_id = ?', $this->table('lockouts')),
            [$accountId],
        );
    }
}
