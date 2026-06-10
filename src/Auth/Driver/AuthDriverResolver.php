<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Driver;

use BackedEnum;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class AuthDriverResolver
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function cache(): AuthCacheDriver
    {
        /** @var AuthCacheDriver */
        return $this->enumConfig('auth.drivers.cache', 'array', AuthCacheDriver::class);
    }

    public function mfa(): AuthMfaDriver
    {
        /** @var AuthMfaDriver */
        return $this->enumConfig('auth.drivers.mfa', 'simple', AuthMfaDriver::class);
    }

    public function notifications(): AuthNotificationDriver
    {
        /** @var AuthNotificationDriver */
        return $this->enumConfig('auth.drivers.notifications', 'collect', AuthNotificationDriver::class);
    }

    public function passkey(): AuthPasskeyDriver
    {
        /** @var AuthPasskeyDriver */
        return $this->enumConfig('auth.drivers.passkey', 'memory', AuthPasskeyDriver::class);
    }

    public function passwords(): AuthPasswordDriver
    {
        /** @var AuthPasswordDriver */
        return $this->enumConfig('auth.drivers.passwords', 'native', AuthPasswordDriver::class);
    }

    public function storage(): AuthStorageDriver
    {
        /** @var AuthStorageDriver */
        return $this->enumConfig('auth.drivers.storage', 'memory', AuthStorageDriver::class);
    }

    public function tokens(): AuthTokenDriver
    {
        /** @var AuthTokenDriver */
        return $this->enumConfig('auth.drivers.tokens', 'simple', AuthTokenDriver::class);
    }

    /**
     * @return array<string, string>
     */
    public function summary(): array
    {
        return [
            'cache' => $this->cache()->value,
            'mfa' => $this->mfa()->value,
            'notifications' => $this->notifications()->value,
            'passkey' => $this->passkey()->value,
            'passwords' => $this->passwords()->value,
            'storage' => $this->storage()->value,
            'tokens' => $this->tokens()->value,
        ];
    }

    /**
     * @template T of BackedEnum
     * @param class-string<T> $enumClass
     * @return T
     */
    private function enumConfig(string $key, string $default, string $enumClass): BackedEnum
    {
        $value = (string) $this->config->get($key, $default);
        $resolved = $enumClass::tryFrom($value);

        if (!$resolved instanceof $enumClass) {
            throw new ConfigurationException(sprintf(
                'Invalid config "%s": "%s".',
                $key,
                $value,
            ));
        }

        return $resolved;
    }
}
