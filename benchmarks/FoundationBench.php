<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Support\RandomAuthIdGenerator;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Foundation;
use Infocyph\Webrick\Request\Request;
use PhpBench\Attributes as Bench;

#[Bench\Revs(10)]
#[Bench\Iterations(3)]
#[Bench\Warmup(1)]
final class FoundationBench
{
    private string $basePath;

    private ConfigRepository $config;

    private Application $httpApplication;

    private Request $httpRequest;

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

    #[Bench\BeforeMethods('setUpHttpApplication')]
    public function benchWarmHttpRequest(): void
    {
        $this->httpApplication->handle($this->httpRequest);
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

    public function setUpHttpApplication(): void
    {
        $this->setUpBasePath();
        $routesDirectory = $this->basePath . '/routes';

        if (!is_dir($routesDirectory)) {
            mkdir($routesDirectory, 0775, true);
        }

        file_put_contents($routesDirectory . '/web.php', <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;

Router::get('/benchmark', static fn(): Response => Response::json(['ok' => true]));
PHP);

        $this->httpApplication = Foundation::create([
            'app' => [
                'base_path' => $this->basePath,
            ],
            'router' => [
                'cache' => false,
                'files' => ['web.php'],
                'middleware' => [
                    'globals' => [
                        'pre' => [],
                        'post' => [],
                    ],
                ],
            ],
        ])->boot();
        $this->httpRequest = Request::fake(
            headers: ['Host' => 'example.test'],
            uri: 'https://example.test/benchmark',
        );
        $this->httpApplication->handle($this->httpRequest);
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
