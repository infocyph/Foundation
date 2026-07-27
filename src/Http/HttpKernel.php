<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http;

use Infocyph\Foundation\Routing\RouterManager;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;

final readonly class HttpKernel
{
    public function __construct(
        private RouterManager $router,
        private ErrorHandler $errorHandler,
    ) {}

    public function handle(Request $request): Response
    {
        return $this->router->dispatch($request, $this->errorHandler);
    }
}
