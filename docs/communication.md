# Communication and email

Foundation composes application communication profiles; TalkingBytes owns the
protocol engines.

Install the optional communication module when the application needs outbound
HTTP, email, webhooks, inbound email, or gRPC composition:

```bash
php infbyte module:install communication
```

This publishes `config/communication.php` and `config/notifications.php`.

## Ownership

Foundation owns only application integration policy:

- named HTTP, webhook and gRPC profiles;
- named outbound email sender profiles and reusable transport definitions;
- application-relative email spool, log and DKIM-key paths;
- authentication-notification sender selection and message mapping;
- configured inbound gRPC method-to-service resolution through InterMix;
- production guards that depend on application environment.

TalkingBytes owns protocol behavior:

- HTTP transport, requests, responses, authentication, cookies and multipart;
- HTTP retries, rate limiting, circuit breaking, idempotency, signing and pools;
- webhook signing, verification, replay protection, sending and receiving;
- SMTP, sendmail, PHP mail, log, spool, fake and null email transports;
- MIME parsing, attachments, bounce parsing, authentication-results parsing and DKIM;
- IMAP and POP3 mailboxes and spool receivers;
- unary and streaming gRPC clients, native/generated-stub invocation, metadata,
  retries, inbound dispatch and status mapping;
- protocol fakes, assertions and protocol event dispatchers.

Foundation intentionally has no `CommunicationManager` or `NotificationManager`
facade. Use native TalkingBytes objects for protocol work.

## Outbound HTTP

The native `HttpClient` binding is the configured default HTTP profile:

```php
use Infocyph\TalkingBytes\Http\HttpClient;

$http = $app->make(HttpClient::class);
$result = $http->get('https://api.example.com/health');
```

For another named application profile, resolve the narrow Foundation profile
composer:

```php
use Infocyph\Foundation\Communication\CommunicationProfiles;

$clients = $app->make(CommunicationProfiles::class);
$billing = $clients->http('billing');
```

`communication.http.clients.*` maps directly to TalkingBytes configuration and
optional decorators. Foundation does not wrap `HttpRequest`, `HttpResponse`,
fakes, multipart bodies, request pools, middleware, or signing helpers.

In production, configured Foundation HTTP profiles must keep both TLS peer and
host verification enabled. Applications that deliberately construct a native
TalkingBytes client outside Foundation own that decision themselves.

`HttpClient` is execution-scoped because cookies, rate limiters and circuit
breakers can contain mutable process state. A persistent worker therefore gets
a fresh configured client for each execution scope.

## Webhooks

The default native bindings are available directly:

```php
use Infocyph\TalkingBytes\Webhook\WebhookReceiver;
use Infocyph\TalkingBytes\Webhook\WebhookSender;
use Infocyph\TalkingBytes\Webhook\WebhookVerifier;

$sender = $app->make(WebhookSender::class);
$receiver = $app->make(WebhookReceiver::class);
$verifier = $app->make(WebhookVerifier::class);
```

Named profiles are selected through `CommunicationProfiles`:

```php
$sender = $app->make(CommunicationProfiles::class)->webhookSender('billing');
$receiver = $app->make(CommunicationProfiles::class)->webhookReceiver('partner');
```

The application may pass a native TalkingBytes `WebhookReplayStore` when
constructing a receiver from the profile composer. Foundation does not create a
second replay abstraction.

Production inbound profiles reject the shipped `change-me` secret. Outbound
profiles may select a named HTTP profile and optional TalkingBytes retry/signing
policy.

## gRPC outbound

Foundation does not own a gRPC transport or generated client. It only applies a
named application retry profile to a native TalkingBytes `GrpcClient`.

For an application callable:

```php
use Infocyph\Foundation\Communication\CommunicationProfiles;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;

$grpc = $app->make(CommunicationProfiles::class)->grpc(
    static function (GrpcRequest $request): GrpcResponse {
        // Application transport/caller.
    },
);
```

For a generated PHP gRPC stub:

```php
$grpc = $app->make(CommunicationProfiles::class)->grpcGeneratedStub(
    $generatedStub,
    ['/billing.Invoice/Get' => 'Get'],
    'internal',
);
```

The returned object is TalkingBytes `GrpcClient`, so unary, server-streaming,
client-streaming and bidirectional APIs stay native to TalkingBytes.

## gRPC inbound

`communication.grpc.inbound.handlers` maps normalized method names to InterMix
service identifiers:

```php
use App\Grpc\GetInvoiceHandler;

return [
    'grpc' => [
        'inbound' => [
            'handlers' => [
                '/billing.Invoice/Get' => GetInvoiceHandler::class,
            ],
        ],
    ],
];
```

A handler may implement TalkingBytes' native contract:

```php
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundHandlerInterface;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;

final class GetInvoiceHandler implements GrpcInboundHandlerInterface
{
    public function handle(GrpcInboundRequest $request): GrpcInboundResponse
    {
        // Resolve repositories/services through constructor injection.
    }
}
```

Foundation resolves configured handlers through InterMix only when the scoped
`GrpcInboundDispatcher` is requested:

```php
use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;

$dispatcher = $app->make(GrpcInboundDispatcher::class);
```

This gives inter-service and microservice request/response handling a native
TalkingBytes boundary without introducing another server framework. The actual
gRPC server/listener remains application or deployment infrastructure.

## Outbound email

`notifications.email.transports` defines reusable application transport
profiles. Each transport selects a native TalkingBytes driver such as `smtp`,
`sendmail`, `spool`, `log`, `mail`, `fake`, or `null`.

`notifications.email.senders` then composes transport selection with optional
fallback, retry, rate-limit and DKIM policy. This lets multiple application
senders share one transport definition or select different transports.

The configured default sender is injected as the native `Emailer`:

```php
use Infocyph\TalkingBytes\Email\Emailer;

$emailer = $app->make(Emailer::class);
$emailer->send($message); // Native TalkingBytes EmailMessage.
```

Select another sender without introducing a transport facade:

```php
use Infocyph\Foundation\Notifications\EmailProfiles;

$marketing = $app->make(EmailProfiles::class)->sender('marketing');
```

The `Emailer` binding is execution-scoped so mutable retry/rate-limit/fake
transport state does not cross persistent worker executions.

## Authentication email

Authentication remains a Foundation workflow. When
`auth.drivers.notifications` selects `talkingbytes`, `notifications.auth.sender`
selects one `notifications.email.senders` profile. Foundation maps the auth
event to a TalkingBytes message; TalkingBytes performs delivery.

Production refuses auth sender profiles whose selected transport driver is
`null` or `fake`. A deliberate `log` transport remains valid for audit-only
applications.

## Inbound email

Inbound email is first-class and remains native TalkingBytes behavior.

The default configured spool receiver is available directly:

```php
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;

$receiver = $app->make(SpoolEmailReceiver::class);
```

Named spool, IMAP and POP3 profiles are application selections:

```php
$email = $app->make(EmailProfiles::class);

$spool = $email->spoolReceiver('support');
$imap = $email->imapMailbox('support');
$pop3 = $email->pop3Mailbox('legacy');
```

Foundation only resolves relative application paths for spool storage. Mailbox
connections, receive loops, parsing, locking and message movement are owned by
TalkingBytes.

Native parsers and DKIM verification are also directly resolvable:

```php
use Infocyph\TalkingBytes\Email\Dkim\DkimVerifier;
use Infocyph\TalkingBytes\Email\Parser\AuthenticationResultsParser;
use Infocyph\TalkingBytes\Email\Parser\BounceParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
```

## Events and persistent runtimes

Foundation does not configure TalkingBytes' process-wide static
`CommunicationEventBus`. That avoids introducing ambient communication state
into reusable Foundation workers. Applications that intentionally use the
TalkingBytes global event bus may configure it directly and are responsible for
its process lifecycle.

Prefer explicit TalkingBytes `EventDispatcher` injection when a protocol object
is constructed by application code.

Network clients, mailboxes and gRPC/native transport objects must not be opened
inside service-provider `register()` methods used before a worker pool forks.
Resolve them inside the child/execution scope. Foundation's built-in configured
HTTP clients, emailers and inbound gRPC dispatchers follow that rule.
