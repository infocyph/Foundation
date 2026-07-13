<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks;

use Infocyph\Foundation\Auth\Support\RandomAuthIdGenerator;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Foundation;
use PhpBench\Attributes as Bench;

#[Bench\Revs(10)]
#[Bench\Iterations(3)]
#[Bench\Warmup(1)]
final class FoundationBench
{
    private string $basePath;

    private ConfigRepository $config;

    private RandomAuthIdGenerator $ids;

    #[Bench\BeforeMethods('setUpBasePath')]
    public function benchApplicationBoot(): void
    {
        Foundation::create($this->applicationConfig())->boot();
    }

    #[Bench\BeforeMethods('setUpBasePath')]
    public function benchApplicationCreation(): void
    {
        Foundation::create($this->applicationConfig());
    }

    #[Bench\BeforeMethods('setUpConfig')]
    public function benchNestedConfigLookup(): void
    {
        $this->config->getString('services.mail.primary.host');
    }

    #[Bench\BeforeMethods('setUpIds')]
    public function benchRandomSessionIdGeneration(): void
    {
        $this->ids->sessionId();
    }

    public function setUpBasePath(): void
    {
        $this->basePath = sys_get_temp_dir() . '/foundation-phpbench';

        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0775, true);
        }
    }

    public function setUpConfig(): void
    {
        $this->config = new ConfigRepository([
            'services' => [
                'mail' => [
                    'primary' => [
                        'host' => 'mail.example.test',
                    ],
                ],
            ],
        ]);
    }

    public function setUpIds(): void
    {
        $this->ids = new RandomAuthIdGenerator();
    }

    private function applicationConfig(): array
    {
        return [
            'app' => [
                'base_path' => $this->basePath,
            ],
            'paths' => [
                'cache' => 'cache',
            ],
        ];
    }
}
