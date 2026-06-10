<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

final readonly class ConfigValidationResult
{
    /**
     * @param list<ConfigIssue> $issues
     */
    public function __construct(
        private array $issues = [],
    ) {}

    public function fails(): bool
    {
        return $this->issues !== [];
    }

    /**
     * @return list<ConfigIssue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(
            static fn(ConfigIssue $issue): string => $issue->message,
            $this->issues,
        );
    }

    public function passes(): bool
    {
        return !$this->fails();
    }

    /**
     * @return array{
     *   valid: bool,
     *   issues: list<array{message: string, key: string, severity: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->passes(),
            'issues' => array_map(
                static fn(ConfigIssue $issue): array => [
                    'message' => $issue->message,
                    'key' => $issue->key,
                    'severity' => $issue->severity,
                ],
                $this->issues,
            ),
        ];
    }
}
