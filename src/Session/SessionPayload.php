<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

final class SessionPayload
{
    private ?string $encoded = null;

    /**
     * @param array<string, mixed> $data
     * @param list<string> $flashKeys
     */
    public function __construct(
        public readonly array $data,
        public readonly array $flashKeys,
        public readonly int $expiresAt,
    ) {}

    /**
     * @param array<mixed> $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $data = $payload['data'] ?? null;
        $flashKeys = $payload['flash'] ?? null;
        $expiresAt = $payload['expires_at'] ?? null;

        if (!is_array($data) || !is_array($flashKeys) || !is_int($expiresAt)) {
            return null;
        }

        $normalizedFlash = [];
        foreach ($flashKeys as $key) {
            if (is_string($key) && $key !== '') {
                $normalizedFlash[] = $key;
            }
        }

        $normalizedData = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $normalizedData[$key] = $value;
            }
        }

        return new self($normalizedData, array_values(array_unique($normalizedFlash)), $expiresAt);
    }

    public static function fromJson(string $payload): ?self
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? self::fromArray($decoded) : null;
    }

    /**
     * @return array{data:array<string, mixed>,flash:list<string>,expires_at:int}
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'flash' => $this->flashKeys,
            'expires_at' => $this->expiresAt,
        ];
    }

    public function toJson(): string
    {
        return $this->encoded ??= json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }
}
