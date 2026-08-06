<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

use Closure;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;

final class SessionManager
{
    /** @var \WeakMap<object, list<BrowserSession>> */
    private \WeakMap $fiberActive;

    /** @var list<BrowserSession> */
    private array $mainActive = [];

    private ?SessionStoreInterface $store = null;

    public function __construct(
        private readonly SessionConfig $config,
        /** @var Closure():SessionStoreInterface */
        private readonly Closure $storeFactory,
        /** @var Closure():(LockProviderInterface|null) */
        private readonly Closure $lockFactory,
        private readonly ?RuntimeContextTracker $contexts = null,
    ) {
        $this->fiberActive = new \WeakMap();
    }

    public function config(): SessionConfig
    {
        return $this->config;
    }

    public function current(): BrowserSession
    {
        $active = $this->active();

        if ($active === []) {
            throw new \LogicException('No browser session is active for the current request.');
        }

        return $active[count($active) - 1];
    }

    public function enter(BrowserSession $session): void
    {
        $this->contexts?->markSession($this);
        $active = $this->active();
        $active[] = $session;
        $this->replaceActive($active);
    }

    public function leave(BrowserSession $session): void
    {
        $sessions = $this->active();
        $active = array_pop($sessions);
        if ($active !== $session) {
            $this->replaceActive([]);

            throw new \LogicException('Browser session request scopes were left out of order.');
        }

        $this->replaceActive($sessions);
    }

    public function open(mixed $candidateId): BrowserSession
    {
        $id = is_string($candidateId)
            && preg_match('/^[a-f0-9]{64}$/D', $candidateId) === 1
            ? $candidateId
            : null;

        return new BrowserSession(
            $id,
            fn(): SessionStoreInterface => $this->store ??= ($this->storeFactory)(),
            $this->lockFactory,
            $this->config,
        );
    }

    public function prune(int $limit = 1_000): int
    {
        return ($this->store ??= ($this->storeFactory)())->prune(time(), $limit);
    }

    public function resetContext(): void
    {
        foreach (array_reverse($this->active()) as $session) {
            $session->release();
        }
        $this->replaceActive([]);
    }

    /**
     * @return list<BrowserSession>
     */
    private function active(): array
    {
        $fiber = \Fiber::getCurrent();

        return $fiber === null
            ? $this->mainActive
            : ($this->fiberActive[$fiber] ?? []);
    }

    /**
     * @param list<BrowserSession> $active
     */
    private function replaceActive(array $active): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            $this->mainActive = $active;

            return;
        }

        if ($active === []) {
            unset($this->fiberActive[$fiber]);

            return;
        }

        $this->fiberActive[$fiber] = $active;
    }
}
