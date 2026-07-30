# Testing

`Application::testing()` returns Foundation's test composition gateway.

## HTTP

```php
$response = $app->testing()->http()
    ->withHeaders(['Authorization' => 'Bearer test'])
    ->post('/accounts', ['email' => 'user@example.test']);

$response
    ->assertStatus(201)
    ->assertHeader('Content-Type')
    ->assertJson(['status' => 'success'])
    ->assertJsonPath('data.email', 'user@example.test');
```

The client creates native Webrick requests and executes the real Foundation
kernel, router, middleware, response, and cleanup boundaries.

## Fakes and helpers

```php
$auth = $app->testing()->auth();
$cache = $app->testing()->fakeCache();
$files = $app->testing()->files();
$sessions = $app->testing()->sessions();

$messages = $app->testing()->fakeMessaging();
$notifications = $app->testing()->fakeNotifications();
$http = $app->testing()->fakeHttp();
$clock = $app->testing()->freezeTime(1_800_000_000);
```

These compose the owning package's contracts and fakes. Foundation does not
maintain parallel queue, email, HTTP transport, cache, or filesystem
implementations. Configure Pathwise disks under a temporary application base
for filesystem isolation.

Database helpers provide automatic rollback and migration refresh. Persistent
worker tests should send several successful and failing requests/messages
through one application and assert that principals, sessions, transactions,
and scoped instances do not leak.
