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

    /** Atomically replace an unchanged, live session; return false after revocation or contention. */
    public function rotate(AuthSession $current, AuthSession $replacement, int $now): bool;

    public function touch(string $sessionId, int $lastSeenAt): void;
}
