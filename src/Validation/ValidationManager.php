<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\HasConfigSection;
use Infocyph\ReqShield\CompiledValidator;
use Infocyph\ReqShield\Support\ValidationResult;
use Infocyph\ReqShield\Validator as ReqShieldValidator;

final readonly class ValidationManager
{
    use HasConfigSection;

    public function __construct(
        private ConfigRepository $config,
        private FoundationValidator $validator,
    ) {}

    public function compile(string $schema): CompiledValidator
    {
        return $this->validator->compile($schema);
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
    public function defineFragment(string $name, array $rules): void
    {
        $this->validator->defineFragment($name, $rules);
    }

    /**
     * @return array<string, mixed>
     */
    public function exportSchema(string $schema, string $format = 'json_schema'): array
    {
        return $this->validator->exportSchema($schema, $format);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function extend(string $schema, array $rules): void
    {
        $this->validator->extend($schema, $rules);
    }

    /**
     * @return array<string, mixed>
     */
    public function fragment(string $name, string $prefix = ''): array
    {
        return $this->validator->fragment($name, $prefix);
    }

    public function hasFragment(string $name): bool
    {
        return $this->validator->hasFragment($name);
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
     * @return array<string, mixed>
     */
    public function schemaStats(string $schema): array
    {
        return $this->validator->schemaStats($schema);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function validate(string $schema, array $payload): ValidationResult
    {
        return $this->validator->validate($schema, $payload);
    }

    public function validateRequest(string $schema, object $request): ValidationResult
    {
        return $this->validator->validateRequest($schema, $request);
    }

    public function validator(string $schema): ReqShieldValidator
    {
        return $this->validator->validator($schema);
    }

    protected function configSection(): string
    {
        return 'validation';
    }
}
