<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Epicrypt\Password\PasswordHasher;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;

final readonly class EpicryptPasswordHasher implements PasswordHasherInterface
{
    public function __construct(
        private PasswordHasher $hasher,
    ) {}

    public function hash(string $plainPassword, array $context = []): string
    {
        unset($context);

        return $this->hasher->hashPassword($plainPassword);
    }
}
