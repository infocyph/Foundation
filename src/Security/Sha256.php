<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security;

/** Foundation-owned SHA-256 primitive for trusted artifact identities. */
final class Sha256
{
    public static function digest(string $payload): string
    {
        return hash('sha256', $payload);
    }
}
