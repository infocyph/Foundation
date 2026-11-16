<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\Route;

/**
 * Runtime entry point discovered through native provider, route or benchmark metadata.
 *
 * @api
 */
final readonly class AttributeGreetingController
{
    #[Route('GET', '/attribute-hello/{name}', name: 'attribute.hello')]
    public function show(string $name): Response
    {
        return Response::json(['hello' => $name]);
    }
}
