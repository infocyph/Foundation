<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\HasConfigSection;
use Infocyph\ReqShield\Support\ValidationResult;

final readonly class ValidationManager
{
    use HasConfigSection;

    public function __construct(
        private ConfigRepository $config,
        private FoundationValidator $validator,
    ) {}

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
     * @param array<string, mixed> $payload
     */
    public function validate(string $schema, array $payload): ValidationResult
    {
        return $this->validator->validate($schema, $payload);
    }

    protected function configSection(): string
    {
        return 'validation';
    }
}
