<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Support;

final readonly class ConfigurationRedactor
{
    /** @var list<string> */
    private const array SENSITIVE_KEYS = [
        'authorization',
        'cookie',
        'credential',
        'key',
        'password',
        'private',
        'secret',
        'token',
    ];

    public function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->sensitive($key)) {
            return '[REDACTED]';
        }
        if (!is_array($value)) {
            return is_object($value) ? ['class' => $value::class] : $value;
        }

        $redacted = [];
        foreach ($value as $itemKey => $item) {
            $redacted[$itemKey] = $this->redact($item, is_string($itemKey) ? $itemKey : null);
        }

        return $redacted;
    }

    private function sensitive(string $key): bool
    {
        $normalized = preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;
        $segments = preg_split('/[^a-z0-9]+/', strtolower($normalized), flags: PREG_SPLIT_NO_EMPTY);
        if ($segments === false) {
            return false;
        }

        return array_any(
            $segments,
            static fn(string $segment): bool => in_array($segment, self::SENSITIVE_KEYS, true),
        );
    }
}
