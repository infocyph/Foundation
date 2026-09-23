<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnConfigResolver;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthPasskeyDriver;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyServiceInterface;
use Infocyph\Foundation\Auth\Support\DisabledPasskeyService;
use Infocyph\Foundation\Auth\Support\InMemoryPasskeyService;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\OTP\Passkey;
use Webauthn\PublicKeyCredential;

final readonly class AuthPasskeyRegistrar extends AbstractAuthRegistrar
{
    public function register(AuthDriverResolver $drivers): void
    {
        $driver = $drivers->passkey();

        if ($driver === AuthPasskeyDriver::DISABLED) {
            $this->recipe(PasskeyServiceInterface::class, DisabledPasskeyService::class);

            return;
        }

        if ($driver === AuthPasskeyDriver::WEBAUTHN) {
            $this->requirePackage(Passkey::class, 'infocyph/otp', 'passkeys');
            $this->requirePackage(PublicKeyCredential::class, 'web-auth/webauthn-lib', 'passkeys');
            $this->recipe(WebAuthnConfigResolver::class, WebAuthnConfigResolver::class, [
                $this->ref(ConfigRepository::class),
            ]);

            $configured = $this->app->config()->get('auth.passkey.state.store');
            $storeName = is_string($configured) && trim($configured) !== '' ? trim($configured) : null;

            $this->staticRecipe(
                Passkey::class,
                AuthPasskeyGraphFactory::class,
                'passkey',
                [
                    $this->ref(WebAuthnConfigResolver::class),
                    $this->ref(CacheLayerFactory::class),
                    $storeName,
                ],
            );
            $this->staticRecipe(
                PasskeyServiceInterface::class,
                AuthPasskeyGraphFactory::class,
                'service',
                [
                    $this->ref(Passkey::class),
                    $this->ref(PasskeyCredentialStoreInterface::class),
                    $this->ref(AccountProviderInterface::class),
                    $this->ref(AuthIdGeneratorInterface::class),
                    $this->ref(ClockInterface::class),
                ],
            );

            return;
        }

        $this->recipe(PasskeyServiceInterface::class, InMemoryPasskeyService::class, [
            $this->ref(PasskeyCredentialStoreInterface::class),
            $this->ref(ClockInterface::class),
            $this->intConfig('auth.passkey_challenge_ttl', 300),
        ]);
    }
}
