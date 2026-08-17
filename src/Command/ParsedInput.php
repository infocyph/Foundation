<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

final readonly class ParsedInput
{
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
    public static function fromArgv(array $argv): self
    {
        $tokens = array_slice($argv, 1);
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
                $index = self::consumeLongOption($tokens, $index, $options);

                continue;
            }
            if ($command === '' && !str_starts_with($token, '-')) {
                $command = $token;

                continue;
            }
            $arguments[] = $token;
        }

        return new self($command, $arguments, $options, $tokens);
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    public function flag(string $name): bool
    {
        $value = $this->options[$name] ?? false;

        return $value === true || $value === '1' || $value === 'true';
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

    /** @param array<string, string|bool|list<string>> $options */
    private static function addOption(array &$options, string $name, string|bool $value): void
    {
        if ($name === '') {
            return;
        }
        if (!array_key_exists($name, $options)) {
            $options[$name] = $value;

            return;
        }

        $existing = $options[$name];
        $options[$name] = is_array($existing)
            ? [...$existing, (string) $value]
            : [(string) $existing, (string) $value];
    }

    /**
     * @param list<string> $tokens
     * @param array<string, string|bool|list<string>> $options
     */
    private static function consumeLongOption(array $tokens, int $index, array &$options): int
    {
        $body = substr($tokens[$index], 2);
        if ($body === '') {
            return $index;
        }
        if (str_contains($body, '=')) {
            [$name, $value] = explode('=', $body, 2);
            self::addOption($options, $name, $value);

            return $index;
        }

        $next = $tokens[$index + 1] ?? null;
        if (is_string($next) && $next !== '' && $next[0] !== '-') {
            self::addOption($options, $body, $next);

            return $index + 1;
        }

        self::addOption($options, $body, true);

        return $index;
    }
}
