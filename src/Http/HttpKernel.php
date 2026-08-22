<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http;

use Infocyph\Foundation\Runtime\ExecutionScope;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\RouterKernel;

final readonly class HttpKernel
{
    public function __construct(
        private RouterKernel $router,
        private ExecutionScope $execution,
    ) {}

    public function handle(Request $request): Response
    {
        return $this->execution->run(
            fn(): Response => $this->router->handle($request),
            [Request::class => $request],
        );
    }
}
