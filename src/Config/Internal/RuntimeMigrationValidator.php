<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config\Internal;

use Infocyph\Foundation\Config\ConfigIssue;
use Infocyph\Foundation\Config\ConfigRepository;

final readonly class RuntimeMigrationValidator
{
    public function __construct(private ConfigRepository $config) {}

    /** @return list<ConfigIssue> */
    public function validate(): array
    {
        return [
            ...$this->classList('database.migrations.classes'),
            ...$this->classList('database.seeders'),
            ...$this->settings(),
        ];
    }

    /** @return list<ConfigIssue> */
    private function classList(string $key): array
    {
        $definitions = $this->config->get($key, []);
        if (!is_array($definitions) || !array_is_list($definitions)) {
            return [new ConfigIssue($key . ' must be a list of non-empty class names.', $key)];
        }

        foreach ($definitions as $definition) {
            if (!is_string($definition) || trim($definition) === '') {
                return [new ConfigIssue($key . ' must be a list of non-empty class names.', $key)];
            }
            if (!class_exists($definition)) {
                return [new ConfigIssue($key . ' must reference existing classes.', $key)];
            }
        }

        return [];
    }

    /** @return list<ConfigIssue> */
    private function finiteNumber(string $key, float $minimum): array
    {
        $value = $this->config->get($key);
        if (!is_int($value) && !is_float($value)) {
            return [new ConfigIssue($key . ' must be a finite number.', $key)];
        }

        $number = (float) $value;

        return is_finite($number) && $number >= $minimum
            ? []
            : [new ConfigIssue($key . ' is outside its supported range.', $key)];
    }

    /** @return list<ConfigIssue> */
    private function settings(): array
    {
        $issues = [];
        $table = $this->config->get('database.migrations.table', 'migrations');
        if (!is_string($table) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table) !== 1) {
            $issues[] = new ConfigIssue(
                'database.migrations.table must be a safe SQL identifier.',
                'database.migrations.table',
            );
        }

        $lockStore = $this->config->get('database.migrations.lock_store');
        if ($lockStore !== null && (!is_string($lockStore) || $lockStore === '')) {
            $issues[] = new ConfigIssue(
                'database.migrations.lock_store must be null or a configured cache store name.',
                'database.migrations.lock_store',
            );
        }

        return [
            ...$issues,
            ...$this->finiteNumber('database.migrations.lock_wait_seconds', 0.0),
            ...$this->finiteNumber('database.migrations.lock_lease_seconds', PHP_FLOAT_MIN),
        ];
    }
}
