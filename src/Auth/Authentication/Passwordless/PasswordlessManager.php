<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Passwordless;

use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;
use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;
use Infocyph\Foundation\Auth\Notification\AuthNotification;
use Infocyph\Foundation\Auth\Notification\AuthNotificationType;
use Infocyph\Foundation\Cache\FoundationCacheKey;

final readonly class PasswordlessManager
{
    public function __construct(
        private PasswordlessTokenServiceInterface $tokens,
        private AuthNotifierInterface $notifier,
        private TtlStoreInterface $ttl,
        private int $ttlSeconds,
    ) {}

    /**
     * @param array<string, mixed> $context
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function issue(string $identifier, array $context = []): PasswordlessResult
    {
        $token = $this->tokens->issue($identifier, $context);
        $this->ttl->put($this->key($token), true, $this->ttlSeconds);
        $this->notifier->send(new AuthNotification(AuthNotificationType::PASSWORDLESS_LOGIN_REQUESTED, null, ['identifier' => $identifier, 'token' => $token] + $context));

        return new PasswordlessResult(PasswordlessStatus::ISSUED, $token, code: 'passwordless_token_issued', context: $context);
    }

    /**
     * @param array<string, mixed> $context
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function verify(string $token, array $context = []): PasswordlessResult
    {
        $verification = $this->tokens->verify($token);
        if ($verification->verified && $this->ttl->pull($this->key($token)) !== true) {
            $verification = new TokenVerificationResult(false, failureReason: 'invalid_token');
        }

        return new PasswordlessResult(
            $verification->verified ? PasswordlessStatus::VERIFIED : (($verification->failureReason === 'expired_token') ? PasswordlessStatus::EXPIRED : PasswordlessStatus::INVALID),
            token: $token,
            verification: $verification,
            code: $verification->verified ? 'passwordless_token_verified' : ($verification->failureReason ?? 'passwordless_token_invalid'),
            context: $context,
        );
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    private function key(string $token): string
    {
        return FoundationCacheKey::security('pl', 'foundation.auth.passwordless.redemption.v1', $token);
    }
}
