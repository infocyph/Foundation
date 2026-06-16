<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;

final class NativePasswordHasher implements PasswordHasherInterface
{
    public function hash(string $plainPassword, array $context = []): string
    {
        return password_hash($plainPassword, PASSWORD_DEFAULT);
    }
}
