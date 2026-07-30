<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Driver;

use BackedEnum;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class AuthDriverResolver
{
    private AuthCacheDriver $cache;

    private AuthIdDriver $ids;

    private AuthMfaDriver $mfa;

    private AuthNotificationDriver $notifications;

    private AuthPasskeyDriver $passkey;

    private AuthPasswordDriver $passwords;

    private AuthStorageDriver $storage;

    private AuthTokenDriver $tokens;

    public function __construct(
        ConfigRepository $config,
    ) {
        $this->cache = self::enumConfig($config, 'auth.drivers.cache', 'array', AuthCacheDriver::class);
        $this->ids = self::enumConfig($config, 'auth.drivers.ids', 'random', AuthIdDriver::class);
        $this->mfa = self::enumConfig($config, 'auth.drivers.mfa', 'simple', AuthMfaDriver::class);
        $this->notifications = self::enumConfig($config, 'auth.drivers.notifications', 'collect', AuthNotificationDriver::class);
        $this->passkey = self::enumConfig($config, 'auth.drivers.passkey', 'memory', AuthPasskeyDriver::class);
        $this->passwords = self::enumConfig($config, 'auth.drivers.passwords', 'native', AuthPasswordDriver::class);
        $this->storage = self::enumConfig($config, 'auth.drivers.storage', 'memory', AuthStorageDriver::class);
        $this->tokens = self::enumConfig($config, 'auth.drivers.tokens', 'simple', AuthTokenDriver::class);
    }

    public function cache(): AuthCacheDriver
    {
        return $this->cache;
    }

    public function ids(): AuthIdDriver
    {
        return $this->ids;
    }

    public function mfa(): AuthMfaDriver
    {
        return $this->mfa;
    }

    public function notifications(): AuthNotificationDriver
    {
        return $this->notifications;
    }

    public function passkey(): AuthPasskeyDriver
    {
        return $this->passkey;
    }

    public function passwords(): AuthPasswordDriver
    {
        return $this->passwords;
    }

    public function storage(): AuthStorageDriver
    {
        return $this->storage;
    }

    /**
     * @return array<string, string>
     */
    public function summary(): array
    {
        return [
            'cache' => $this->cache()->value,
            'ids' => $this->ids()->value,
            'mfa' => $this->mfa()->value,
            'notifications' => $this->notifications()->value,
            'passkey' => $this->passkey()->value,
            'passwords' => $this->passwords()->value,
            'storage' => $this->storage()->value,
            'tokens' => $this->tokens()->value,
        ];
    }

    public function tokens(): AuthTokenDriver
    {
        return $this->tokens;
    }

    /**
     * @template T of BackedEnum
     *
     * @param class-string<T> $enumClass
     * @return T
     */
    private static function enumConfig(
        ConfigRepository $config,
        string $key,
        string $default,
        string $enumClass,
    ): BackedEnum {
        $configured = $config->get($key, $default);
        $value = is_string($configured) ? $configured : $default;
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
