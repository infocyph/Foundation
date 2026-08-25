<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http;

use Infocyph\Foundation\Operations\MaintenanceManager;
use Infocyph\Foundation\Runtime\ExecutionScope;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\RouterKernel;

final readonly class HttpKernel
{
    public function __construct(
        private RouterKernel $router,
        private ExecutionScope $execution,
        private MaintenanceManager $maintenance,
    ) {}

    public function handle(Request $request): Response
    {
        return $this->execution->run(function () use ($request): Response {
            $maintenance = $this->maintenance->status();
            if ($maintenance['enabled']) {
                $headers = [];
                if ($maintenance['retry_after'] !== null) {
                    $headers['Retry-After'] = (string) $maintenance['retry_after'];
                }

                return Response::auto(
                    $request,
                    [
                        'message' => $maintenance['message'] ?? 'Service temporarily unavailable for maintenance.',
                        'maintenance' => true,
                    ],
                    503,
                    $headers,
                );
            }

            return $this->router->handle($request);
        }, [Request::class => $request]);
    }
}
