<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config\Internal;

use Infocyph\Foundation\Config\ConfigIssue;
use Infocyph\Foundation\Config\ConfigRepository;
use Psr\Log\LogLevel;

final readonly class RuntimeLoggingValidator
{
    public function __construct(private ConfigRepository $config) {}

    /** @return list<ConfigIssue> */
    public function validate(): array
    {
        $issues = [
            ...$this->allowedString(
                'logging.driver',
                $this->config->get('logging.driver', 'null'),
                ['null', 'file', 'error_log'],
            ),
            ...$this->allowedString(
                'logging.level',
                $this->config->get('logging.level', LogLevel::WARNING),
                [
                    LogLevel::DEBUG,
                    LogLevel::INFO,
                    LogLevel::NOTICE,
                    LogLevel::WARNING,
                    LogLevel::ERROR,
                    LogLevel::CRITICAL,
                    LogLevel::ALERT,
                    LogLevel::EMERGENCY,
                ],
            ),
        ];

        foreach (['include_message', 'include_trace'] as $option) {
            $key = 'logging.exceptions.' . $option;
            if (!is_bool($this->config->get($key, false))) {
                $issues[] = new ConfigIssue($key . ' must be true or false.', $key);
            }
        }

        return [...$issues, ...$this->collections(), ...$this->limits()];
    }

    /**
     * @param list<string> $allowed
     * @return list<ConfigIssue>
     */
    private function allowedString(string $key, mixed $value, array $allowed): array
    {
        return is_string($value) && in_array($value, $allowed, true)
            ? []
            : [new ConfigIssue(
                sprintf('%s must be one of: %s.', $key, implode(', ', $allowed)),
                $key,
            )];
    }

    /** @return list<ConfigIssue> */
    private function collections(): array
    {
        $issues = [];
        $redact = $this->config->get('logging.redact', []);
        if (!is_array($redact)
            || array_any($redact, static fn(mixed $key): bool => !is_string($key) || trim($key) === '')
        ) {
            $issues[] = new ConfigIssue(
                'logging.redact must be a list of non-empty key fragments.',
                'logging.redact',
            );
        }

        $ignored = $this->config->get('logging.exceptions.ignore', []);
        if (!is_array($ignored)
            || array_any(
                $ignored,
                static fn(mixed $class): bool => !is_string($class)
                    || trim($class) === ''
                    || !is_a($class, \Throwable::class, true),
            )
        ) {
            $issues[] = new ConfigIssue(
                'logging.exceptions.ignore must be a list of non-empty Throwable class names.',
                'logging.exceptions.ignore',
            );
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function finiteNumber(string $key, float $minimum, ?float $maximum = null): array
    {
        $value = $this->config->get($key);
        if (!is_int($value) && !is_float($value)) {
            return [new ConfigIssue($key . ' must be a finite number.', $key)];
        }

        $number = (float) $value;

        return is_finite($number) && $number >= $minimum && ($maximum === null || $number <= $maximum)
            ? []
            : [new ConfigIssue($key . ' is outside its supported range.', $key)];
    }

    /** @return list<ConfigIssue> */
    private function limits(): array
    {
        $issues = [
            ...$this->finiteNumber('logging.exceptions.sample_rate', 0.0, 1.0),
            ...$this->positiveInteger('logging.exceptions.throttle_seconds', 0),
            ...$this->positiveInteger('logging.exceptions.throttle_limit', 1),
        ];
        $path = $this->config->get('logging.path');
        if ($this->config->get('logging.driver') === 'file'
            && $path !== null
            && (!is_string($path) || trim($path) === '')
        ) {
            $issues[] = new ConfigIssue(
                'logging.path must be null or a non-empty filename for the file driver.',
                'logging.path',
            );
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function positiveInteger(string $key, int $minimum): array
    {
        $value = $this->config->get($key);

        return is_int($value) && $value >= $minimum
            ? []
            : [new ConfigIssue(
                sprintf('%s must be an integer of at least %d.', $key, $minimum),
                $key,
            )];
    }
}
