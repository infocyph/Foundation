<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\ReqShield\CompiledValidator;
use Infocyph\ReqShield\Exceptions\UnsupportedRequestObjectException;
use Infocyph\ReqShield\Support\ValidationResult;
use Infocyph\ReqShield\Validator as ReqShieldValidator;

final readonly class FoundationValidator
{
    public function __construct(
        private ConfigRepository $config,
        private ReqShieldDatabaseProvider $database,
        private ValidationSchemaRegistry $schemas,
    ) {}

    public function compile(string $schema): CompiledValidator
    {
        return new CompiledValidator($this->validator($schema));
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function define(string $schema, array $rules): void
    {
        $this->schemas->define($schema, $rules);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function defineFragment(string $name, array $rules): void
    {
        ReqShieldValidator::defineFragment($name, $rules);
    }

    /**
     * @return array<string, mixed>
     */
    public function exportSchema(string $schema, string $format = 'json_schema'): array
    {
        return $this->mixedMap($this->validator($schema)->exportSchema($format));
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function extend(string $schema, array $rules): void
    {
        $this->schemas->extend($schema, $rules);
    }

    /**
     * @return array<string, mixed>
     */
    public function fragment(string $name, string $prefix = ''): array
    {
        return $this->mixedMap(ReqShieldValidator::fragment($name, $prefix));
    }

    public function has(string $schema): bool
    {
        return $this->schemas->has($schema);
    }

    public function hasFragment(string $name): bool
    {
        return ReqShieldValidator::hasFragment($name);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schemas(): array
    {
        return $this->schemas->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function schemaStats(string $schema): array
    {
        return $this->mixedMap($this->validator($schema)->getSchemaStats());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function validate(string $schema, array $payload): ValidationResult
    {
        return $this->validator($schema)->validate($payload);
    }

    public function validateRequest(string $schema, object $request): ValidationResult
    {
        return $this->validator($schema)->validate($this->requestPayload($request));
    }

    public function validator(string $schema): ReqShieldValidator
    {
        $validator = ReqShieldValidator::make($this->rules($schema), $this->database);
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

        $casts = $this->mixedMap($options['casts'] ?? null);
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
            $validator->enableNestedValidation($this->nestedFlattenAll($options));
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

        return $validator;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function localePacks(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $packs = [];

        foreach ($value as $locale => $messages) {
            if (!is_string($locale) || $locale === '' || !is_array($messages)) {
                continue;
            }

            $packs[$locale] = $this->mixedMap($messages);
        }

        return $packs;
    }

    /**
     * @return array<string, mixed>
     */
    private function mixedMap(mixed $value): array
    {
        return ValueNormalizer::associativeArray($value);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function nestedFlattenAll(array $options): bool
    {
        return ValueNormalizer::string($options['nested_mode'] ?? 'all', 'all') !== 'required';
    }

    /**
     * @param array<mixed> $value
     * @return array<int|string, mixed>
     */
    private function numericPayloadEntries(array $value): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = [];

        foreach ($value as $key => $item) {
            if (!is_int($key)) {
                continue;
            }

            $normalized[(string) $key] = $item;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(string $schema): array
    {
        $legacy = [
            'fail_fast' => $this->config->get('validation.fail_fast', true),
            'allow_unknown' => $this->config->get('validation.allow_unknown', true),
            'strip_unknown' => $this->config->get('validation.strip_unknown', false),
            'strict' => $this->config->get('validation.strict', false),
            'nested' => $this->config->get('validation.nested', false),
            'nested_mode' => $this->config->get('validation.nested_mode', 'all'),
            'throw_on_failure' => $this->config->get('validation.throw_on_failure', false),
            'locale' => $this->config->get('validation.locale'),
            'dto' => $this->config->get('validation.dto'),
        ];
        $legacyMaps = [
            'aliases' => [],
            'casts' => [],
            'locale_packs' => [],
            'messages' => [],
            'sanitizers' => [],
        ];

        $defaults = ValueNormalizer::associativeArray($this->config->get('validation.defaults', []));
        $configuredOverrides = ValueNormalizer::associativeArray($this->config->get('validation.overrides', []));
        $overrides = isset($configuredOverrides[$schema]) && is_array($configuredOverrides[$schema])
            ? ValueNormalizer::associativeArray($configuredOverrides[$schema])
            : [];
        $options = array_replace($legacy, $defaults, $overrides);

        foreach (array_keys($legacyMaps) as $key) {
            $options[$key] = array_replace(
                $legacyMaps[$key],
                $this->mixedMap($defaults[$key] ?? null),
                $this->mixedMap($overrides[$key] ?? null),
            );
        }

        return $options;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function requestPayload(object $request): array
    {
        $payload = [];
        $hasAccessor = false;

        foreach ([
            'getQueryParams',
            'getParsedBody',
            'getUploadedFiles',
            'getAttributes',
        ] as $accessor) {
            if (!method_exists($request, $accessor)) {
                continue;
            }

            $hasAccessor = true;
            $payload = array_replace($payload, $this->requestValue($request->{$accessor}()));
        }

        if (!$hasAccessor) {
            throw UnsupportedRequestObjectException::missingRequestAccessors();
        }

        return $payload;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function requestValue(mixed $value): array
    {
        if (is_array($value)) {
            return ValueNormalizer::associativeArray($value) + $this->numericPayloadEntries($value);
        }

        if ($value instanceof \Traversable) {
            return $this->requestValue(iterator_to_array($value));
        }

        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                $result = $value->toArray();

                return is_array($result)
                    ? $this->requestValue($result)
                    : [];
            }

            return ValueNormalizer::associativeArray(get_object_vars($value));
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, string|callable|list<string|callable>>
     */
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

            $steps = [];

            foreach ($pipeline as $step) {
                if (is_string($step) || is_callable($step)) {
                    $steps[] = $step;
                }
            }

            if ($steps !== []) {
                $normalized[$key] = $steps;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        $normalized = [];

        foreach ($this->mixedMap($value) as $key => $item) {
            if (!is_string($item)) {
                continue;
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }
}
