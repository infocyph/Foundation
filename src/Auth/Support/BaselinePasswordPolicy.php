<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyResult;

final readonly class BaselinePasswordPolicy implements PasswordPolicyInterface
{
    public function __construct(
        private int $minimumLength = 12,
        private int $maximumLength = 1024,
    ) {
        if ($this->minimumLength < 1) {
            throw new \InvalidArgumentException('Password minimum length must be at least 1.');
        }
        if ($this->maximumLength < $this->minimumLength) {
            throw new \InvalidArgumentException('Password maximum length must be greater than or equal to the minimum length.');
        }
    }

    public function validate(string $plainPassword, array $context = []): PasswordPolicyResult
    {
        unset($context);

        $length = function_exists('mb_strlen')
            ? mb_strlen($plainPassword, 'UTF-8')
            : strlen($plainPassword);
        $violations = [];

        if ($length < $this->minimumLength) {
            $violations[] = sprintf('Password must be at least %d characters.', $this->minimumLength);
        }
        if ($length > $this->maximumLength) {
            $violations[] = sprintf('Password must not exceed %d characters.', $this->maximumLength);
        }

        return $violations === []
            ? new PasswordPolicyResult(true)
            : new PasswordPolicyResult(false, $violations, 'password_policy_failed');
    }
}
