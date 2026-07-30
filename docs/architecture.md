# Architecture and lifecycle

## Ownership

Foundation owns application bootstrap, configuration composition, runtime
selection, providers, route middleware policy, authentication, browser
sessions, resources, operational commands, and adapters between standalone
Infocyph packages.

It does not reimplement the packages it composes:

| Capability | Owner |
| --- | --- |
| Dependency injection and scopes | InterMix |
| HTTP routing, requests, responses, emitters | Webrick |
| Commands, schedules, worker supervision | Console |
| Events, queues, retries, failures | Omnibus |
| Database connections, schema, migrations, pagination | DBLayer |
| Cache stores, counters, locks | CacheLayer |
| Filesystems | Pathwise |
| Validation | ReqShield |
| Cryptography | Epicrypt |
| HTTP, email, notifications, webhooks, gRPC | TalkingBytes |

## Separate runtime graphs

Create the runtime explicitly:

```php
$web = Foundation::web(['base_path' => dirname(__DIR__)]);
$cli = Foundation::console(['base_path' => dirname(__DIR__)]);
```

Web mode prepares paths, routing, logging, and the HTTP boundary. Console mode
prepares paths only. A console command may activate routing, database, cache, or
messaging when that command needs it, but listing and help remain preflight
operations and do not create a Foundation application.

The runtime is not inferred from `PHP_SAPI`; tests and workers may intentionally
execute web behavior under CLI.

## Lazy capabilities

`Application::has()` checks whether a service can be provided without
activating it. `Application::make()` activates the owning deferred provider and
then resolves the service. Optional packages remain unavailable until installed
and produce an actionable `module:install` error.

Route middleware activates auth and browser sessions only on routes that select
them. Messaging, database, cache, validation, filesystem, communication, and
cryptography do not enter a plain route graph.

## Persistent processes

Every HTTP request runs inside an InterMix scope. Every consumed Omnibus message
runs inside a separate `ExecutionScope`. In `finally` blocks Foundation clears:

- the current principal;
- active browser sessions and leases;
- open DBLayer transactions and request observation buffers;
- scoped InterMix instances.

The cleanup runs after successful and failed work. Long-lived workers may reuse
the application container, but application singletons must never retain
request-, message-, tenant-, or principal-specific data.

## Cache-time work

Foundation deliberately moves discovery and compilation to deployment:

- config files become a single or sharded config cache;
- route files become the selected Webrick matcher cache;
- command and schedule maps become Console manifests;
- installed packages that explicitly expose `foundation-module.php` become one
  module manifest.

No package, command, listener, migration, route, or provider directory is
scanned during a request.
