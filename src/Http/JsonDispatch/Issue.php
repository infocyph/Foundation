<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\JsonDispatch;

use JsonSerializable;

final readonly class Issue implements JsonSerializable
{
    /**
     * @param array{pointer?:string,parameter?:string,header?:string,resource?:string} $source
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $code,
        public string $title,
        public ?string $detail = null,
        public array $source = [],
        public array $meta = [],
    ) {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $code) !== 1) {
            throw new \InvalidArgumentException('JsonDispatch issue codes must use upper snake case.');
        }
        if ($title === '') {
            throw new \InvalidArgumentException('JsonDispatch issue titles must not be empty.');
        }
        if (count($source) > 1) {
            throw new \InvalidArgumentException('JsonDispatch issue sources identify exactly one location.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'code' => $this->code,
            'title' => $this->title,
            'detail' => $this->detail,
            'source' => $this->source,
            'meta' => $this->meta,
        ], static fn(mixed $value): bool => $value !== null && $value !== []);
    }
}
