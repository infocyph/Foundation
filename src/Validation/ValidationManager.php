<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\ReqShield\Support\ValidationResult;

final readonly class ValidationManager
{
    public function __construct(
        private ConfigRepository $config,
        private FoundationValidator $validator,
    ) {}

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $this->config->get('validation', []);
        }

        return $this->config->get('validation.' . $key, $default);
    }

    public function hasSchema(string $name): bool
    {
        return $this->validator->has($name);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schemas(): array
    {
        return $this->validator->schemas();
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function define(string $schema, array $rules): void
    {
        $this->validator->define($schema, $rules);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function extend(string $schema, array $rules): void
    {
        $this->validator->extend($schema, $rules);
    }

    public function validate(string $schema, array $payload): ValidationResult
    {
        return $this->validator->validate($schema, $payload);
    }
}
