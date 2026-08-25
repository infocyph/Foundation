<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

final class CacheNamespace
{
    public static function derive(string $prefix, string $name): string
    {
        $namespace = $prefix . $name;
        $normalized = preg_replace('/[^A-Za-z0-9_.-]+/', '.', $namespace) ?? '';
        $normalized = trim($normalized, '.');
        if ($normalized === '') {
            $normalized = 'foundation';
        }
        if (strlen($normalized) <= 64) {
            return $normalized;
        }

        return substr($normalized, 0, 47) . '.' . substr(hash('sha256', $namespace), 0, 16);
    }
}
