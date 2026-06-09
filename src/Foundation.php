<?php

declare(strict_types=1);

namespace Infocyph\Foundation;

use Infocyph\Foundation\Application\Application;

final class Foundation
{
    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config = []): Application
    {
        return Application::create($config);
    }
}
