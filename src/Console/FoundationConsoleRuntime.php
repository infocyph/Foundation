<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console;

use Closure;
use Infocyph\Console\Configuration\Configuration;
use Infocyph\Console\Configuration\ConfigurationProvider;
use Infocyph\Console\Container\ContainerProvider;
use Infocyph\Foundation\Application\Application;
use Infocyph\InterMix\DI\Container;

/**
 * Defers the Foundation application until Console dispatches a real command.
 */
final class FoundationConsoleRuntime implements ConfigurationProvider, ContainerProvider
{
    private ?Application $application = null;

    private ?string $profile = null;

    /**
     * The factory receives the selected Console profile, or null when none was
     * requested. It is never invoked for help, list, completion, or version.
     *
     * @param Closure(?string): Application $applicationFactory
     */
    public function __construct(private readonly Closure $applicationFactory) {}

    public function application(): Application
    {
        if ($this->application !== null) {
            return $this->application;
        }

        $application = ($this->applicationFactory)($this->profile);
        if (!$application->runningInConsole()) {
            throw new \LogicException('Foundation Console requires an application created with Foundation::console().');
        }

        return $this->application = $application;
    }

    public function configuration(): Configuration
    {
        return Configuration::fromConfig($this->application()->config());
    }

    public function container(): Container
    {
        return $this->application()->container();
    }

    public function useProfile(?string $profile): void
    {
        if ($this->application !== null && $profile !== $this->profile) {
            throw new \LogicException('The Foundation Console profile cannot change after application creation.');
        }

        $this->profile = $profile;
    }
}
