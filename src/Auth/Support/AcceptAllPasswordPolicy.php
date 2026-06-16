<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyResult;

final class AcceptAllPasswordPolicy implements PasswordPolicyInterface
{
    public function validate(string $plainPassword, array $context = []): PasswordPolicyResult
    {
        unset($plainPassword, $context);

        return new PasswordPolicyResult(true);
    }
}
