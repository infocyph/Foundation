<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Epicrypt;

use Infocyph\AuthLayer\Contract\Security\PasswordHasherInterface;
use Infocyph\Epicrypt\Password\PasswordHasher;

final readonly class EpicryptPasswordHasher implements PasswordHasherInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private PasswordHasher $hasher,
        private array $options = [],
    ) {}

    public function hash(string $plainPassword, array $context = []): string
    {
        return $this->hasher->hashPassword($plainPassword, $this->options + $context);
    }
}
