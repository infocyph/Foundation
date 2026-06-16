<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Gate;

final readonly class Ability
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $name,
        public ?string $resourceType = null,
        public ?string $resourceId = null,
        public array $context = [],
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public static function from(string $name, mixed $resource = null, array $context = []): self
    {
        $resourceType = null;
        $resourceId = null;

        if (is_object($resource)) {
            $resourceType = $resource::class;
            if (isset($resource->id) && is_string($resource->id)) {
                $resourceId = $resource->id;
            }
        } elseif (is_array($resource)) {
            $resourceType = isset($resource['type']) && is_string($resource['type']) ? $resource['type'] : null;
            $resourceId = isset($resource['id']) && is_string($resource['id']) ? $resource['id'] : null;
        } elseif (is_string($resource)) {
            $resourceId = $resource;
        }

        return new self($name, $resourceType, $resourceId, $context);
    }
}
