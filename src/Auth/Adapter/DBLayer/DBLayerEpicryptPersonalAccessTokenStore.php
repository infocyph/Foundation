<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenRecord;
use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenStoreInterface;
use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenUsageStoreInterface;

final readonly class DBLayerEpicryptPersonalAccessTokenStore extends DBLayerStore implements
    PersonalAccessTokenStoreInterface,
    PersonalAccessTokenUsageStoreInterface
{
    public function create(PersonalAccessTokenRecord $record): bool
    {
        $result = $this->connection()->transaction(function (Connection $connection) use ($record): bool {
            $this->serializeSubject($connection, $record->subject);
            if ($this->findUsing($connection, $record->tokenId) instanceof PersonalAccessTokenRecord) {
                return false;
            }

            $connection->table($this->table('personalAccessTokens'))->insert([
                'token_id' => $record->tokenId,
                'subject_hash' => $this->subjectHash($record->subject),
                'subject' => $record->subject,
                'name' => $record->name,
                'abilities' => DBLayerJson::encodeList($record->abilities),
                'created_at' => $record->createdAt,
                'expires_at' => $record->expiresAt,
                'revoked_at' => $record->revokedAt,
                'last_used_at' => null,
            ]);

            return true;
        });

        return $result === true;
    }

    public function find(string $tokenId): ?PersonalAccessTokenRecord
    {
        return $this->findUsing($this->connection(), $tokenId);
    }

    public function lastUsedAt(string $tokenId, string $subject): ?int
    {
        $row = $this->first(
            sprintf(
                'SELECT last_used_at FROM %s WHERE token_id = ? AND subject_hash = ? AND subject = ?',
                $this->table('personalAccessTokens'),
            ),
            [$tokenId, $this->subjectHash($subject), $subject],
        );

        return is_array($row) ? $this->intOrNull($row['last_used_at'] ?? null) : null;
    }

    public function listForSubject(string $subject, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Personal-access-token list limit must be between 1 and 100.');
        }

        $rows = $this->all(
            sprintf(
                'SELECT * FROM %s WHERE subject_hash = ? AND subject = ? ORDER BY created_at DESC, token_id ASC LIMIT %d',
                $this->table('personalAccessTokens'),
                $limit,
            ),
            [$this->subjectHash($subject), $subject],
        );

        return array_map($this->map(...), $rows);
    }

    public function revoke(string $tokenId, string $subject, int $revokedAt): ?PersonalAccessTokenRecord
    {
        $result = $this->connection()->transaction(function (Connection $connection) use ($tokenId, $subject, $revokedAt): ?PersonalAccessTokenRecord {
            $connection->execute(
                sprintf(
                    'UPDATE %s SET revoked_at = ? WHERE token_id = ? AND subject_hash = ? AND subject = ? AND revoked_at IS NULL',
                    $this->table('personalAccessTokens'),
                ),
                [$revokedAt, $tokenId, $this->subjectHash($subject), $subject],
            );

            $record = $this->findUsing($connection, $tokenId);

            return $record instanceof PersonalAccessTokenRecord && hash_equals($record->subject, $subject)
                ? $record
                : null;
        });

        return $result instanceof PersonalAccessTokenRecord ? $result : null;
    }

    public function revokeAll(string $subject, int $revokedAt): int
    {
        $result = $this->connection()->transaction(function (Connection $connection) use ($subject, $revokedAt): int {
            $this->serializeSubject($connection, $subject);

            return $connection->execute(
                sprintf(
                    'UPDATE %s SET revoked_at = ? WHERE subject_hash = ? AND subject = ? AND revoked_at IS NULL',
                    $this->table('personalAccessTokens'),
                ),
                [$revokedAt, $this->subjectHash($subject), $subject],
            )->rowCount();
        });

        return is_int($result) ? $result : 0;
    }

    public function touch(
        string $tokenId,
        string $subject,
        int $usedAt,
        int $minimumIntervalSeconds,
    ): ?int {
        $result = $this->connection()->transaction(function (Connection $connection) use (
            $tokenId,
            $subject,
            $usedAt,
            $minimumIntervalSeconds,
        ): ?int {
            $connection->execute(
                sprintf(
                    'UPDATE %s SET last_used_at = ? WHERE token_id = ? AND subject_hash = ? AND subject = ? AND revoked_at IS NULL AND (last_used_at IS NULL OR last_used_at <= ?)',
                    $this->table('personalAccessTokens'),
                ),
                [
                    $usedAt,
                    $tokenId,
                    $this->subjectHash($subject),
                    $subject,
                    $usedAt - $minimumIntervalSeconds,
                ],
            );
            $row = $connection->select(
                sprintf(
                    'SELECT last_used_at FROM %s WHERE token_id = ? AND subject_hash = ? AND subject = ?',
                    $this->table('personalAccessTokens'),
                ),
                [$tokenId, $this->subjectHash($subject), $subject],
            )[0] ?? null;

            return is_array($row) ? $this->intOrNull($row['last_used_at'] ?? null) : null;
        });

        return is_int($result) ? $result : null;
    }

    private function findUsing(Connection $connection, string $tokenId): ?PersonalAccessTokenRecord
    {
        $row = $connection->select(
            sprintf('SELECT * FROM %s WHERE token_id = ?', $this->table('personalAccessTokens')),
            [$tokenId],
        )[0] ?? null;

        return is_array($row) ? $this->map($row) : null;
    }

    /** @param array<string,mixed> $row */
    private function map(array $row): PersonalAccessTokenRecord
    {
        return new PersonalAccessTokenRecord(
            tokenId: $this->string($row['token_id'] ?? ''),
            subject: $this->string($row['subject'] ?? ''),
            name: $this->string($row['name'] ?? ''),
            abilities: DBLayerJson::decodeStringList($row['abilities'] ?? null),
            createdAt: $this->int($row['created_at'] ?? 0),
            expiresAt: $this->intOrNull($row['expires_at'] ?? null),
            revokedAt: $this->intOrNull($row['revoked_at'] ?? null),
        );
    }

    private function serializeSubject(Connection $connection, string $subject): void
    {
        $hash = $this->subjectHash($subject);

        try {
            $connection->table($this->table('personalAccessTokenSubjects'))->insert([
                'subject_hash' => $hash,
                'revision' => 0,
            ]);
        } catch (\Throwable $exception) {
            $existing = $connection->select(
                sprintf(
                    'SELECT subject_hash FROM %s WHERE subject_hash = ?',
                    $this->table('personalAccessTokenSubjects'),
                ),
                [$hash],
            )[0] ?? null;
            if (!is_array($existing)) {
                throw $exception;
            }
        }

        $connection->execute(
            sprintf(
                'UPDATE %s SET revision = revision + 1 WHERE subject_hash = ?',
                $this->table('personalAccessTokenSubjects'),
            ),
            [$hash],
        );
    }

    private function subjectHash(string $subject): string
    {
        return hash('sha3-256', "foundation.personal-access-token.subject\0" . $subject);
    }
}
