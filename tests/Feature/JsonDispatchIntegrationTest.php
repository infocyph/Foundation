<?php

declare(strict_types=1);

use Infocyph\DBLayer\Pagination\CursorPaginator;
use Infocyph\Foundation\Http\JsonDispatch\Issue;
use Infocyph\Foundation\Http\JsonDispatch\JsonDispatchResponseFactory;
use Infocyph\Foundation\Http\Resource\JsonResource;
use Infocyph\Foundation\Http\Resource\ResourceCollection;
use Infocyph\Foundation\Testing\TestResponse;
use Infocyph\ReqShield\Support\ValidationResult;

it('emits JsonDispatch 3 success envelopes and resource transformations', function (): void {
    $factory = new JsonDispatchResponseFactory('example', '7.4.0');
    $resource = new class(['id' => 7, 'secret' => 'hidden']) extends JsonResource {
        public function resolve(): mixed
        {
            return ['id' => $this->resource['id']];
        }
    };

    $response = new TestResponse($factory->success(
        new ResourceCollection([$resource], static fn(JsonResource $item): JsonResource => $item),
        message: 'Ready',
    ));

    $response
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/vnd.example.jd.v3+json; charset=utf-8')
        ->assertHeader('X-Api-Version-Selected', '7.4.0')
        ->assertHeader('Vary', 'Accept, X-Api-Version')
        ->assertJson([
            'status' => 'success',
            'message' => 'Ready',
            'data' => [['id' => 7]],
        ]);
    expect($response->status())->toBe(200);
});

it('keeps native semantic statuses and tunnels them only when explicitly configured', function (): void {
    $issue = new Issue('RATE_LIMITED', 'Too many requests', source: ['header' => 'Authorization']);
    $native = new TestResponse((new JsonDispatchResponseFactory())->fail([$issue], 429));
    $restricted = new TestResponse((new JsonDispatchResponseFactory(
        tunnelErrors: true,
    ))->error([
        new Issue('UPSTREAM_FAILED', 'Dependency unavailable'),
    ], 503));

    $native
        ->assertStatus(429)
        ->assertJsonPath('status', 'fail')
        ->assertJsonPath('status_code', 429);
    expect($native->response()->getHeaderLine('X-JD-Status-Code'))->toBe('');

    $restricted
        ->assertStatus(200)
        ->assertHeader('X-JD-Status-Code', '503')
        ->assertHeader('Cache-Control', 'no-store')
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('status_code', 503);
});

it('maps ReqShield field failures without renormalizing validation output', function (): void {
    $response = new TestResponse((new JsonDispatchResponseFactory())->failureFromValidation(
        new ValidationResult([
            'profile.email' => ['The email is invalid.'],
            'items[0].sku' => ['The SKU is required.'],
        ]),
    ));

    $data = $response->assertStatus(422)->json()['data'];

    expect($data)->toHaveCount(2)
        ->and($data[0]['code'])->toBe('VALIDATION_FAILED')
        ->and($data[0]['source']['pointer'])->toBe('/profile/email')
        ->and($data[1]['source']['pointer'])->toBe('/items/0/sku');
});

it('preserves DBLayer cursor tokens and replaces existing cursor query values', function (): void {
    $cursor = 'opaque+/= token';
    $paginator = new CursorPaginator(
        items: [['id' => 2], ['id' => 3]],
        perPage: 2,
        cursor: 'current-token',
        nextCursor: $cursor,
        hasMore: true,
        previousCursor: 'previous-token',
        hasPrevious: true,
    );
    $response = new TestResponse((new JsonDispatchResponseFactory())->paginated(
        $paginator,
        '/users?filter=active&cursor=stale#results',
    ));
    $json = $response->json();

    expect($json['_properties']['/data']['pagination'])->toBe([
        'mode' => 'cursor',
        'limit' => 2,
        'count' => 2,
        'has_more' => true,
        'next_cursor' => $cursor,
        'previous_cursor' => 'previous-token',
    ])->and($json['_links']['next'])
        ->toBe('/users?filter=active&cursor=opaque%2B%2F%3D%20token#results')
        ->and(substr_count($json['_links']['next'], 'cursor='))
        ->toBe(1)
        ->and($json['_links']['previous'])
        ->toBe('/users?filter=active&cursor=previous-token#results');
});

it('rejects invalid issue and status combinations before rendering', function (): void {
    $factory = new JsonDispatchResponseFactory();

    expect(fn() => $factory->success([], 204))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => $factory->fail([], 422))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => $factory->error([new Issue('BROKEN', 'Broken')], 422))
        ->toThrow(InvalidArgumentException::class);
});
