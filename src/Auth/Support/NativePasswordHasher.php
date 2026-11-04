<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;

final class NativePasswordHasher implements PasswordHasherInterface
{
    #[\Override]
    public function hash(string $plainPassword, array $context = []): string
    {
        unset($context);

        return password_hash($plainPassword, PASSWORD_DEFAULT);
    }
}
