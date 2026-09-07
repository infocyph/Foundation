<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Operations\MaintenanceManager;
use Infocyph\Webrick\Request\Request;

it('keeps maintenance state out of the mutable development router path', function (): void {
    $project = webMaintenanceProject();
    $marker = $project . '/route-hit.txt';

    try {
        $app = Foundation::web([
            'base_path' => $project,
            'operations' => [
                'maintenance' => [
                    'driver' => 'file',
                    'path' => 'storage/framework/maintenance.json',
                ],
            ],
        ]);
        $maintenance = $app->make(MaintenanceManager::class);
        $state = $maintenance->enable(37, 'Planned maintenance');

        expect($state['enabled'])->toBeTrue()
            ->and($state['retry_after'])->toBe(37)
            ->and($state['message'])->toBe('Planned maintenance')
            ->and($state['driver'])->toBe('file');

        $activeDuringMaintenance = $app->handle(webMaintenanceRequest());
        $activePayload = json_decode((string) $activeDuringMaintenance->getBody(), true, flags: JSON_THROW_ON_ERROR);

        expect($activeDuringMaintenance->getStatusCode())->toBe(200)
            ->and($activePayload)->toBe(['ok' => true])
            ->and(file_get_contents($marker))->toBe('hit');

        expect($maintenance->disable())->toBeTrue()
            ->and($maintenance->status()['enabled'])->toBeFalse();

        unlink($marker);
        $activeAfterMaintenance = $app->handle(webMaintenanceRequest());
        $afterPayload = json_decode((string) $activeAfterMaintenance->getBody(), true, flags: JSON_THROW_ON_ERROR);

        expect($activeAfterMaintenance->getStatusCode())->toBe(200)
            ->and($afterPayload)->toBe(['ok' => true])
            ->and(file_get_contents($marker))->toBe('hit');
    } finally {
        webMaintenanceRemoveDirectory($project);
    }
});

function webMaintenanceProject(): string
{
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'foundation-web-maintenance-'
        . bin2hex(random_bytes(5));
    $routes = $project . '/routes';
    mkdir($routes, 0775, true);
    file_put_contents($routes . '/web.php', <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;

Router::get('/maintenance-probe', static function (Application $application): Response {
    file_put_contents($application->basePath('route-hit.txt'), 'hit', LOCK_EX);

    return Response::json(['ok' => true]);
});
PHP);

    return $project;
}

function webMaintenanceRequest(): Request
{
    return Request::fake(
        headers: [
            'Accept' => 'application/json',
            'Host' => 'example.test',
        ],
        uri: 'https://example.test/maintenance-probe',
    );
}

function webMaintenanceRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $entries = scandir($directory);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_link($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            webMaintenanceRemoveDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}
