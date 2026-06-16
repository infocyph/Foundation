<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\WebAuthn;

use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;

final readonly class WebAuthnChallengeStore
{
    public function __construct(
        private TtlStoreInterface $ttl,
        private string $prefix = 'webauthn:challenge:',
    ) {}

    public function get(string $challengeId): ?WebAuthnChallengeRecord
    {
        $payload = $this->ttl->get($this->key($challengeId));

        return is_array($payload)
            ? WebAuthnChallengeRecord::fromArray($payload)
            : null;
    }

    public function pull(string $challengeId): ?WebAuthnChallengeRecord
    {
        $payload = $this->ttl->pull($this->key($challengeId));

        return is_array($payload)
            ? WebAuthnChallengeRecord::fromArray($payload)
            : null;
    }

    public function put(WebAuthnChallengeRecord $record, int $ttlSeconds): void
    {
        $this->ttl->put($this->key($record->id), $record->toArray(), $ttlSeconds);
    }

    private function key(string $challengeId): string
    {
        return $this->prefix . $challengeId;
    }
}
