<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

final readonly class ParsedInput
{
    /** @var array<string, array{name:string,short:?string,accepts_value:bool,multiple:bool,negatable:bool}> */
    private const array GLOBAL_OPTIONS = [
        'help' => ['name' => 'help', 'short' => 'h', 'accepts_value' => false, 'multiple' => false, 'negatable' => false],
        'version' => ['name' => 'version', 'short' => 'V', 'accepts_value' => false, 'multiple' => false, 'negatable' => false],
        'quiet' => ['name' => 'quiet', 'short' => 'q', 'accepts_value' => false, 'multiple' => false, 'negatable' => false],
        'no-interaction' => ['name' => 'no-interaction', 'short' => 'n', 'accepts_value' => false, 'multiple' => false, 'negatable' => false],
        'json' => ['name' => 'json', 'short' => null, 'accepts_value' => false, 'multiple' => false, 'negatable' => false],
        'env' => ['name' => 'env', 'short' => null, 'accepts_value' => true, 'multiple' => false, 'negatable' => false],
    ];

    /**
     * @param list<string> $arguments
     * @param array<string, string|bool|list<string>> $options
     * @param list<string> $raw
     */
    public function __construct(
        public string $command,
        public array $arguments,
        public array $options,
        public array $raw,
    ) {}

    /** @param list<string> $argv */
    public static function fromArgv(array $argv, ?CommandDefinition $definition = null): self
    {
        $tokens = array_values(array_slice($argv, 1));
        $command = '';
        $arguments = [];
        $options = [];

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if ($token === '--') {
                $arguments = [...$arguments, ...array_slice($tokens, $index + 1)];

                break;
            }
            if (str_starts_with($token, '--')) {
                $index = self::consumeLongOption($tokens, $index, $options, $definition);

                continue;
            }
            if ($token !== '-' && str_starts_with($token, '-')) {
                $index = self::consumeShortOptions($tokens, $index, $options, $definition);

                continue;
            }
            if ($command === '') {
                $command = $token;

                continue;
            }
            $arguments[] = $token;
        }

        $input = new self($command, $arguments, $options, $tokens);
        if ($definition !== null) {
            self::validateArguments($input, $definition);
        }

        return $input;
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    public function flag(string $name): bool
    {
        $value = $this->options[$name] ?? false;
        if (is_array($value)) {
            $value = end($value);
        }

        return $value === true || $value === '1' || $value === 'true';
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $last = end($value);

            return is_string($last) ? $last : $default;
        }

        return $default;
    }

    /** @return list<string> */
    public function values(string $name): array
    {
        $value = $this->options[$name] ?? null;
        if (is_string($value)) {
            return [$value];
        }
        if (is_array($value)) {
            return array_values($value);
        }

        return [];
    }

    /** @param array<string, string|bool|list<string>> $options */
    private static function addOption(array &$options, string $name, string|bool $value, bool $multiple): void
    {
        if ($name === '') {
            return;
        }
        if (!array_key_exists($name, $options)) {
            $options[$name] = $value;

            return;
        }
        if (!$multiple) {
            $options[$name] = $value;

            return;
        }

        $existing = $options[$name];
        $options[$name] = is_array($existing)
            ? [...$existing, (string) $value]
            : [(string) $existing, (string) $value];
    }

    /**
     * @return array{name:string,short:?string,accepts_value:bool,multiple:bool,negatable:bool}|null
     */
    private static function longMetadata(string $name, ?CommandDefinition $definition): ?array
    {
        $option = $definition?->options()[$name] ?? null;
        if (is_array($option)) {
            return [
                'name' => $option['name'],
                'short' => $option['short'],
                'accepts_value' => $option['accepts_value'],
                'multiple' => $option['multiple'],
                'negatable' => $option['negatable'],
            ];
        }

        return self::GLOBAL_OPTIONS[$name] ?? null;
    }

    /**
     * @return array{name:string,short:?string,accepts_value:bool,multiple:bool,negatable:bool}|null
     */
    private static function shortMetadata(string $short, ?CommandDefinition $definition): ?array
    {
        $option = $definition?->optionByShort($short);
        if (is_array($option)) {
            return [
                'name' => $option['name'],
                'short' => $option['short'],
                'accepts_value' => $option['accepts_value'],
                'multiple' => $option['multiple'],
                'negatable' => $option['negatable'],
            ];
        }

        foreach (self::GLOBAL_OPTIONS as $metadata) {
            if ($metadata['short'] === $short) {
                return $metadata;
            }
        }

        return null;
    }

    /**
     * @param list<string> $tokens
     * @param array<string, string|bool|list<string>> $options
     */
    private static function consumeLongOption(
        array $tokens,
        int $index,
        array &$options,
        ?CommandDefinition $definition,
    ): int {
        $body = substr($tokens[$index], 2);
        if ($body === '') {
            return $index;
        }

        $inlineValue = null;
        if (str_contains($body, '=')) {
            [$body, $inlineValue] = explode('=', $body, 2);
        }

        $negated = false;
        $name = $body;
        $metadata = self::longMetadata($name, $definition);
        if ($metadata === null && str_starts_with($name, 'no-')) {
            $candidate = substr($name, 3);
            $candidateMetadata = self::longMetadata($candidate, $definition);
            if ($candidateMetadata !== null && $candidateMetadata['negatable']) {
                $name = $candidate;
                $metadata = $candidateMetadata;
                $negated = true;
            }
        }

        if ($definition === null && $metadata === null) {
            if ($inlineValue !== null) {
                self::addOption($options, $name, $inlineValue, true);

                return $index;
            }
            $next = $tokens[$index + 1] ?? null;
            if (is_string($next) && $next !== '' && $next[0] !== '-') {
                self::addOption($options, $name, $next, true);

                return $index + 1;
            }
            self::addOption($options, $name, true, true);

            return $index;
        }

        if ($metadata === null) {
            throw new \InvalidArgumentException(sprintf('Unknown option "--%s".', $name));
        }
        if ($negated) {
            if ($inlineValue !== null) {
                throw new \InvalidArgumentException(sprintf('Negated option "--no-%s" does not accept a value.', $name));
            }
            self::addOption($options, $name, false, $metadata['multiple']);

            return $index;
        }
        if (!$metadata['accepts_value']) {
            if ($inlineValue !== null && !in_array(strtolower($inlineValue), ['0', '1', 'false', 'true'], true)) {
                throw new \InvalidArgumentException(sprintf('Flag option "--%s" does not accept a value.', $name));
            }
            self::addOption(
                $options,
                $name,
                $inlineValue === null ? true : in_array(strtolower($inlineValue), ['1', 'true'], true),
                $metadata['multiple'],
            );

            return $index;
        }

        $value = $inlineValue;
        if ($value === null) {
            $value = $tokens[$index + 1] ?? null;
            if (!is_string($value) || $value === '--') {
                throw new \InvalidArgumentException(sprintf('Option "--%s" requires a value.', $name));
            }
            $index++;
        }
        self::addOption($options, $name, $value, $metadata['multiple']);

        return $index;
    }

    /**
     * @param list<string> $tokens
     * @param array<string, string|bool|list<string>> $options
     */
    private static function consumeShortOptions(
        array $tokens,
        int $index,
        array &$options,
        ?CommandDefinition $definition,
    ): int {
        $cluster = substr($tokens[$index], 1);
        if ($cluster === '') {
            return $index;
        }

        for ($offset = 0, $length = strlen($cluster); $offset < $length; $offset++) {
            $short = $cluster[$offset];
            $metadata = self::shortMetadata($short, $definition);
            if ($definition === null && $metadata === null) {
                self::addOption($options, $short, true, true);

                continue;
            }
            if ($metadata === null) {
                throw new \InvalidArgumentException(sprintf('Unknown option "-%s".', $short));
            }
            if (!$metadata['accepts_value']) {
                self::addOption($options, $metadata['name'], true, $metadata['multiple']);

                continue;
            }

            $value = substr($cluster, $offset + 1);
            if ($value === '') {
                $value = $tokens[$index + 1] ?? null;
                if (!is_string($value) || $value === '--') {
                    throw new \InvalidArgumentException(sprintf('Option "-%s" requires a value.', $short));
                }
                $index++;
            }
            self::addOption($options, $metadata['name'], $value, $metadata['multiple']);

            break;
        }

        return $index;
    }

    private static function validateArguments(self $input, CommandDefinition $definition): void
    {
        $declared = $definition->arguments();
        $required = count(array_filter($declared, static fn(array $argument): bool => $argument['required']));
        if (count($input->arguments) < $required) {
            $missing = $declared[count($input->arguments())]['name'] ?? 'argument';
            throw new \InvalidArgumentException(sprintf('Missing required argument "%s".', $missing));
        }

        $variadic = $declared !== [] && $declared[array_key_last($declared)]['variadic'];
        if (!$variadic && count($input->arguments) > count($declared)) {
            throw new \InvalidArgumentException('Too many command arguments were provided.');
        }
    }
}
