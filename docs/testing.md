# Testing

Foundation keeps `Application` narrow. Resolve `TestKit` through DI when tests
want Foundation's application-level testing helpers:

```php
use Infocyph\Foundation\Testing\TestKit;

$testing = $app->make(TestKit::class);
```

## HTTP

```php
$response = $testing->http()
    ->withHeaders(['Authorization' => 'Bearer test'])
    ->post('/accounts', ['email' => 'user@example.test']);

$response
    ->assertStatus(201)
    ->assertHeader('Content-Type')
    ->assertJson(['status' => 'success'])
    ->assertJsonPath('data.email', 'user@example.test');
```

The client creates native Webrick requests and executes the Foundation Web
kernel/router/middleware/response/execution-cleanup path.

## Fakes and helpers

```php
$auth = $testing->auth();
$cache = $testing->fakeCache();
$files = $testing->files();
$sessions = $testing->sessions();

$messages = $testing->fakeMessaging();
$notifications = $testing->fakeNotifications();
$http = $testing->fakeHttp();
$clock = $testing->freezeTime(1_800_000_000);
```

These compose the owning package contracts/fakes rather than maintaining
parallel queue, email, HTTP transport, cache, or filesystem engines inside
Foundation.

`fakeMessaging()` replaces the active Omnibus transport registry/message bus
with a recording sender for configured transport names. `fakeHttp()` uses
TalkingBytes' native fake HTTP client. `fakeNotifications()` supplies a native
TalkingBytes fake `Emailer`.

Optional helper methods require their corresponding optional capability to be
installed/configured.

## Database tests

When DBLayer is available:

```php
$db = $testing->database();

$db->transaction(function () use ($app): void {
    // Resolve/use the configured DBLayer connection here.
});
```

The database test helper delegates migration/connection behavior to DBLayer and
provides Foundation application-level rollback/refresh orchestration.

## Persistent-runtime tests

Persistent-runtime verification should run multiple successful/failing units
through one application and assert that principals, sessions, transactions,
execution-scoped services, and tracked external state do not leak across the
execution boundary.

The complete cross-runtime/backend/fork/performance test matrix belongs to the
dedicated release-verification phase; this guide describes the available test
composition APIs and intended assertions, not a claim that the release matrix
has already run.

## Maintainer dependency evidence

The original Foundation 3 source is `fc9b3258646ac744e61fd02e18d9b98e5afff2e8`. Its Composer manifest is authentic; no original lock was tracked or recovered. Historical selected-package resolution is a reconstruction, not the original resolved graph. Keep reconstructed and candidate locks as separate CI artifacts. This repository intentionally ignores its lockfile; application consumers should commit their own application locks.

The November 11 toolchain audit recorded no advisories but failed for abandoned development doctrine/annotations 2.0.2, required by PHPBench 1.7.0. Pest 5.3.5 limited PHPUnit to 13.4.5; Psalm 7.3.0 and danog/advanced-json-rpc 3.2.3 required JsonMapper 5. These are dated upstream constraints, not runtime fixes or current audit certification. Use `composer audit --locked --format=json` and Composer resolution diagnostics for the actual selected graph; do not fake replacements or weaken checks.
