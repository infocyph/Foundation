<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

use Closure;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Psr\Container\ContainerInterface;

final readonly class SessionManager
{
    public function __construct(
        private SessionConfig $config,
        /** @var Closure():SessionStoreInterface */
        private Closure $storeFactory,
        /** @var Closure():(LockProviderInterface|null) */
        private Closure $lockFactory,
        private ContainerInterface $container,
    ) {}

    public function config(): SessionConfig
    {
        return $this->config;
    }

    public function current(): BrowserSession
    {
        $active = $this->state()->active;

        if ($active === []) {
            throw new \LogicException('No browser session is active for the current request.');
        }

        return $active[count($active) - 1];
    }

    public function enter(BrowserSession $session): void
    {
        $state = $this->state();
        $state->active[] = $session;
    }

    public function leave(BrowserSession $session): void
    {
        $state = $this->state();
        $active = array_pop($state->active);
        if ($active !== $session) {
            $state->active = [];

            throw new \LogicException('Browser session request scopes were left out of order.');
        }
    }

    public function open(mixed $candidateId): BrowserSession
    {
        $id = is_string($candidateId)
            && preg_match('/^[a-f0-9]{64}$/D', $candidateId) === 1
            ? $candidateId
            : null;

        return new BrowserSession(
            $id,
            fn(): SessionStoreInterface => $this->store(),
            $this->lockFactory,
            $this->config,
        );
    }

    public function prune(int $limit = 1_000): int
    {
        return $this->store()->prune(time(), $limit);
    }

    public function resetContext(): void
    {
        $this->state()->reset();
    }

    private function state(): SessionExecutionState
    {
        $state = $this->container->get(SessionExecutionState::class);
        if (!$state instanceof SessionExecutionState) {
            throw new \LogicException('SessionExecutionState binding is invalid.');
        }

        return $state;
    }

    private function store(): SessionStoreInterface
    {
        $state = $this->state();

        return $state->store ??= ($this->storeFactory)();
    }
}
