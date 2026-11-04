<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\Session\AuthSession;
use Infocyph\Foundation\Auth\Contract\Storage\SessionStoreInterface;

final class InMemorySessionStore implements SessionStoreInterface
{
    /**
     * @var array<string, AuthSession>
     */
    private array $sessions = [];

    #[\Override]
    public function create(AuthSession $session): void
    {
        $this->sessions[$session->id] = $session;
    }

    #[\Override]
    public function find(string $sessionId): ?AuthSession
    {
        return $this->sessions[$sessionId] ?? null;
    }

    #[\Override]
    public function revoke(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);
    }

    #[\Override]
    public function revokeAllForAccount(string $accountId, ?string $exceptSessionId = null): void
    {
        foreach ($this->sessions as $sessionId => $session) {
            if ($session->accountId !== $accountId) {
                continue;
            }

            if ($exceptSessionId !== null && $sessionId === $exceptSessionId) {
                continue;
            }

            unset($this->sessions[$sessionId]);
        }
    }

    #[\Override]
    public function rotate(AuthSession $current, AuthSession $replacement, int $now): bool
    {
        if (($this->sessions[$current->id] ?? null) != $current
            || $current->isExpiredAt($now)
            || $replacement->accountId !== $current->accountId
            || isset($this->sessions[$replacement->id])
        ) {
            return false;
        }

        unset($this->sessions[$current->id]);
        $this->sessions[$replacement->id] = $replacement;

        return true;
    }

    #[\Override]
    public function touch(string $sessionId, int $lastSeenAt): void
    {
        $session = $this->sessions[$sessionId] ?? null;

        if ($session === null) {
            return;
        }

        $this->sessions[$sessionId] = $session->seenAt($lastSeenAt);
    }
}
