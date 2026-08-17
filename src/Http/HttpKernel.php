<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http;

use Infocyph\Foundation\Routing\RouterManager;
use Infocyph\Foundation\Runtime\ExecutionScope;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;

final readonly class HttpKernel
{
    public function __construct(
        private RouterManager $router,
        private ErrorHandler $errorHandler,
        private ExecutionScope $execution,
    ) {}

    public function handle(Request $request): Response
    {
        return $this->execution->run(
            fn(): Response => $this->router->dispatch($request, $this->errorHandler),
            [Request::class => $request],
        );
    }
}
