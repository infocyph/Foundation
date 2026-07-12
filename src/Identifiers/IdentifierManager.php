<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Identifiers;

use DateTimeInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\UID\Configuration\RandflakeConfig;
use Infocyph\UID\Configuration\SnowflakeConfig;
use Infocyph\UID\Configuration\SonyflakeConfig;
use Infocyph\UID\Configuration\TBSLConfig;
use Infocyph\UID\Contracts\IdValueInterface;
use Infocyph\UID\CUID2;
use Infocyph\UID\Enums\ClockBackwardPolicy;
use Infocyph\UID\Enums\IdOutputType;
use Infocyph\UID\Enums\UlidGenerationMode;
use Infocyph\UID\Id;
use Infocyph\UID\IdComparator;
use Infocyph\UID\KSUID;
use Infocyph\UID\NanoID;
use Infocyph\UID\OpaqueId;
use Infocyph\UID\Randflake;
use Infocyph\UID\Sequence\FilesystemSequenceProvider;
use Infocyph\UID\Sequence\InMemorySequenceProvider;
use Infocyph\UID\Sequence\SequenceProviderInterface;
use Infocyph\UID\Snowflake;
use Infocyph\UID\Sonyflake;
use Infocyph\UID\TBSL;
use Infocyph\UID\ULID;
use Infocyph\UID\UUID;
use Infocyph\UID\XID;

final readonly class IdentifierManager
{
    public function __construct(
        private ConfigRepository $config,
        private PathManager $paths,
    ) {}

    public function compare(IdValueInterface|string $left, IdValueInterface|string $right): int
    {
        return IdComparator::compare($left, $right);
    }

    public function cuid2(?int $length = null): string
    {
        return Id::cuid2($length ?? $this->intConfig('ids.cuid2.length', 24));
    }

    public function deterministic(string $payload, ?int $length = null, ?string $namespace = null): string
    {
        return Id::deterministic(
            payload: $payload,
            length: $length ?? $this->intConfig('ids.deterministic.length', 24),
            namespace: $namespace ?? $this->stringConfig('ids.deterministic.namespace', 'default'),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generate(?string $driver = null, array $options = []): int|string
    {
        return match ($this->normalizeDriver($driver ?? $this->stringConfig('ids.default', 'uuid7'))) {
            'cuid2' => $this->cuid2($this->nullableIntOption($options, 'length')),
            'deterministic' => $this->deterministic(
                payload: $this->requiredStringOption($options, 'payload'),
                length: $this->nullableIntOption($options, 'length'),
                namespace: $this->nullableStringOption($options, 'namespace'),
            ),
            'ksuid' => $this->ksuid($this->dateTimeOption($options)),
            'nanoid' => $this->nanoId($this->nullableIntOption($options, 'length')),
            'opaque' => $this->opaque($this->nullableIntOption($options, 'length')),
            'randflake' => $this->randflake($this->randflakeConfigOption($options)),
            'randflake_string' => $this->randflakeString($this->randflakeConfigOption($options)),
            'snowflake' => $this->snowflake($this->snowflakeConfigOption($options)),
            'sonyflake' => $this->sonyflake($this->sonyflakeConfigOption($options)),
            'tbsl' => $this->tbsl($this->tbslConfigOption($options)),
            'ulid' => $this->ulid(
                dateTime: $this->dateTimeOption($options),
                mode: $this->ulidModeOption($options),
            ),
            'uuid', 'uuid7' => $this->uuid7(
                dateTime: $this->dateTimeOption($options),
                node: $this->nullableStringOption($options, 'node'),
            ),
            'uuid1' => $this->uuid1($this->nullableStringOption($options, 'node')),
            'uuid4' => $this->uuid4(),
            'uuid6' => $this->uuid6($this->nullableStringOption($options, 'node')),
            'uuid8' => $this->uuid8($this->nullableStringOption($options, 'node')),
            'xid' => $this->xid(),
            default => throw new \RuntimeException(sprintf('Unsupported ID driver "%s".', (string) $driver)),
        };
    }

    public function generateForAuth(string $key): string
    {
        $driver = $this->stringConfig('ids.auth.' . $key, $this->defaultAuthDriver($key));

        return (string) $this->generate($driver);
    }

    public function isValid(string $driver, string $id): bool
    {
        return match ($this->normalizeDriver($driver)) {
            'cuid2' => CUID2::isValid($id),
            'ksuid' => KSUID::isValid($id),
            'nanoid' => NanoID::isValid($id),
            'randflake' => Randflake::isValid($id),
            'randflake_string' => $this->randflakeStringIsValid($id),
            'snowflake' => Snowflake::isValid($id),
            'sonyflake' => Sonyflake::isValid($id),
            'tbsl' => TBSL::isValid($id),
            'ulid' => ULID::isValid($id),
            'uuid', 'uuid1', 'uuid4', 'uuid6', 'uuid7', 'uuid8' => UUID::isValid($id),
            'xid' => XID::isValid($id),
            default => throw new \RuntimeException(sprintf('Unsupported ID driver "%s".', $driver)),
        };
    }

    public function ksuid(?DateTimeInterface $dateTime = null): string
    {
        return Id::ksuid($dateTime);
    }

    public function nanoId(?int $length = null): string
    {
        return Id::nanoId($length ?? $this->intConfig('ids.nanoid.length', 21));
    }

    public function opaque(?int $length = null): string
    {
        return Id::opaque($length ?? $this->intConfig('ids.opaque.length', 12));
    }

    public function opaqueFromInt(int $value, ?string $salt = null): string
    {
        return OpaqueId::fromInt(
            value: $value,
            salt: $salt ?? $this->stringConfig('ids.opaque.salt', ''),
        );
    }

    public function opaqueToInt(string $token, ?string $salt = null): int
    {
        return OpaqueId::toInt(
            token: $token,
            salt: $salt ?? $this->stringConfig('ids.opaque.salt', ''),
        );
    }

    /**
     * @return array<string, mixed>
     * @param array<string, mixed> $options
     */
    public function parse(string $driver, string $id, array $options = []): array
    {
        return match ($this->normalizeDriver($driver)) {
            'cuid2' => CUID2::parse($id, $this->nullableIntOption($options, 'length')),
            'ksuid' => KSUID::parse($id),
            'nanoid' => NanoID::parse($id, $this->nullableIntOption($options, 'length')),
            'randflake' => Randflake::parse($id, $this->randflakeSecret($options)),
            'randflake_string' => Randflake::parseString($id, $this->randflakeSecret($options)),
            'snowflake' => $this->snowflakeEpoch($options) !== null
                ? Snowflake::parseWithEpoch($id, $this->snowflakeEpoch($options))
                : Snowflake::parse($id),
            'sonyflake' => $this->sonyflakeEpoch($options) !== null
                ? Sonyflake::parseWithEpoch($id, $this->sonyflakeEpoch($options))
                : Sonyflake::parse($id),
            'tbsl' => TBSL::parse($id),
            'ulid' => [
                'isValid' => ULID::isValid($id),
                'time' => ULID::isValid($id) ? ULID::getTime($id) : null,
            ],
            'uuid', 'uuid1', 'uuid4', 'uuid6', 'uuid7', 'uuid8' => Id::uuidParse($id),
            'xid' => XID::parse($id),
            default => throw new \RuntimeException(sprintf('Unsupported ID driver "%s".', $driver)),
        };
    }

    public function randflake(?RandflakeConfig $config = null): int|string
    {
        return Id::randflake($config ?? $this->randflakeConfig());
    }

    public function randflakeString(?RandflakeConfig $config = null): string
    {
        return Id::randflakeString($config ?? $this->randflakeConfig());
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public function sequenceProvider(?array $config = null): SequenceProviderInterface
    {
        $driver = $this->normalizeDriver(
            $this->stringValue($config['driver'] ?? null)
            ?? $this->stringConfig('ids.sequence.driver', 'filesystem'),
        );

        return match ($driver) {
            'file', 'filesystem' => new FilesystemSequenceProvider(
                $this->sequenceDirectory($config),
                $this->intValue($config['wait_time'] ?? null) ?? $this->intConfig('ids.sequence.wait_time', 1000),
                $this->intValue($config['max_attempts'] ?? null) ?? $this->intConfig('ids.sequence.max_attempts', 1000),
            ),
            'memory' => new InMemorySequenceProvider(),
            default => throw new \RuntimeException(sprintf('Unsupported sequence provider driver "%s".', $driver)),
        };
    }

    public function snowflake(?SnowflakeConfig $config = null): int|string
    {
        return Id::snowflake($config ?? $this->snowflakeConfig());
    }

    public function sonyflake(?SonyflakeConfig $config = null): int|string
    {
        return Id::sonyflake($config ?? $this->sonyflakeConfig());
    }

    /**
     * @param array<int, IdValueInterface|string> $ids
     * @return array<int, IdValueInterface|string>
     */
    public function sort(array $ids): array
    {
        return IdComparator::sort($ids);
    }

    public function tbsl(?TBSLConfig $config = null): int|string
    {
        return Id::tbsl($config ?? $this->tbslConfig());
    }

    public function ulid(?DateTimeInterface $dateTime = null, UlidGenerationMode|string|null $mode = null): string
    {
        return Id::ulid(
            dateTime: $dateTime,
            mode: $this->ulidMode($mode),
        );
    }

    public function uuid(?DateTimeInterface $dateTime = null, ?string $node = null): string
    {
        return Id::uuid($dateTime, $node);
    }

    public function uuid1(?string $node = null): string
    {
        return Id::uuid1($node);
    }

    public function uuid3(string $namespace, string $name): string
    {
        return Id::uuid3($namespace, $name);
    }

    public function uuid4(): string
    {
        return Id::uuid4();
    }

    public function uuid5(string $namespace, string $name): string
    {
        return Id::uuid5($namespace, $name);
    }

    public function uuid6(?string $node = null): string
    {
        return Id::uuid6($node);
    }

    public function uuid7(?DateTimeInterface $dateTime = null, ?string $node = null): string
    {
        return Id::uuid7($dateTime, $node);
    }

    public function uuid8(?string $node = null): string
    {
        return Id::uuid8($node);
    }

    public function uuidFromBase(string $encoded, int $base): string
    {
        return UUID::fromBase($encoded, $base);
    }

    public function uuidFromBytes(string $bytes): string
    {
        return UUID::fromBytes($bytes);
    }

    public function uuidToBase(string $uuid, int $base): string
    {
        return UUID::toBase($uuid, $base);
    }

    public function uuidToBytes(string $uuid): string
    {
        return UUID::toBytes($uuid);
    }

    public function xid(): string
    {
        return Id::xid();
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\\/]|\\\\\\\\|\\/)/i', $path) === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayConfig(string $key): ?array
    {
        $value = $this->config->get($key);
        if (!is_array($value)) {
            return null;
        }

        $config = [];
        foreach ($value as $configKey => $entry) {
            if (!is_string($configKey)) {
                continue;
            }

            $config[$configKey] = $entry;
        }

        return $config;
    }

    private function clockBackwardPolicy(string $value): ClockBackwardPolicy
    {
        return ClockBackwardPolicy::tryFrom(strtolower($value)) ?? ClockBackwardPolicy::WAIT;
    }

    private function customEpochValue(mixed $epoch): DateTimeInterface|int|string|null
    {
        return $epoch instanceof DateTimeInterface || is_int($epoch) || is_string($epoch)
            ? $epoch
            : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function dateTimeOption(array $options): ?DateTimeInterface
    {
        $value = $options['date_time'] ?? $options['dateTime'] ?? null;

        return $value instanceof DateTimeInterface
            ? $value
            : null;
    }

    private function defaultAuthDriver(string $key): string
    {
        return match ($key) {
            'correlation' => 'ulid',
            default => 'uuid7',
        };
    }

    private function intConfig(string $key, int $default): int
    {
        return $this->config->getInt($key, $default) ?? $default;
    }

    private function intValue(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function normalizeDriver(string $driver): string
    {
        return str_replace(['-', ' '], '_', strtolower($driver));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function nullableIntOption(array $options, string $key): ?int
    {
        return $this->intValue($options[$key] ?? null);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function nullableStringOption(array $options, string $key): ?string
    {
        return $this->stringValue($options[$key] ?? null);
    }

    private function outputType(string $value): IdOutputType
    {
        return IdOutputType::tryFrom(strtolower($value)) ?? IdOutputType::STRING;
    }

    private function randflakeConfig(): RandflakeConfig
    {
        return new RandflakeConfig(
            nodeId: $this->intConfig('ids.randflake.node_id', 0),
            leaseStart: $this->intConfig('ids.randflake.lease_start', 0),
            leaseEnd: $this->intConfig('ids.randflake.lease_end', 0),
            secret: $this->stringConfig('ids.randflake.secret', 'change-me'),
            sequenceProvider: $this->sequenceProvider($this->arrayConfig('ids.randflake.sequence')),
            outputType: $this->outputType($this->stringConfig('ids.randflake.output', 'string')),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function randflakeConfigOption(array $options): ?RandflakeConfig
    {
        $config = $options['config'] ?? null;

        return $config instanceof RandflakeConfig
            ? $config
            : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function randflakeSecret(array $options): string
    {
        return $this->stringValue($options['secret'] ?? null)
            ?? $this->stringConfig('ids.randflake.secret', 'change-me');
    }

    private function randflakeStringIsValid(string $id): bool
    {
        try {
            Randflake::decodeString($id);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function requiredStringOption(array $options, string $key): string
    {
        $value = $this->stringValue($options[$key] ?? null);
        if ($value === null || $value === '') {
            throw new \RuntimeException(sprintf('ID option "%s" is required.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed>|null $config
     */
    private function sequenceDirectory(?array $config = null): string
    {
        $directory = $this->stringValue($config['directory'] ?? null)
            ?? $this->stringConfig('ids.sequence.directory', $this->paths->cache('ids'));

        $resolved = $this->absolute($directory)
            ? $directory
            : $this->paths->base($directory);

        if (!is_dir($resolved) && !mkdir($resolved, 0775, true) && !is_dir($resolved)) {
            throw new \RuntimeException(sprintf('Unable to create ID sequence directory "%s".', $resolved));
        }

        return $resolved;
    }

    private function snowflakeConfig(): SnowflakeConfig
    {
        return new SnowflakeConfig(
            datacenterId: $this->intConfig('ids.snowflake.datacenter_id', 0),
            workerId: $this->intConfig('ids.snowflake.worker_id', 0),
            customEpoch: $this->customEpochValue($this->config->get('ids.snowflake.custom_epoch')),
            sequenceProvider: $this->sequenceProvider($this->arrayConfig('ids.snowflake.sequence')),
            clockBackwardPolicy: $this->clockBackwardPolicy($this->stringConfig('ids.snowflake.clock_backward_policy', 'wait')),
            outputType: $this->outputType($this->stringConfig('ids.snowflake.output', 'string')),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function snowflakeConfigOption(array $options): ?SnowflakeConfig
    {
        $config = $options['config'] ?? null;

        return $config instanceof SnowflakeConfig
            ? $config
            : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function snowflakeEpoch(array $options): ?int
    {
        $epoch = $this->intValue($options['custom_epoch'] ?? null);
        if ($epoch !== null) {
            return $epoch;
        }

        return $this->intValue($this->config->get('ids.snowflake.custom_epoch'));
    }

    private function sonyflakeConfig(): SonyflakeConfig
    {
        return new SonyflakeConfig(
            machineId: $this->intConfig('ids.sonyflake.machine_id', 0),
            customEpoch: $this->customEpochValue($this->config->get('ids.sonyflake.custom_epoch')),
            sequenceProvider: $this->sequenceProvider($this->arrayConfig('ids.sonyflake.sequence')),
            clockBackwardPolicy: $this->clockBackwardPolicy($this->stringConfig('ids.sonyflake.clock_backward_policy', 'wait')),
            outputType: $this->outputType($this->stringConfig('ids.sonyflake.output', 'string')),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function sonyflakeConfigOption(array $options): ?SonyflakeConfig
    {
        $config = $options['config'] ?? null;

        return $config instanceof SonyflakeConfig
            ? $config
            : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function sonyflakeEpoch(array $options): ?int
    {
        $epoch = $this->intValue($options['custom_epoch'] ?? null);
        if ($epoch !== null) {
            return $epoch;
        }

        return $this->intValue($this->config->get('ids.sonyflake.custom_epoch'));
    }

    private function stringConfig(string $key, string $default): string
    {
        return $this->config->getString($key, $default) ?? $default;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function tbslConfig(): TBSLConfig
    {
        return new TBSLConfig(
            machineId: $this->intConfig('ids.tbsl.machine_id', 0),
            sequenced: $this->config->getBool('ids.tbsl.sequenced', false) ?? false,
            sequenceProvider: $this->sequenceProvider($this->arrayConfig('ids.tbsl.sequence')),
            clockBackwardPolicy: $this->clockBackwardPolicy($this->stringConfig('ids.tbsl.clock_backward_policy', 'wait')),
            outputType: $this->outputType($this->stringConfig('ids.tbsl.output', 'string')),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function tbslConfigOption(array $options): ?TBSLConfig
    {
        $config = $options['config'] ?? null;

        return $config instanceof TBSLConfig
            ? $config
            : null;
    }

    private function ulidMode(UlidGenerationMode|string|null $mode): UlidGenerationMode
    {
        if ($mode instanceof UlidGenerationMode) {
            return $mode;
        }

        return UlidGenerationMode::tryFrom(
            strtolower($mode ?? $this->stringConfig('ids.ulid.mode', 'monotonic')),
        ) ?? UlidGenerationMode::MONOTONIC;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function ulidModeOption(array $options): UlidGenerationMode|string|null
    {
        $mode = $options['mode'] ?? null;

        return $mode instanceof UlidGenerationMode || is_string($mode)
            ? $mode
            : null;
    }
}
