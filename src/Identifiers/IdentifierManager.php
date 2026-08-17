<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Identifiers;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\UID\Enums\UlidGenerationMode;
use Infocyph\UID\Id;
use Infocyph\UID\RandomId;

final readonly class IdentifierManager
{
    public function __construct(private ConfigRepository $config) {}

    /** @param array<string, mixed> $options */
    public function generate(?string $driver = null, array $options = []): string
    {
        $driver = $this->normalizeDriver($driver ?? $this->stringConfig('ids.default', 'uuid7'));

        return match ($driver) {
            'cuid2' => Id::cuid2($this->intOption($options, 'length', $this->intConfig('ids.cuid2.length', 24))),
            'deterministic' => Id::deterministic(
                $this->requiredString($options, 'payload'),
                $this->intOption($options, 'length', $this->intConfig('ids.deterministic.length', 24)),
                $this->stringOption($options, 'namespace', $this->stringConfig('ids.deterministic.namespace', 'default')),
            ),
            'ksuid' => Id::ksuid(),
            'nanoid' => Id::nanoId($this->intOption($options, 'length', $this->intConfig('ids.nanoid.length', 21))),
            'random' => Id::random(
                $this->intOption($options, 'length', 21),
                $this->stringOption($options, 'alphabet', RandomId::DEFAULT_ALPHABET),
            ),
            'ulid' => Id::ulid(null, $this->ulidMode($options['mode'] ?? null)),
            'uuid', 'uuid7' => Id::uuid7(),
            'uuid1' => Id::uuid1($this->nullableString($options['node'] ?? null)),
            'uuid4' => Id::uuid4(),
            'uuid6' => Id::uuid6($this->nullableString($options['node'] ?? null)),
            'uuid8' => Id::uuid8($this->nullableString($options['node'] ?? null)),
            'xid' => Id::xid(),
            default => throw new \InvalidArgumentException(sprintf('Unsupported configured ID driver "%s".', $driver)),
        };
    }

    public function generateForAuth(string $purpose): string
    {
        return $this->generate($this->stringConfig('ids.auth.'.$purpose, $this->defaultAuthDriver($purpose)));
    }

    private function defaultAuthDriver(string $purpose): string
    {
        return $purpose === 'correlation' ? 'ulid' : 'uuid7';
    }

    private function intConfig(string $key, int $default): int
    {
        return $this->config->getInt($key, $default) ?? $default;
    }

    /** @param array<string, mixed> $options */
    private function intOption(array $options, string $key, int $default): int
    {
        $value = $options[$key] ?? null;

        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function normalizeDriver(string $driver): string
    {
        return str_replace(['-', ' '], '_', strtolower(trim($driver)));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $options */
    private function requiredString(array $options, string $key): string
    {
        $value = $this->nullableString($options[$key] ?? null);
        if ($value === null) {
            throw new \InvalidArgumentException(sprintf('ID option "%s" is required.', $key));
        }

        return $value;
    }

    private function stringConfig(string $key, string $default): string
    {
        return $this->config->getString($key, $default) ?? $default;
    }

    /** @param array<string, mixed> $options */
    private function stringOption(array $options, string $key, string $default): string
    {
        return $this->nullableString($options[$key] ?? null) ?? $default;
    }

    private function ulidMode(mixed $value): UlidGenerationMode
    {
        if ($value instanceof UlidGenerationMode) {
            return $value;
        }
        $configured = is_string($value) ? $value : $this->stringConfig('ids.ulid.mode', 'monotonic');

        return UlidGenerationMode::tryFrom(strtolower($configured)) ?? UlidGenerationMode::MONOTONIC;
    }
}
