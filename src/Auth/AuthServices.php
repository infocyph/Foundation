<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth;

use Infocyph\Foundation\Auth\Account\AccountManager;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationManager;
use Infocyph\Foundation\Auth\Authentication\Impersonation\ImpersonationManager;
use Infocyph\Foundation\Auth\Authentication\Lockout\LockoutManager;
use Infocyph\Foundation\Auth\Authentication\Login\Authenticator;
use Infocyph\Foundation\Auth\Authentication\Login\AuthenticatorInterface;
use Infocyph\Foundation\Auth\Authentication\PasswordChange\PasswordChangeManager;
use Infocyph\Foundation\Auth\Authentication\Passwordless\PasswordlessManager;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetManager;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberMeManager;
use Infocyph\Foundation\Auth\Authentication\Session\SessionManager;
use Infocyph\Foundation\Auth\Authentication\StepUp\StepUpManager;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\TokenAuthManager;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Authorization\Gate\Gate;
use Infocyph\Foundation\Auth\Authorization\Grant\DelegationManager;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionManager;
use Infocyph\Foundation\Auth\Authorization\Role\RoleManager;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Device\DeviceManager;
use Infocyph\Foundation\Auth\Mfa\MfaManager;
use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Auth\Passkey\PasskeyManager;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Config\ConfigRepository;
use Psr\Container\ContainerInterface;

final readonly class AuthServices
{
    public function __construct(
        private ContainerInterface $container,
        private ConfigRepository $config,
    ) {}

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function accountProvider(): AccountProviderInterface
    {
        return $this->resolve(AccountProviderInterface::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function accounts(): AccountManager
    {
        return $this->resolve(AccountManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function authenticator(): AuthenticatorInterface
    {
        return $this->resolve(Authenticator::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function authorizer(): AuthorizerInterface
    {
        return $this->resolve(AuthorizerInterface::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function delegation(): DelegationManager
    {
        return $this->resolve(DelegationManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function devices(): DeviceManager
    {
        return $this->resolve(DeviceManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function emailVerification(): EmailVerificationManager
    {
        return $this->resolve(EmailVerificationManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function gate(): Gate
    {
        return $this->resolve(Gate::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function impersonation(): ImpersonationManager
    {
        return $this->resolve(ImpersonationManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function lockouts(): LockoutManager
    {
        return $this->resolve(LockoutManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function mfa(): MfaManager
    {
        return $this->resolve(MfaManager::class);
    }

    /**
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function oauth(): OAuthManager
    {
        if ($this->config->get('auth.oauth.enabled', false) !== true) {
            throw new \LogicException('OAuth is disabled. Enable auth.oauth.enabled before requesting OAuth services.');
        }

        return $this->resolve(OAuthManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function passkeys(): PasskeyManager
    {
        return $this->resolve(PasskeyManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function passwordChanges(): PasswordChangeManager
    {
        return $this->resolve(PasswordChangeManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function passwordHasher(): PasswordHasherInterface
    {
        return $this->resolve(PasswordHasherInterface::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function passwordless(): PasswordlessManager
    {
        return $this->resolve(PasswordlessManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function passwordPolicy(): PasswordPolicyInterface
    {
        return $this->resolve(PasswordPolicyInterface::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function passwordResets(): PasswordResetManager
    {
        return $this->resolve(PasswordResetManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function passwordVerifier(): PasswordVerifierInterface
    {
        return $this->resolve(PasswordVerifierInterface::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function permissions(): PermissionManager
    {
        return $this->resolve(PermissionManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function principals(): CurrentPrincipalContext
    {
        return $this->resolve(CurrentPrincipalContext::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function rememberMe(): RememberMeManager
    {
        return $this->resolve(RememberMeManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function roles(): RoleManager
    {
        return $this->resolve(RoleManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function sessions(): SessionManager
    {
        return $this->resolve(SessionManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function stepUp(): StepUpManager
    {
        return $this->resolve(StepUpManager::class);
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    public function tokens(): TokenAuthManager
    {
        return $this->resolve(TokenAuthManager::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \UnexpectedValueException
     */
    private function resolve(string $id): object
    {
        $service = $this->container->get($id);
        if (!is_object($service)) {
            throw new \UnexpectedValueException(sprintf('Auth service "%s" did not resolve to an object.', $id));
        }

        /** @var T $service */
        return $service;
    }
}
