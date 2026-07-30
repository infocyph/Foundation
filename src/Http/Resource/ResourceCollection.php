<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Resource;

use Closure;
use JsonSerializable;

final readonly class ResourceCollection implements JsonSerializable
{
    /** @var Closure(mixed): mixed */
    private Closure $transform;

    /**
     * @param iterable<mixed> $items
     * @param callable(mixed): mixed $transform
     */
    public function __construct(
        private iterable $items,
        callable $transform,
    ) {
        $this->transform = Closure::fromCallable($transform);
    }

    /**
     * @return list<mixed>
     */
    public function jsonSerialize(): array
    {
        $resolved = [];
        foreach ($this->items as $item) {
            $value = ($this->transform)($item);
            $resolved[] = $value instanceof JsonSerializable ? $value->jsonSerialize() : $value;
        }

        return $resolved;
    }
}
