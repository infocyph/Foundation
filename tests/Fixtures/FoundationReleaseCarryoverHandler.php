<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use RuntimeException;

final class FoundationReleaseCarryoverHandler
{
    public function __invoke(FoundationReleaseCarryoverMessage $message): void
    {
        if ($message->value === 'dead-letter') {
            throw new RuntimeException('Expected durable carryover failure.');
        }
    }
}
