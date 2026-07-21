<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http;

use Infocyph\Foundation\Routing\RouterManager;
use Infocyph\InterMix\DI\Container;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;

final class HttpKernel
{
    private int $requestScopeSequence = 0;

    public function __construct(
        private readonly RouterManager $router,
        private readonly ErrorHandler $errorHandler,
        private readonly Container $container,
        private readonly bool $scopeRequests = true,
    ) {}

    public function handle(Request $request): Response
    {
        if (!$this->scopeRequests) {
            return $this->router->dispatch($request, $this->errorHandler);
        }

        $response = $this->container->withinScope(
            'foundation.http.' . (++$this->requestScopeSequence),
            fn(): Response => $this->router->dispatch($request, $this->errorHandler),
        );

        if (!$response instanceof Response) {
            throw new \RuntimeException('Scoped HTTP handler must return a Webrick response instance.');
        }

        return $response;
    }
}
