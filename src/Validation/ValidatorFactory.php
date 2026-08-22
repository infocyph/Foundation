<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\ReqShield\CompiledValidator;
use Infocyph\ReqShield\Contracts\DatabaseProvider;
use Infocyph\ReqShield\Validator;

/**
 * Creates native ReqShield validators from Foundation-owned named schemas.
 *
 * Rule execution, sanitization, casting, schema compilation and validation
 * semantics remain ReqShield responsibilities. Foundation only resolves the
 * application schema and applies its configured profile.
 */
final readonly class ValidatorFactory
{
    public function __construct(
        private ConfigRepository $config,
        private ValidationSchemaRegistry $schemas,
        private DatabaseProvider $database,
    ) {}

    public function compile(string $schema): CompiledValidator
    {
        return new CompiledValidator($this->make($schema));
    }

    public function make(string $schema): Validator
    {
        $validator = Validator::make($this->rules($schema), $this->database);
        $options = $this->options($schema);

        $validator->setFailFast(ValueNormalizer::bool($options['fail_fast'] ?? true, true));

        $aliases = $this->stringMap($options['aliases'] ?? null);
        if ($aliases !== []) {
            $validator->setFieldAliases($aliases);
        }

        $messages = $this->stringMap($options['messages'] ?? null);
        if ($messages !== []) {
            $validator->setCustomMessages($messages);
        }

        $sanitizers = $this->sanitizerMap($options['sanitizers'] ?? null);
        if ($sanitizers !== []) {
            $validator->setSanitizers($sanitizers);
        }

        $casts = ValueNormalizer::associativeArray($options['casts'] ?? []);
        if ($casts !== []) {
            $validator->setCasts($casts);
        }

        $locale = ValueNormalizer::nullableString($options['locale'] ?? null);
        if ($locale !== null) {
            $validator->setLocale($locale);
        }

        $localePacks = $this->localePacks($options['locale_packs'] ?? null);
        if ($localePacks !== []) {
            $validator->setLocalePacks($localePacks);
        }

        if (ValueNormalizer::bool($options['nested'] ?? false, false)) {
            $validator->setNestedFlattenMode(ValueNormalizer::string(
                $options['nested_mode'] ?? 'all',
                'all',
            ));
        }

        if (ValueNormalizer::bool($options['strip_unknown'] ?? false, false)) {
            $validator->stripUnknown();
        } elseif (ValueNormalizer::bool($options['strict'] ?? false, false)) {
            $validator->strict();
        } elseif (array_key_exists('allow_unknown', $options)) {
            $validator->allowUnknown(ValueNormalizer::bool($options['allow_unknown'], true));
        }

        if (ValueNormalizer::bool($options['throw_on_failure'] ?? false, false)) {
            $validator->throwOnFailure();
        }

        $dto = ValueNormalizer::nullableString($options['dto'] ?? null);
        if ($dto !== null) {
            $validator->setDtoClass($dto);
        }

        $limits = ValueNormalizer::associativeArray($options['limits'] ?? []);
        if ($limits !== []) {
            $validator->limits(
                maxDepth: $this->positiveInt($limits['max_depth'] ?? null, 32, 'max_depth'),
                maxFields: $this->positiveInt($limits['max_fields'] ?? null, 10_000, 'max_fields'),
                maxWildcardExpansions: $this->positiveInt(
                    $limits['max_wildcard_expansions'] ?? null,
                    10_000,
                    'max_wildcard_expansions',
                ),
                maxFlattenedPaths: $this->positiveInt(
                    $limits['max_flattened_paths'] ?? null,
                    10_000,
                    'max_flattened_paths',
                ),
            );
        }

        return $validator;
    }

    /** @return array<string, array<string, mixed>> */
    private function localePacks(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $packs = [];
        foreach ($value as $locale => $messages) {
            if (is_string($locale) && $locale !== '' && is_array($messages)) {
                $packs[$locale] = ValueNormalizer::associativeArray($messages);
            }
        }

        return $packs;
    }

    /** @return array<string, mixed> */
    private function options(string $schema): array
    {
        $defaults = ValueNormalizer::associativeArray($this->config->get('validation.defaults', []));
        $configuredOverrides = ValueNormalizer::associativeArray($this->config->get('validation.overrides', []));
        $overrides = isset($configuredOverrides[$schema]) && is_array($configuredOverrides[$schema])
            ? ValueNormalizer::associativeArray($configuredOverrides[$schema])
            : [];

        $options = array_replace([
            'fail_fast' => $this->config->get('validation.fail_fast', true),
        ], $defaults, $overrides);

        foreach (['aliases', 'casts', 'limits', 'locale_packs', 'messages', 'sanitizers'] as $key) {
            $options[$key] = array_replace(
                ValueNormalizer::associativeArray($defaults[$key] ?? []),
                ValueNormalizer::associativeArray($overrides[$key] ?? []),
            );
        }

        return $options;
    }

    private function positiveInt(mixed $value, int $default, string $name): int
    {
        if ($value === null) {
            return $default;
        }
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/D', $value) === 1)) {
            throw new ConfigurationException(sprintf('validation limits.%s must be a positive integer.', $name));
        }

        $resolved = (int) $value;
        if ($resolved < 1) {
            throw new ConfigurationException(sprintf('validation limits.%s must be a positive integer.', $name));
        }

        return $resolved;
    }

    /** @return array<string, mixed> */
    private function rules(string $schema): array
    {
        $rules = $this->schemas->schema($schema);
        if ($rules === null || $rules === []) {
            throw new ConfigurationException(sprintf('Validation schema "%s" is not defined.', $schema));
        }

        return $rules;
    }

    /** @return array<string, string|callable|list<string|callable>> */
    private function sanitizerMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $pipeline) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_string($pipeline) || is_callable($pipeline)) {
                $normalized[$key] = $pipeline;

                continue;
            }
            if (!is_array($pipeline)) {
                continue;
            }

            $steps = array_values(array_filter(
                $pipeline,
                static fn(mixed $step): bool => is_string($step) || is_callable($step),
            ));
            if ($steps !== []) {
                $normalized[$key] = $steps;
            }
        }

        return $normalized;
    }

    /** @return array<string, string> */
    private function stringMap(mixed $value): array
    {
        $normalized = [];
        foreach (ValueNormalizer::associativeArray($value) as $key => $item) {
            if (is_string($item)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }
}
