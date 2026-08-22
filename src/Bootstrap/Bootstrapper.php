<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Bootstrap;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ProviderFileLoader;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProviderInterface;
use Infocyph\Foundation\Auth\AuthOtpServiceProvider;
use Infocyph\Foundation\Auth\AuthServiceProvider;
use Infocyph\Foundation\Cache\CacheServiceProvider;
use Infocyph\Foundation\Communication\CommunicationServiceProvider;
use Infocyph\Foundation\Database\DatabaseServiceProvider;
use Infocyph\Foundation\Exception\BootstrapException;
use Infocyph\Foundation\Filesystem\FilesystemServiceProvider;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Filesystem\PathServiceProvider;
use Infocyph\Foundation\Http\HttpServiceProvider;
use Infocyph\Foundation\Http\JsonDispatch\JsonDispatchServiceProvider;
use Infocyph\Foundation\Logging\LoggingServiceProvider;
use Infocyph\Foundation\Messaging\MessagingServiceProvider;
use Infocyph\Foundation\Notifications\NotificationServiceProvider;
use Infocyph\Foundation\Routing\RouteCachePath;
use Infocyph\Foundation\Routing\RouteFileLoader;
use Infocyph\Foundation\Routing\RoutingServiceProvider;
use Infocyph\Foundation\Security\SecurityServiceProvider;
use Infocyph\Foundation\Session\SessionServiceProvider;
use Infocyph\Foundation\Validation\ValidationServiceProvider;
use Psr\Log\LoggerInterface;

final class Bootstrapper
{
    /** @var list<class-string<ServiceProviderInterface>> */
    private const array COMMON_EAGER_PROVIDERS = [PathServiceProvider::class];

    /** @var list<class-string<ServiceProviderInterface>> */
    private const array WEB_EAGER_PROVIDERS = [
        RoutingServiceProvider::class,
        LoggingServiceProvider::class,
        HttpServiceProvider::class,
    ];

    public function activateProviderFor(Application $app, string $service): bool
    {
        $provider = $this->providerFor($service);
        if ($provider === null || !$this->providerAllowed($app, $service, $provider)) {
            return false;
        }

        if ($provider === AuthOtpServiceProvider::class) {
            return $this->activateProvider($app, $provider)
                && $this->activateProvider($app, AuthServiceProvider::class);
        }

        return $this->activateProvider($app, $provider);
    }

    public function boot(Application $app): void
    {
        $app->providers()->boot($app);

        if ($app->runningInWeb()
            && $app->has(RouteFileLoader::class)
            && !RouteCachePath::isWarm($app->config())
        ) {
            $app->make(RouteFileLoader::class)->load();
        }
    }

    public function canProvide(Application $app, string $service): bool
    {
        $provider = $this->providerFor($service);

        return $provider !== null && $this->providerAllowed($app, $service, $provider);
    }

    public function manages(string $service): bool
    {
        return $this->providerFor($service) !== null;
    }

    public function prepare(Application $app): void
    {
        $eager = self::COMMON_EAGER_PROVIDERS;
        if ($app->runtimeMode() === RuntimeMode::Web) {
            $eager = [...$eager, ...self::WEB_EAGER_PROVIDERS];
        }

        foreach ($eager as $provider) {
            $app->register($this->instantiateProvider($provider));
        }
        $app->providers()->register($app);

        foreach ($this->configuredProviders($app) as $provider) {
            $app->register($provider);
        }
        foreach ($this->providerFileProviders($app) as $provider) {
            $app->register($provider);
        }
        $app->providers()->register($app);
    }

    public function unavailableServiceMessage(string $service): ?string
    {
        $provider = $this->providerFor($service);
        $dependency = $provider === null ? null : $this->providerDependency($provider);
        if ($dependency === null || class_exists($dependency['class'])) {
            return null;
        }

        return sprintf(
            'Foundation service "%s" requires %s; install module "%s".',
            $service,
            $dependency['package'],
            $dependency['module'],
        );
    }

    /** @param class-string<ServiceProviderInterface> $provider */
    private function activateProvider(Application $app, string $provider): bool
    {
        if ($app->providers()->activate($provider, $app)) {
            return true;
        }

        $app->providers()->addDeferred($provider);

        return $app->providers()->activate($provider, $app);
    }

    /** @return list<ServiceProviderInterface> */
    private function configuredProviders(Application $app): array
    {
        $configured = $app->config()->get('providers', []);
        if (!is_array($configured)) {
            return [];
        }
        if ($configured !== [] && array_is_list($configured)) {
            throw new BootstrapException(
                'Configured providers must define common, web, cli, worker, and scheduler provider groups.',
            );
        }

        $providers = [];
        foreach (['common', $app->runtimeMode()->value] as $group) {
            $entries = $configured[$group] ?? [];
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $provider) {
                $instance = $this->instantiateProvider($provider);
                $providers[$instance::class] = $instance;
            }
        }

        return array_values($providers);
    }

    private function instantiateProvider(mixed $provider): ServiceProviderInterface
    {
        if ($provider instanceof ServiceProviderInterface) {
            return $provider;
        }
        if (!is_string($provider) || $provider === '' || !class_exists($provider)) {
            throw new BootstrapException('Configured provider must be an existing non-empty class name.');
        }
        if (!is_a($provider, ServiceProviderInterface::class, true)) {
            throw new BootstrapException(sprintf(
                'Configured provider "%s" must implement %s.',
                $provider,
                ServiceProviderInterface::class,
            ));
        }

        return new $provider();
    }

    /** @param class-string<ServiceProviderInterface> $provider */
    private function providerAllowed(Application $app, string $service, string $provider): bool
    {
        if (!$this->providerDependencyAvailable($provider)) {
            return false;
        }

        if ($provider !== HttpServiceProvider::class) {
            return true;
        }

        return $app->runningInWeb()
            && ($service === 'foundation.http' || str_starts_with($service, 'Infocyph\\Foundation\\Http\\'));
    }

    /**
     * @param class-string<ServiceProviderInterface> $provider
     * @return array{class:class-string,module:string,package:string}|null
     */
    private function providerDependency(string $provider): ?array
    {
        return match ($provider) {
            AuthOtpServiceProvider::class => ['class' => \Infocyph\OTP\TOTP::class, 'module' => 'otp', 'package' => 'infocyph/otp'],
            CacheServiceProvider::class => ['class' => \Infocyph\CacheLayer\Cache\Cache::class, 'module' => 'cache', 'package' => 'infocyph/cachelayer'],
            CommunicationServiceProvider::class => ['class' => \Infocyph\TalkingBytes\Http\HttpClient::class, 'module' => 'communication', 'package' => 'infocyph/talkingbytes'],
            NotificationServiceProvider::class => ['class' => \Infocyph\TalkingBytes\Email\Emailer::class, 'module' => 'communication', 'package' => 'infocyph/talkingbytes'],
            DatabaseServiceProvider::class => ['class' => \Infocyph\DBLayer\DB::class, 'module' => 'db', 'package' => 'infocyph/dblayer'],
            MessagingServiceProvider::class => ['class' => \Infocyph\Omnibus\MessageBus::class, 'module' => 'messaging', 'package' => 'infocyph/omnibus'],
            FilesystemServiceProvider::class => ['class' => \Infocyph\Pathwise\PathwiseFacade::class, 'module' => 'filesystem', 'package' => 'infocyph/pathwise'],
            SecurityServiceProvider::class => ['class' => \Infocyph\Epicrypt\Crypto\AeadCipher::class, 'module' => 'crypto', 'package' => 'infocyph/epicrypt'],
            ValidationServiceProvider::class => ['class' => \Infocyph\ReqShield\Validator::class, 'module' => 'validation', 'package' => 'infocyph/reqshield'],
            default => null,
        };
    }

    /** @param class-string<ServiceProviderInterface> $provider */
    private function providerDependencyAvailable(string $provider): bool
    {
        $dependency = $this->providerDependency($provider);

        return $dependency === null || class_exists($dependency['class']);
    }

    /** @return list<ServiceProviderInterface> */
    private function providerFileProviders(Application $app): array
    {
        if ($app->config()->isCompiled()) {
            return [];
        }

        $providers = [];
        foreach (new ProviderFileLoader($app->make(PathManager::class))->providers($app->runtimeMode()) as $provider) {
            $instance = $this->instantiateProvider($provider);
            $providers[$instance::class] = $instance;
        }

        return array_values($providers);
    }

    /** @return class-string<ServiceProviderInterface>|null */
    private function providerFor(string $service): ?string
    {
        $aliases = [
            'foundation.auth' => AuthServiceProvider::class,
            'foundation.cache' => CacheServiceProvider::class,
            'foundation.communication' => CommunicationServiceProvider::class,
            'foundation.crypto' => SecurityServiceProvider::class,
            'foundation.db' => DatabaseServiceProvider::class,
            'foundation.email' => NotificationServiceProvider::class,
            'foundation.files' => FilesystemServiceProvider::class,
            'foundation.filesystem' => FilesystemServiceProvider::class,
            'foundation.logging' => LoggingServiceProvider::class,
            'foundation.messaging' => MessagingServiceProvider::class,
            'foundation.notifications' => NotificationServiceProvider::class,
            'foundation.paths' => PathServiceProvider::class,
            'foundation.router' => RoutingServiceProvider::class,
            'foundation.responses' => JsonDispatchServiceProvider::class,
            'foundation.security' => SecurityServiceProvider::class,
            'foundation.session' => SessionServiceProvider::class,
            'foundation.validator' => ValidationServiceProvider::class,
        ];

        return $aliases[$service] ?? match (true) {
            str_starts_with($service, 'Infocyph\\Foundation\\Auth\\Adapter\\Otp\\'),
            str_starts_with($service, 'Infocyph\\Foundation\\Auth\\Otp\\'),
            $service === \Infocyph\OTP\RecoveryCodes::class,
            $service === \Infocyph\OTP\Contracts\RecoveryCodeStoreInterface::class => AuthOtpServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Auth\\') => AuthServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Cache\\') => CacheServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Communication\\') => CommunicationServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Database\\') => DatabaseServiceProvider::class,
            $service === PathManager::class,
            $service === PathServiceProvider::class => PathServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Filesystem\\'),
            $service === \League\Flysystem\FilesystemOperator::class,
            $service === \Infocyph\Pathwise\StreamHandler\UploadProcessor::class,
            $service === \Infocyph\Pathwise\StreamHandler\DownloadProcessor::class => FilesystemServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Logging\\'),
            $service === LoggerInterface::class => LoggingServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Messaging\\'),
            str_starts_with($service, 'Infocyph\\Omnibus\\') => MessagingServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Notifications\\'),
            str_starts_with($service, 'Infocyph\\TalkingBytes\\Email\\') => NotificationServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Http\\JsonDispatch\\'),
            str_starts_with($service, 'Infocyph\\Foundation\\Http\\Resource\\') => JsonDispatchServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Http\\Middleware\\'),
            str_starts_with($service, 'Infocyph\\Foundation\\Http\\Resolver\\') => AuthServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Http\\') => HttpServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Routing\\') => RoutingServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Security\\'),
            str_starts_with($service, 'Infocyph\\Epicrypt\\') => SecurityServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Session\\') => SessionServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Validation\\') => ValidationServiceProvider::class,
            str_starts_with($service, 'Infocyph\\TalkingBytes\\Grpc\\'),
            str_starts_with($service, 'Infocyph\\TalkingBytes\\Http\\'),
            str_starts_with($service, 'Infocyph\\TalkingBytes\\Webhook\\') => CommunicationServiceProvider::class,
            default => null,
        };
    }
}
