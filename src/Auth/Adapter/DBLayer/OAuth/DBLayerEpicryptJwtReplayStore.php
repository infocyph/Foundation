<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth;

use Infocyph\Epicrypt\Token\Jwt\JwtReplayStoreInterface;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerStore;

final readonly class DBLayerEpicryptJwtReplayStore extends DBLayerStore implements JwtReplayStoreInterface
{
    public function consume(string $namespace, string $tokenId, int $expiresAt): bool
    {
        $id = $this->id($namespace, $tokenId);
        try {
            $this->insertRecord('oauthReplayStates', [
                'id' => $id,
                'namespace_hash' => hash('sha256', $namespace),
                'token_id' => $tokenId,
                'expires_at' => $expiresAt,
            ]);

            return true;
        } catch (\Throwable $exception) {
            if ($this->first(
                sprintf('SELECT id FROM %s WHERE id = ?', $this->table('oauthReplayStates')),
                [$id],
            ) !== null) {
                return false;
            }

            throw $exception;
        }
    }

    public function isRevoked(string $namespace, string $tokenId, int $expiresAt): bool
    {
        $row = $this->first(
            sprintf('SELECT expires_at FROM %s WHERE id = ?', $this->table('oauthReplayStates')),
            [$this->id($namespace, $tokenId)],
        );

        return is_array($row) && $this->int($row['expires_at'] ?? 0) === $expiresAt;
    }

    private function id(string $namespace, string $tokenId): string
    {
        return hash('sha256', "foundation.oauth.replay\0" . $namespace . "\0" . $tokenId);
    }
}
