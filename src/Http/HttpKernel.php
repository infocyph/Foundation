<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http;

use Infocyph\Foundation\Routing\RouterManager;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;

final readonly class HttpKernel
{
    public function __construct(
        private RouterManager $router,
        private ErrorHandler $errorHandler,
        private Container $container,
        private bool $scopeRequests = true,
    ) {}

    public function handle(Request $request): Response
    {
        if (!$this->scopeRequests) {
            return $this->router->dispatch($request, $this->errorHandler);
        }

        $this->container->bind(Request::class, fn() => $request, LifetimeEnum::Scoped);

        $response = $this->container->withinScope(
            $this->scopeId($request),
            fn(): Response => $this->router->dispatch($request, $this->errorHandler),
        );

        if (!$response instanceof Response) {
            throw new \RuntimeException('Scoped HTTP handler must return a Webrick response instance.');
        }

        return $response;
    }

    private function scopeId(Request $request): string
    {
        $path = $request->getUri()->getPath();
        $normalized = $path === '' ? '/' : $path;

        return sprintf(
            'http:%s:%s:%d',
            strtolower($request->getMethod()),
            $normalized,
            spl_object_id($request),
        );
    }
}
