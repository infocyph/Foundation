<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

use Closure;
use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\Foundation\Cache\FoundationCacheKey;
use Infocyph\Webrick\Request\Request;

final class BrowserSession
{
    public const string REQUEST_ATTRIBUTE = 'foundation.session';

    private bool $accessed = false;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, true> */
    private array $flashCurrent = [];

    /** @var array<string, true> */
    private array $flashNext = [];

    private ?string $id = null;

    private bool $loaded = false;

    private ?LockHandle $lock = null;

    private ?LockProviderInterface $lockProvider = null;

    private bool $released = false;

    public function __construct(
        private readonly ?string $candidateId,
        /** @var Closure():SessionStoreInterface */
        private readonly Closure $store,
        /** @var Closure():(LockProviderInterface|null) */
        private readonly Closure $locks,
        private readonly SessionConfig $config,
    ) {}

    /**
     * @throws \LogicException
     */
    public static function fromRequest(Request $request): self
    {
        $session = $request->getAttribute(self::REQUEST_ATTRIBUTE);
        if (!$session instanceof self) {
            throw new \LogicException('This route does not have browser session middleware.');
        }

        return $session;
    }

    /**
     * @return array<string, mixed>
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function all(): array
    {
        $this->load();

        return $this->data;
    }

    /**
     * @throws \LengthException
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function commit(int $now): SessionCommit
    {
        $this->assertOpen();

        if (!$this->accessed) {
            return new SessionCommit(false, null);
        }

        $this->load();
        foreach ($this->flashCurrent as $key => $_) {
            if (!isset($this->flashNext[$key])) {
                unset($this->data[$key]);
            }
        }

        $this->assertLockOwned();

        $id = $this->id ?? self::generateId();
        $payload = new SessionPayload(
            $this->data,
            array_keys($this->flashNext),
            $now + $this->config->lifetimeSeconds,
        );
        if (strlen($payload->toJson()) > $this->config->maxPayloadBytes) {
            throw new \LengthException(sprintf(
                'Browser session payload exceeds the configured %d-byte limit.',
                $this->config->maxPayloadBytes,
            ));
        }

        ($this->store)()->save($id, $payload);

        return new SessionCommit(true, $id);
    }

    /**
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function csrfToken(): string
    {
        $this->load();
        $token = $this->data['_csrf_token'] ?? null;
        if (is_string($token) && strlen($token) === 64) {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $this->data['_csrf_token'] = $token;

        return $token;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function flash(string $key, mixed $value): void
    {
        $this->put($key, $value);
        $this->flashNext[$key] = true;
    }

    /**
     * @param array<string, mixed> $input
     * @throws \InvalidArgumentException
     */
    public function flashInput(array $input): void
    {
        $this->flash('_old_input', $input);
    }

    /**
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function forget(string ...$keys): void
    {
        $this->load();
        foreach ($keys as $key) {
            if (!array_key_exists($key, $this->data)) {
                continue;
            }

            unset($this->data[$key], $this->flashCurrent[$key], $this->flashNext[$key]);
        }
    }

    /**
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();

        return $this->data[$key] ?? $default;
    }

    /**
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function has(string $key): bool
    {
        $this->load();

        return array_key_exists($key, $this->data);
    }

    /**
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function id(): string
    {
        $this->load();

        return $this->id ?? throw new \LogicException('The browser session has no identifier.');
    }

    /**
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function invalidate(): void
    {
        $this->load();
        $this->deleteCurrent();
        $this->data = [];
        $this->flashCurrent = [];
        $this->flashNext = [];
        $this->id = self::generateId();
    }

    /**
     * @param list<string> $keys
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function keep(array $keys): void
    {
        $this->load();
        foreach ($keys as $key) {
            if (isset($this->flashCurrent[$key])) {
                $this->flashNext[$key] = true;
            }
        }
    }

    /** @return array<string, mixed> */
    public function oldInput(): array
    {
        $input = $this->get('_old_input', []);
        if (!is_array($input)) {
            return [];
        }

        $normalized = [];
        foreach ($input as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    /**
     * @throws \InvalidArgumentException
      * @throws \LogicException
      * @throws \RuntimeException
     */
    public function put(string $key, mixed $value): void
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Session keys cannot be empty.');
        }

        $this->load();
        $this->data[$key] = $value;
    }

    /**
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function reflash(): void
    {
        $this->load();
        $this->flashNext += $this->flashCurrent;
    }

    /**
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function regenerate(bool $destroy = true): string
    {
        $this->load();
        if ($destroy) {
            $this->deleteCurrent();
        }

        $this->id = self::generateId();

        return $this->id;
    }

    /**
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function regenerateCsrfToken(): string
    {
        $this->load();
        $token = bin2hex(random_bytes(32));
        $this->data['_csrf_token'] = $token;

        return $token;
    }

    public function release(): void
    {
        $this->released = true;
        $provider = $this->lockProvider;
        $lock = $this->lock;
        $this->lock = null;
        $this->lockProvider = null;
        $provider?->release($lock);
    }

    public function wasAccessed(): bool
    {
        return $this->accessed;
    }

    private static function generateId(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @throws \LogicException
     * @throws \RuntimeException
     */
    private function acquireLock(string $id): void
    {
        if (!$this->config->lockEnabled) {
            return;
        }

        $provider = ($this->locks)();
        if (!$provider instanceof LockProviderInterface) {
            throw new \LogicException(
                'Session locking requires a configured Foundation cache lock provider.',
            );
        }

        $lock = $provider->acquire(
            FoundationCacheKey::security('session-lock', 'browser-session-lock', $id),
            $this->config->lockWaitSeconds,
            $this->config->lockLeaseSeconds,
        );
        if (!$lock instanceof LockHandle) {
            throw new \RuntimeException('Timed out while waiting for the browser session lock.');
        }

        $this->lockProvider = $provider;
        $this->lock = $lock;
    }

    /**
     * @throws \RuntimeException
     */
    private function assertLockOwned(): void
    {
        if (
            $this->lock !== null
            && !$this->lockProvider?->refresh($this->lock, $this->config->lockLeaseSeconds)
        ) {
            throw new \RuntimeException('The browser session lock lease was lost before mutation.');
        }
    }

    /**
     * @throws \LogicException
     */
    private function assertOpen(): void
    {
        if ($this->released) {
            throw new \LogicException('The browser session is finalized for this request.');
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function deleteCurrent(): void
    {
        $id = $this->id;
        if ($id !== null) {
            $this->assertLockOwned();
            ($this->store)()->delete($id);
        }
    }

    /**
     * @throws \LogicException
     * @throws \RuntimeException
     */
    private function load(): void
    {
        $this->assertOpen();
        $this->accessed = true;
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        if ($this->candidateId !== null) {
            $this->acquireLock($this->candidateId);
            $payload = ($this->store)()->load($this->candidateId, time());
            if ($payload !== null) {
                $this->id = $this->candidateId;
                $this->data = $payload->data;
                $this->flashCurrent = array_fill_keys($payload->flashKeys, true);

                return;
            }
        }

        $this->id = self::generateId();
    }
}
