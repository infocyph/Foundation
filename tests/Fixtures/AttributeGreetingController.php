<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\Route;

final readonly class AttributeGreetingController
{
    #[Route('GET', '/attribute-hello/{name}', name: 'attribute.hello')]
    public function show(string $name): Response
    {
        return Response::json(['hello' => $name]);
    }
}
