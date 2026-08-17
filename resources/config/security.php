<?php

declare(strict_types=1);

$env = static function (string $key, mixed $default = null): mixed {
    $value = getenv($key);

    return $value === false ? $default : $value;
};

$envString = static function (string $key, string $default) use ($env): string {
    $value = $env($key);

    return is_string($value) && $value !== '' ? $value : $default;
};

$envInt = static function (string $key, int $default) use ($env): int {
    $value = $env($key);

    return is_numeric($value) ? (int) $value : $default;
};

return [
    'password' => [
        'algorithm' => $envString('SECURITY_PASSWORD_ALGORITHM', 'argon2id'),
        'memory_cost' => $envInt('SECURITY_PASSWORD_MEMORY_COST', PASSWORD_ARGON2_DEFAULT_MEMORY_COST),
        'time_cost' => $envInt('SECURITY_PASSWORD_TIME_COST', PASSWORD_ARGON2_DEFAULT_TIME_COST),
        'threads' => $envInt('SECURITY_PASSWORD_THREADS', PASSWORD_ARGON2_DEFAULT_THREADS),
        'cost' => $envInt('SECURITY_PASSWORD_BCRYPT_COST', 12),
    ],
    'jwt' => [
        'audience' => $env('SECURITY_JWT_AUDIENCE'),
        'issuer' => $env('SECURITY_JWT_ISSUER'),
        'maximum_lifetime_seconds' => $envInt('SECURITY_JWT_MAXIMUM_LIFETIME_SECONDS', 1209600),
        'leeway_seconds' => $envInt('SECURITY_JWT_LEEWAY_SECONDS', 0),
    ],
    'integrity' => [
        'algorithm' => $envString('SECURITY_INTEGRITY_ALGORITHM', 'sha256'),
    ],
    'key_rings' => [],
];
