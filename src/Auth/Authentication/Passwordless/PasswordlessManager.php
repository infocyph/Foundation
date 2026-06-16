<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Passwordless;

use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Notification\AuthNotification;
use Infocyph\Foundation\Auth\Notification\AuthNotificationType;

final readonly class PasswordlessManager
{
    public function __construct(
        private PasswordlessTokenServiceInterface $tokens,
        private AuthNotifierInterface $notifier,
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function issue(string $identifier, array $context = []): PasswordlessResult
    {
        $token = $this->tokens->issue($identifier, $context);
        $this->notifier->send(new AuthNotification(AuthNotificationType::PASSWORDLESS_LOGIN_REQUESTED, null, ['identifier' => $identifier, 'token' => $token] + $context));

        return new PasswordlessResult(PasswordlessStatus::ISSUED, $token, code: 'passwordless_token_issued', context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function verify(string $token, array $context = []): PasswordlessResult
    {
        $verification = $this->tokens->verify($token);

        return new PasswordlessResult(
            $verification->verified ? PasswordlessStatus::VERIFIED : (($verification->failureReason === 'expired_token') ? PasswordlessStatus::EXPIRED : PasswordlessStatus::INVALID),
            token: $token,
            verification: $verification,
            code: $verification->verified ? 'passwordless_token_verified' : ($verification->failureReason ?? 'passwordless_token_invalid'),
            context: $context,
        );
    }
}
