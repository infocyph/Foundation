<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\RouterKernel;

final readonly class HttpKernel
{
    public function __construct(private RouterKernel $router) {}

    public function handle(Request $request): Response
    {
        // Webrick owns the stable webrick.request scope around routing/dispatch.
        return $this->router->handle($request);
    }
}
