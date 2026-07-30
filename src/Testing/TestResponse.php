<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Testing;

use Infocyph\Webrick\Response\Response;

final readonly class TestResponse
{
    public function __construct(private Response $response) {}

    public function assertHeader(string $name, ?string $value = null): self
    {
        $actual = $this->response->getHeaderLine($name);
        if ($actual === '' || ($value !== null && $actual !== $value)) {
            throw new \RuntimeException(sprintf(
                'Expected response header "%s"%s; received "%s".',
                $name,
                $value === null ? '' : sprintf(' to equal "%s"', $value),
                $actual,
            ));
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $expected
     */
    public function assertJson(array $expected): self
    {
        $actual = $this->json();
        if (!$this->contains($actual, $expected)) {
            throw new \RuntimeException(sprintf(
                "Response JSON does not contain the expected fragment.\nExpected: %s\nActual: %s",
                json_encode($expected, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            ));
        }

        return $this;
    }

    public function assertJsonPath(string $path, mixed $expected): self
    {
        $value = $this->json();
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                throw new \RuntimeException(sprintf('Response JSON path "%s" does not exist.', $path));
            }
            $value = $value[$segment];
        }
        if ($value !== $expected) {
            throw new \RuntimeException(sprintf(
                'Response JSON path "%s" did not contain the expected value.',
                $path,
            ));
        }

        return $this;
    }

    public function assertStatus(int $status): self
    {
        if ($this->response->getStatusCode() !== $status) {
            throw new \RuntimeException(sprintf(
                'Expected HTTP status %d; received %d.',
                $status,
                $this->response->getStatusCode(),
            ));
        }

        return $this;
    }

    public function body(): string
    {
        return (string) $this->response->getBody();
    }

    /**
     * @return array<array-key, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body(), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('Response body is not a JSON object or array.');
        }

        return $decoded;
    }

    public function response(): Response
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    private function contains(mixed $actual, mixed $expected): bool
    {
        if (!is_array($expected)) {
            return $actual === $expected;
        }
        if (!is_array($actual)) {
            return false;
        }

        return array_all(
            $expected,
            fn(mixed $value, int|string $key): bool => array_key_exists($key, $actual)
                && $this->contains($actual[$key], $value),
        );
    }
}
