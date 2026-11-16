<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\JsonDispatch;

use Infocyph\DBLayer\Pagination\CursorPaginator;
use Infocyph\DBLayer\Pagination\PaginatorInterface;
use Infocyph\Foundation\Http\Resource\JsonResource;
use Infocyph\ReqShield\Support\ValidationResult;
use Infocyph\Webrick\Response\Response;
use JsonSerializable;

/**
 * Public JSON dispatch response assembly contract.
 *
 * @api
 */
final readonly class JsonDispatchResponseFactory
{
    public const string SPECIFICATION_VERSION = '3.0.0';

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private string $vendor = 'infocyph',
        private string $applicationVersion = '1.0.0',
        private bool $tunnelErrors = false,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9.-]*$/', $vendor) !== 1) {
            throw new \InvalidArgumentException('JsonDispatch vendor must be a media-type-safe token.');
        }
        if ($applicationVersion === '') {
            throw new \InvalidArgumentException('JsonDispatch application version must not be empty.');
        }
    }

    /**
     * @param list<Issue> $issues
     * @throws \InvalidArgumentException
     */
    public function error(
        array $issues,
        int $status = 500,
        ?string $message = null,
    ): Response {
        return $this->problem('error', $issues, $status, $message);
    }

    /**
     * @param list<Issue> $issues
     * @throws \InvalidArgumentException
     */
    public function fail(
        array $issues,
        int $status = 422,
        ?string $message = null,
    ): Response {
        return $this->problem('fail', $issues, $status, $message);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function failureFromValidation(
        ValidationResult $validation,
        int $status = 422,
        string $message = 'The request contains invalid values.',
    ): Response {
        $issues = [];
        foreach ($validation->errors() as $field => $messages) {
            foreach ($messages as $detail) {
                $issues[] = new Issue(
                    code: 'VALIDATION_FAILED',
                    title: 'Invalid request value',
                    detail: $detail,
                    source: ['pointer' => $this->pointer($field)],
                );
            }
        }

        if ($issues === []) {
            throw new \InvalidArgumentException('A validation failure response requires at least one error.');
        }

        return $this->fail($issues, $status, $message);
    }

    /**
     * @param callable(mixed): mixed|null $transform
     */
    public function paginated(
        PaginatorInterface $paginator,
        string $self,
        ?callable $transform = null,
        int $status = 200,
        ?string $message = null,
    ): Response {
        $items = $transform === null
            ? $paginator->items()
            : array_map($transform, $paginator->items());
        $pagination = $this->pagination($paginator);
        $links = ['self' => $self];

        if ($paginator instanceof CursorPaginator) {
            if ($paginator->nextCursor() !== null) {
                $links['next'] = $this->withQuery($self, 'cursor', $paginator->nextCursor());
            }
            if ($paginator->previousCursor() !== null) {
                $links['previous'] = $this->withQuery($self, 'cursor', $paginator->previousCursor());
            }
        }

        return $this->respond([
            'status' => 'success',
            'message' => $message,
            'data' => $this->normalize($items),
            '_properties' => [
                '/data' => [
                    'type' => 'array',
                    'pagination' => $pagination,
                ],
            ],
            '_links' => $links,
        ], $status);
    }

    /**
     * Resources and JSON-serializable objects are resolved before rendering.
     *
     * @param array<array-key, mixed>|string|int|float|bool|object|null $data
     * @throws \InvalidArgumentException
     */
    public function success(
        array|string|int|float|bool|object|null $data = null,
        int $status = 200,
        ?string $message = null,
    ): Response {
        if ($status < 200 || $status > 299 || in_array($status, [204, 205], true)) {
            throw new \InvalidArgumentException('JsonDispatch success envelopes require a body-bearing 2xx status.');
        }

        return $this->respond([
            'status' => 'success',
            'message' => $message,
            'data' => $this->normalize($data),
        ], $status);
    }

    private function mediaType(): string
    {
        return sprintf('application/vnd.%s.jd.v3+json; charset=utf-8', $this->vendor);
    }

    /** @param array<array-key, mixed>|string|int|float|bool|object|null $value */
    private function normalize(array|string|int|float|bool|object|null $value): mixed
    {
        if ($value instanceof JsonResource) {
            return $value->resolve();
        }
        if ($value instanceof JsonSerializable) {
            return $value->jsonSerialize();
        }

        return $value;
    }

    /**
     * @return array{mode:string,limit:int,count:int,has_more?:bool,next_cursor?:string,previous_cursor?:string,offset?:int,total?:int}
     */
    private function pagination(PaginatorInterface $paginator): array
    {
        if ($paginator instanceof CursorPaginator) {
            $pagination = [
                'mode' => 'cursor',
                'limit' => $paginator->perPage(),
                'count' => $paginator->count(),
                'has_more' => $paginator->hasMorePages(),
            ];
            $nextCursor = $paginator->nextCursor();
            if ($nextCursor !== null) {
                $pagination['next_cursor'] = $nextCursor;
            }
            $previousCursor = $paginator->previousCursor();
            if ($previousCursor !== null) {
                $pagination['previous_cursor'] = $previousCursor;
            }

            return $pagination;
        }

        $pagination = [
            'mode' => 'offset',
            'offset' => max(0, ($paginator->currentPage() - 1) * $paginator->perPage()),
            'limit' => $paginator->perPage(),
            'count' => $paginator->count(),
        ];
        $total = $paginator->total();
        if ($total !== null) {
            $pagination['total'] = $total;
        }

        return $pagination;
    }

    private function pointer(string $field): string
    {
        $segments = preg_split('/[.\\[\\]]+/', $field, -1, PREG_SPLIT_NO_EMPTY);
        if ($segments === false) {
            $segments = [$field];
        }

        return '/' . implode('/', array_map(
            static fn(string $segment): string => str_replace(['~', '/'], ['~0', '~1'], $segment),
            $segments,
        ));
    }

    /**
     * @param list<Issue> $issues
     * @throws \InvalidArgumentException
     */
    private function problem(string $type, array $issues, int $status, ?string $message): Response
    {
        $expected = $type === 'fail' ? [400, 499] : [500, 599];
        if ($status < $expected[0] || $status > $expected[1] || $issues === []) {
            throw new \InvalidArgumentException(sprintf(
                'JsonDispatch %s envelopes require a matching status and at least one issue.',
                $type,
            ));
        }

        return $this->respond([
            'status' => $type,
            'status_code' => $status,
            'message' => $message,
            'data' => array_map(static fn(Issue $issue): array => $issue->jsonSerialize(), $issues),
        ], $status, true);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function respond(array $envelope, int $semanticStatus, bool $problem = false): Response
    {
        $envelope = array_filter(
            $envelope,
            static fn(mixed $value, string $key): bool => $key === 'data' || $value !== null,
            ARRAY_FILTER_USE_BOTH,
        );
        $headers = [
            'Content-Type' => $this->mediaType(),
            'X-Api-Version-Selected' => $this->applicationVersion,
            'Vary' => 'Accept, X-Api-Version',
        ];
        $httpStatus = $semanticStatus;

        if ($problem && $this->tunnelErrors) {
            $httpStatus = 200;
            $headers['X-JD-Status-Code'] = (string) $semanticStatus;
            $headers['Cache-Control'] = 'no-store';
        }

        return Response::json($envelope, $httpStatus, $headers);
    }

    private function withQuery(string $uri, string $key, string $value): string
    {
        $fragment = '';
        $fragmentOffset = strpos($uri, '#');
        if ($fragmentOffset !== false) {
            $fragment = substr($uri, $fragmentOffset);
            $uri = substr($uri, 0, $fragmentOffset);
        }

        $parts = explode('?', $uri, 2);
        $query = [];
        if (isset($parts[1])) {
            parse_str($parts[1], $query);
        }
        $query[$key] = $value;

        return $parts[0] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) . $fragment;
    }
}
