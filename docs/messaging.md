# Events, queues, and scheduled messages

Foundation composes Omnibus through `config/messaging.php`. Console already
provides the Omnibus command surface; registering no handlers, listeners,
routes, or scheduled messages leaves the runtime graph dormant.

## Explicit maps

```php
return [
    'handlers' => [
        App\Message\GenerateReport::class => App\Handler\GenerateReport::class,
    ],
    'routes' => [
        App\Message\GenerateReport::class => [
            'transport' => 'memory',
            'queue' => 'reports',
            'delay_seconds' => 0.0,
        ],
    ],
    'listeners' => [
        App\Event\OrderPaid::class => [
            App\Listener\SendReceipt::class,
        ],
    ],
    'scheduled_messages' => [
        'reports.daily' => App\MessageFactory\DailyReport::class,
    ],
];
```

Invokable class names are resolved through InterMix only when dispatched. Live
config may contain callables; cached config requires class names. Foundation
does not scan message or listener directories.

## Dispatch

```php
$envelope = $app->messaging()->dispatch(new GenerateReport($id));
$app->messaging()->event(new OrderPaid($orderId));
$app->messaging()->dispatchNotification($notification);
```

The `Bus` and `Event` facades expose the same manager boundary. Synchronous
events preserve configured listener order. Queued listeners and messages follow
Omnibus routing, retry, failure, serialization, and transport contracts.

## Consumers and scheduling

```bash
php infbyte queue:consume
php infbyte queue:consume --queue=reports --limit=100
php infbyte schedule:dispatch-message reports.daily
```

Console controls overlap, time limits, worker scaling, and process supervision.
Omnibus owns message delivery and retry. Each consumed message enters a fresh
InterMix scope; cleanup runs after both success and failure.

## Authentication forwarding

`messaging.forward_auth_events=false` is the default. When enabled, Foundation
first persists an auth audit event and then forwards it to Omnibus. Messaging
failure never replaces the canonical audit store.

## Testing

```php
$recording = $app->testing()->fakeMessaging();
$app->messaging()->dispatch(new GenerateReport('42'));

assert($recording->count(GenerateReport::class) === 1);
$app->messaging()->restore();
```
