# JSON resources and JsonDispatch

Foundation's built-in JsonDispatch response boundary currently advertises
specification version `3.0.0` through
`JsonDispatchResponseFactory::SPECIFICATION_VERSION`.

## Resources

Extend `JsonResource` for one application representation:

```php
use Infocyph\Foundation\Http\Resource\JsonResource;

final class AccountResource extends JsonResource
{
    public function resolve(): array
    {
        return [
            'id' => $this->resource->id(),
            'email' => $this->resource->email(),
        ];
    }
}
```

Generate the same contract with:

```bash
php infbyte create:resource Account
```

`ResourceCollection` transforms iterables without requiring an Active Record
model layer.

## Responses

`Application` does not expose a response facade. Resolve the concrete Foundation
factory through DI:

```php
use Infocyph\Foundation\Http\JsonDispatch\Issue;
use Infocyph\Foundation\Http\JsonDispatch\JsonDispatchResponseFactory;

$responses = $app->make(JsonDispatchResponseFactory::class);

return $responses->success(new AccountResource($account));

return $responses->fail([
    new Issue('ACCOUNT_INVALID', 'Invalid account'),
], 422);
```

Responses use the configured JsonDispatch vendor/application-version policy and
Foundation's current v3 media-type boundary.

Native mode preserves semantic `4xx`/`5xx` statuses. When the explicitly
configured error-tunneling policy is enabled, error/failure responses may retain
semantic status inside the envelope while using the transport status configured
by the JsonDispatch boundary. Successful responses are not treated as errors.

## Validation and pagination

`failureFromValidation()` maps ReqShield validation issues into JsonDispatch
issues; it does not rerun validation.

`paginated()` maps DBLayer paginator metadata. Cursor strings remain opaque;
Foundation only places them into navigation links.

See `resources/config/responses.php` for publishable response policy.
