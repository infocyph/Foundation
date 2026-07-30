# JSON resources and JsonDispatch

Foundation pins its envelope boundary to JsonDispatch `3.0.0`.

## Resources

Extend `JsonResource` for one representation:

```php
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

`ResourceCollection` transforms an iterable without requiring a model or
Active Record layer.

## Responses

```php
return $app->responses()->success(new AccountResource($account));

return $app->responses()->fail([
    new Issue('ACCOUNT_INVALID', 'Invalid account'),
], 422);
```

Responses use `application/vnd.<vendor>.jd.v3+json`,
`X-Api-Version-Selected`, and `Vary: Accept, X-Api-Version`.

Native mode preserves semantic `4xx` and `5xx` statuses. The explicitly enabled
restricted-transport profile returns HTTP 200 while preserving `status_code`
and adding `X-JD-Status-Code` plus `Cache-Control: no-store`. Success responses
are never tunneled.

## Validation and pagination

`failureFromValidation()` maps ReqShield error paths directly to JSON Pointer
sources. It does not revalidate or renormalize the validation result.

`paginated()` maps DBLayer offset and cursor paginator metadata. Cursor strings
remain opaque; Foundation only URL-encodes them when constructing navigation
links and replaces an existing cursor query parameter rather than duplicating
it.

See `resources/config/responses.php` for all response keys and valid values.
