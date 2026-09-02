<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Epicrypt\Password\PasswordHasher as EpicryptPasswordEngine;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPasswordHasher;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPasswordVerifier;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthPasswordDriver;
use Infocyph\Foundation\Auth\Support\NativePasswordHasher;
use Infocyph\Foundation\Auth\Support\NativePasswordVerifier;

final readonly class AuthPasswordRegistrar extends AbstractAuthRegistrar
{
    public function register(AuthDriverResolver $drivers): void
    {
        if ($drivers->passwords() === AuthPasswordDriver::SECURITY) {
            $this->requirePackage(EpicryptPasswordEngine::class, 'infocyph/epicrypt', 'crypto');
            $this->recipe(PasswordHasherInterface::class, EpicryptPasswordHasher::class, [
                $this->ref(EpicryptPasswordEngine::class),
            ]);
            $this->recipe(PasswordVerifierInterface::class, EpicryptPasswordVerifier::class, [
                $this->ref(EpicryptPasswordEngine::class),
            ]);

            return;
        }

        $this->recipe(PasswordHasherInterface::class, NativePasswordHasher::class);
        $this->recipe(PasswordVerifierInterface::class, NativePasswordVerifier::class, [
            $this->ref(PasswordHasherInterface::class),
        ]);
    }
}
