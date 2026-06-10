<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\ReqShield\Support\ValidationResult;
use Infocyph\ReqShield\Validator;

final class FoundationValidator
{
    /**
     * @var array<string, Validator>
     */
    private array $validators = [];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ValidationSchemaRegistry $schemas,
    ) {}

    public function has(string $schema): bool
    {
        return $this->schemas->has($schema);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schemas(): array
    {
        return $this->schemas->all();
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function define(string $schema, array $rules): void
    {
        $this->schemas->define($schema, $rules);
        unset($this->validators[$schema]);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function extend(string $schema, array $rules): void
    {
        $this->schemas->extend($schema, $rules);
        unset($this->validators[$schema]);
    }

    public function validate(string $schema, array $payload): ValidationResult
    {
        return $this->validator($schema)->validate($payload);
    }

    private function rules(string $schema): array
    {
        $rules = $this->schemas->schema($schema);
        if (!is_array($rules) || $rules === []) {
            throw new ConfigurationException(sprintf(
                'Validation schema "%s" is not defined.',
                $schema,
            ));
        }

        return $rules;
    }

    private function validator(string $schema): Validator
    {
        if (isset($this->validators[$schema])) {
            return $this->validators[$schema];
        }

        $validator = Validator::make($this->rules($schema));
        $validator->setFailFast((bool) $this->config->get('validation.fail_fast', true));

        return $this->validators[$schema] = $validator;
    }
}
