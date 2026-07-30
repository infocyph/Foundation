<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Testing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Webrick\Request\Request;

final class HttpTestClient
{
    /** @var array<string,string|list<string>> */
    private array $headers = [
        'Accept' => 'application/json',
        'Host' => 'localhost',
    ];

    public function __construct(private readonly Application $application)
    {
        if (!$application->runningInWeb()) {
            throw new \LogicException('Foundation HTTP tests require a web application.');
        }
    }

    /**
     * @param array<string,mixed> $query
     */
    public function delete(string $uri, array $query = []): TestResponse
    {
        return $this->request('DELETE', $uri, $query);
    }

    /**
     * @param array<string,mixed> $query
     */
    public function get(string $uri, array $query = []): TestResponse
    {
        return $this->request('GET', $uri, $query);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function patch(string $uri, array $data = []): TestResponse
    {
        return $this->request('PATCH', $uri, post: $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function post(string $uri, array $data = []): TestResponse
    {
        return $this->request('POST', $uri, post: $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function put(string $uri, array $data = []): TestResponse
    {
        return $this->request('PUT', $uri, post: $data);
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,string|list<string>> $headers
     */
    public function request(
        string $method,
        string $uri,
        array $query = [],
        array $post = [],
        array $headers = [],
    ): TestResponse {
        $request = Request::fake(
            query: $query,
            post: $post,
            headers: $headers + $this->headers,
            method: $method,
            uri: $this->absoluteUri($uri),
        );

        return new TestResponse($this->application->handle($request));
    }

    /**
     * @param array<string,string|list<string>> $headers
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->headers = $headers + $this->headers;

        return $clone;
    }

    private function absoluteUri(string $uri): string
    {
        if (preg_match('#^https?://#i', $uri) === 1) {
            return $uri;
        }

        return 'http://localhost/' . ltrim($uri, '/');
    }
}
