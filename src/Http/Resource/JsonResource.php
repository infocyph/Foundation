<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Resource;

use JsonSerializable;

abstract class JsonResource implements JsonSerializable
{
    public function __construct(protected readonly mixed $resource) {}

    abstract public function resolve(): mixed;

    final public function jsonSerialize(): mixed
    {
        return $this->resolve();
    }
}
