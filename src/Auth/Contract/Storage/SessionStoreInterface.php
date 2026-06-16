<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Storage;

use Infocyph\Foundation\Auth\Authentication\Session\AuthSession;

interface SessionStoreInterface
{
    public function create(AuthSession $session): void;

    public function find(string $sessionId): ?AuthSession;

    public function revoke(string $sessionId): void;

    public function revokeAllForAccount(string $accountId, ?string $exceptSessionId = null): void;

    public function rotate(string $sessionId, AuthSession $replacement): void;

    public function touch(string $sessionId, int $lastSeenAt): void;
}
