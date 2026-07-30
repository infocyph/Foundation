<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http;

use Infocyph\Foundation\Routing\RouterManager;
use Infocyph\Foundation\Runtime\RuntimeContextResetter;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;

final readonly class HttpKernel
{
    public function __construct(
        private RouterManager $router,
        private ErrorHandler $errorHandler,
        private RuntimeContextResetter $contexts,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request, $this->errorHandler);
        } finally {
            $this->contexts->reset();
        }
    }
}
