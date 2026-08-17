from pathlib import Path


def replace(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text()
    if old not in text:
        raise SystemExit(f'missing expected text in {path}: {old[:120]!r}')
    p.write_text(text.replace(old, new))

# UID 5: Foundation keeps only purpose/default ID policy; specialist algorithms stay in UID.
Path('src/Identifiers/IdentifierManager.php').write_text(r'''<?php

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
        return $this->generate($this->stringConfig('ids.auth.' . $purpose, $this->defaultAuthDriver($purpose)));
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
''')

replace('src/Identifiers/IdentifierServiceProvider.php', 'use Infocyph\\Foundation\\Filesystem\\PathManager;\n', '')
replace('src/Identifiers/IdentifierServiceProvider.php', r'''        $this->bindFactory($container, IdentifierManager::class, function () use ($container): IdentifierManager {
            $config = $container->get(ConfigRepository::class);
            $paths = $container->get(PathManager::class);

            if (!$config instanceof ConfigRepository) {
                throw new \RuntimeException('Identifier config service must resolve to ConfigRepository.');
            }

            if (!$paths instanceof PathManager) {
                throw new \RuntimeException('Identifier paths service must resolve to PathManager.');
            }

            return new IdentifierManager($config, $paths);
        }, LifetimeEnum::Singleton);
''', r'''        $this->bindFactory($container, IdentifierManager::class, function () use ($container): IdentifierManager {
            $config = $container->get(ConfigRepository::class);
            if (!$config instanceof ConfigRepository) {
                throw new \RuntimeException('Identifier config service must resolve to ConfigRepository.');
            }

            return new IdentifierManager($config);
        }, LifetimeEnum::Singleton);
''')

# Epicrypt 2.1: generic crypto belongs to Epicrypt. Keep only Foundation auth-security contracts here.
Path('src/Security/SecurityManager.php').write_text(r'''<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security;

use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Support\AbstractContainerManager;

final readonly class SecurityManager extends AbstractContainerManager
{
    public function accessTokens(): AccessTokenServiceInterface
    {
        return $this->typedService(AccessTokenServiceInterface::class, 'Security access token service must resolve correctly.');
    }

    public function passwordHasher(): PasswordHasherInterface
    {
        return $this->typedService(PasswordHasherInterface::class, 'Security password hasher must resolve correctly.');
    }

    public function passwordPolicy(): PasswordPolicyInterface
    {
        return $this->typedService(PasswordPolicyInterface::class, 'Security password policy must resolve correctly.');
    }

    public function passwordVerifier(): PasswordVerifierInterface
    {
        return $this->typedService(PasswordVerifierInterface::class, 'Security password verifier must resolve correctly.');
    }

    public function refreshTokens(): RefreshTokenServiceInterface
    {
        return $this->typedService(RefreshTokenServiceInterface::class, 'Security refresh token service must resolve correctly.');
    }

    protected function configSection(): string
    {
        return 'security';
    }
}
''')

# Repair the accidental docblock opening that commented out helper methods.
replace('src/Filesystem/FilesystemResponseFactory.php', '''    /**
    private function freshRangeHeader''', '''    private function freshRangeHeader''')

# InterMix 9.1 classSettler returns ClassResolution object.
replace('src/Messaging/MessagingServiceProvider.php', "$instance = $resolved['instance'] ?? null;", '$instance = $resolved->instance;')
replace('src/Messaging/MessagingServiceProvider.php', '''        if (!is_object($instance)) {
            throw new \\InvalidArgumentException(sprintf(
                'Messaging service "%s" could not be constructed.',
                $class,
            ));
        }

        return $instance;
''', '''        return $instance;
''')

# Strongly typed artifact definition prevents mixed calls into class_exists/sprintf.
replace('src/Generator/ArtifactGenerator.php', '/** @param array<string, mixed> $definition */\n    private function assertRequirement', '/** @param array{directory:string,namespace:string,suffix:string,stub:string,requires?:class-string,install?:string} $definition */\n    private function assertRequirement')

# Small command/static cleanups.
replace('src/Command/ParsedInput.php', '$tokens = array_values(array_slice($argv, 1));', '$tokens = array_slice($argv, 1);')
replace('src/Command/CommandCatalog.php', '?->runtime ?? RuntimeMode::Cli', '->runtime ?? RuntimeMode::Cli')

# ModuleCatalog guarantees config/package/constraint/description types.
replace('src/Module/ModuleManager.php', "$configured = $definition['config'] ?? [];\n        if (!is_array($configured) || $configured === []) {", "$configured = $definition['config'];\n        if ($configured === []) {")
replace('src/Module/ModuleManager.php', '''            if (!is_string($filename) || $filename === '' || basename($filename) !== $filename) {''', '''            if ($filename === '' || basename($filename) !== $filename) {''')
replace('src/Module/ModuleManager.php', "['composer', 'require', $requirement, '--with-all-dependencies']", "['composer', 'require', $requirement, '--with-all-dependencies', '--update-no-dev']")
replace('src/Module/ModuleManager.php', "['composer', 'remove', $package, '--with-all-dependencies']", "['composer', 'remove', $package, '--with-all-dependencies', '--update-no-dev']")

# ReqShield 3.0 batch contract is already list<array<string,mixed>>.
p = Path('src/Validation/ReqShieldDatabaseProvider.php')
s = p.read_text()
s = s.replace("$column = is_array($check) ? $this->stringValue($check['column'] ?? null) : $this->stringValue($key);", "$column = $this->stringValue($check['column'] ?? null);")
s = s.replace("$value = is_array($check) ? ($check['value'] ?? null) : $check;", "$value = $check['value'] ?? null;")
s = s.replace("'identifier' => is_array($check)\n                    ? $this->identifier($check['field'] ?? $value, $key)\n                    : $this->identifier($value, $key),", "'identifier' => $this->identifier($check['field'] ?? $value, $key),")
p.write_text(s)

# Remove the temporary static snapshot workflow in the same real source commit.
Path('.github/workflows/foundation-static-snapshot.yml').unlink(missing_ok=True)
Path('tools/foundation-static-batch-2.py').unlink(missing_ok=True)
