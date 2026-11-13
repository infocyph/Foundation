.. _configuration-reference:

Infbyte application configuration reference
===========================================

This page is for someone **building an Infbyte application**.
Every ``config/filename.php`` below is relative to the
**Infbyte project root** (for example ``my-app/config/app.php``).
It is never a suggestion to edit the Foundation library's source.

**Version:** the material is verified against Infbyte candidate
``feature/infbyte-4`` and its current Foundation 4 development
contracts. Public Infbyte ``main`` remains Foundation 3.
Start with :doc:`../start/configuration` for the practical sequence.

Six files shipped with the Infbyte 4 candidate
-----------------------------------------------

These files exist before you activate any optional package:

.. list-table::
   :header-rows: 1
   :widths: 28 42 30

   * - Path in **your Infbyte project**
     - Main keys
     - When to edit
   * - ``config/app.php``
     - ``name``, ``env``, ``debug``, ``url``,
       ``capabilities``, ``config_cache``, ``container``
     - Identity, runtime topology, local build options
   * - ``config/auth.php``
     - ``drivers``, ``token_secret_environment``,
       ``otp``, ``webauthn``
     - Account/credential providers and security posture
   * - ``config/cache.php``
     - ``default``, ``lock``, ``stores``,
       ``counters``, ``connections``
     - CacheLayer store and atomic coordination choices
   * - ``config/modules.php``
     - ``features``
     - Enable/disable qualified optional integrations
   * - ``config/notifications.php``
     - ``channels``, ``auth``, ``email``
     - Notification routing, sender profiles, transports
   * - ``config/router.php``
     - ``files``, ``matcher``
     - Explicit Webrick route source and matcher selection

The candidate does **not** ship ``config/database.php`` by
default. That file is published into your app when you select
the ``data.database`` feature; the same rule applies to other
optional feature configuration.

Configuration hierarchy
-----------------------

During development or release compilation Foundation composes:

#. Framework Core defaults and selected preset.
#. Application files in ``my-app/config/*.php``.
#. Explicit inline bootstrap overrides, where the supported
   runtime factory allows them.

The result is accessible through
``Application::config(): ConfigRepository``. This is **composed
configuration**, not a promise that the latest disk file
will be reparsed on every request.

For direct programmatic reads in an application service,
``$app->config()->get('app.name')`` obtains a value and
``$app->config()->all()`` exposes the composed array.
Use the CLI for safe diagnostics; do not dump full configuration
when it may contain secrets.

A production generation includes a validated immutable config
snapshot. Restarting an already active process without
rebuilding a changed generation may retain the old settings.
Do not modify generated ``storage/releases/`` PHP to force
a configuration update.

Application identity: config/app.php
------------------------------------

The actual candidate derives ``name``, ``env``,
``debug`` and ``url`` from the environment:

.. code-block:: php

   <?php

   declare(strict_types=1);

   $environment = env_string('APP_ENV', 'local');
   $production = $environment === 'production';

   return [
       'name' => env_string('APP_NAME', 'Infbyte'),
       'env' => $environment,
       'debug' => env_bool('APP_DEBUG', !$production),
       'url' => env_string('APP_URL', 'http://localhost'),
       'capabilities' => [],
       'config_cache' => [
           'type' => env_string(
               'APP_CONFIG_CACHE_TYPE',
               $production ? 'single' : 'sharded',
           ),
       ],
   ];

This is an **illustrative subset**. The real file parses the
``APP_CAPABILITIES`` comma-separated value and includes
InterMix development container settings. Keep the
candidate's full file and edit only the fields you need.

Do not enable ``APP_DEBUG`` on an externally accessible
production host. Set ``APP_URL`` to the intended canonical
external origin used by auth/cookie/callback policies, and
configure trusted proxies at the Webrick host boundary.

``"config_cache.type"`` chooses an ArrayKit development
cache layout (supported values: ``sharded`` or ``single``).
It does **not** select how the immutable Foundation release
generation is stored.

Selected features: config/modules.php
-------------------------------------

The initial Infbyte 4 candidate is explicitly Core-only:

.. code-block:: php

   <?php

   declare(strict_types=1);

   return [
       'features' => [],
   ];

The exact eight optional feature identifiers are:

.. list-table::
   :header-rows: 1
   :widths: 24 38 38

   * - Group
     - Qualified features
     - Native mechanism
   * - data
     - ``data.database``, ``data.filesystem``
     - DBLayer, Pathwise
   * - security
     - ``security.crypto``, ``security.validation``,
       ``security.mfa``, ``security.passkey``
     - Epicrypt, ReqShield, OTP, WebAuthn
   * - execution
     - ``execution.messaging``,
       ``execution.communication``
     - Omnibus, TalkingBytes

Use the CLI to plan, install, enable and inspect a feature:

.. code-block:: bash

   php infbyte module:plan data.database
   php infbyte module:install data.database
   php infbyte module:enable data.database
   php infbyte module:doctor data.database

``module:install`` can reconcile Composer and selected
feature-owned configuration **without enabling** it.
``module:enable`` updates the application's feature selection.
The ``app.capabilities`` topology must also permit the
requested runtime service. Adding every package and turning
on every capability globally is neither necessary nor cheap.

Authentication: config/auth.php
-------------------------------

``auth.drivers`` selects implementations by **driver name**:

.. list-table::
   :header-rows: 1
   :widths: 28 35 37

   * - Config key
     - Candidate default
     - Production concern
   * - ``drivers.storage``
     - ``memory``
     - Use a durable account store when accounts persist
   * - ``drivers.cache``
     - ``array``
     - Shared/atomic state needed for replay/lock coordination
   * - ``drivers.passwords``
     - ``native``
     - Verify hashing/password policy
   * - ``drivers.tokens``
     - ``simple``
     - Configure token secret and lifecycle
   * - ``drivers.mfa``
     - ``simple``
     - OTP needs explicit optional feature(s)
   * - ``drivers.passkey``
     - ``memory``
     - WebAuthn needs passkey feature and RP/origin policy
   * - ``drivers.notifications``
     - ``collect``
     - Select real trusted delivery when sending auth email

The candidate's ``token_secret_environment`` defaults to
``AUTH_TOKEN_SECRET``, generated by ``php infbyte app:install``.
Its **value** must not be serialized into release metadata,
printed in a docs example, or committed in ``config/auth.php``.

``auth.otp`` configures issuer, HOTP/TOTP and recovery
codes. ``auth.webauthn`` configures relying-party identity,
origin, attestation and accepted algorithms. Never activate
these drivers without their selected features and actual
credential storage/replay policy.

Cache and locks: config/cache.php
---------------------------------

``config/cache.php`` is a **Core application file** and is
not supplied by an optional ``data.cache`` module.

The candidate's default cache store is ``local`` and
``default_counter`` is null. The ``stores`` table contains
explicit named CacheLayer backends, including
``memory``, ``file``, ``sqlite``, ``database``,
``redis`` and ``valkey``. A name in configuration does
not prove its backend is installed and reachable.

The ``lock`` configuration can choose a dedicated driver
and named store; otherwise it inherits the chosen native
CacheLayer lock where eligible. File/SQLite locks are
machine-local, not automatically cross-host coordination.

For a production session, OTP replay policy or distributed
worker overlap guard, select an atomic **shared** backend
and verify failure behavior. Do not use ``CACHE_STORE=memory``
and assume independent PHP workers share state.

Core cache schema operations:

.. code-block:: bash

   php infbyte cache:schema:status
   php infbyte cache:schema:install

Notifications: config/notifications.php
---------------------------------------

``notifications.channels`` contains application-owned
notification channels; ``notifications.auth`` selects
authentication notice routing, and ``notifications.email``
selects sender profiles and transports.

The candidate's ``email.senders.default.transport`` and
``email.senders.auth.transport`` resolve to a ``null``
transport by default, which discards mail. For production
password-reset or verification email, configure a real
transport and test actual delivery.

The native delivery engine is TalkingBytes. You do not
create a duplicate Foundation SMTP client inside Infbyte.

Routing: config/router.php
--------------------------

The candidate's default route list is:

.. code-block:: php

   <?php

   declare(strict_types=1);

   return [
       'files' => ['api.php'],
       'matcher' => env_string('ROUTER_MATCHER', 'fused'),
   ];

``api.php`` is resolved under **``my-app/routes/``**.
``router.matcher`` selects a Webrick format such as
``fused``, ``generated`` or ``sharded`` during build.
Do not confuse ``config/router.php`` (your application)
with the framework's route-cache implementation.

A new route source file only participates when selected
explicitly. Inspect:

.. code-block:: bash

   php infbyte route:list

Optional feature configuration
------------------------------

These **paths are inside the Infbyte application**, and
they appear only if a selected feature publishes them:

.. list-table::
   :header-rows: 1
   :widths: 35 30 35

   * - Application path
     - Feature
     - What it configures
   * - ``config/database.php``
     - ``data.database``
     - DBLayer connections, migration and seed manifests
   * - ``config/filesystem.php``
     - ``data.filesystem``
     - Pathwise disks and transfer policy
   * - ``config/validation.php``
     - ``security.validation``
     - ReqShield rule/sanitization profile selection
   * - ``config/security.php``
     - ``security.crypto``
     - Epicrypt-backed security mechanisms
   * - ``config/messaging.php``
     - ``execution.messaging``
     - Omnibus handlers, transports, codecs and worker profiles
   * - ``config/communication.php``
     - ``execution.communication``
     - TalkingBytes HTTP, email, webhook and gRPC profiles

OTP MFA and passkeys can use selected auth policy without
adding their own generic ``config/mfa.php`` or
``config/passkey.php`` unless a real application creates
additional settings.

Some Foundation Core policies have optional template
files such as ``session.php``, ``logging.php``,
``operations.php`` or ``responses.php``. They are
**not part of the six-file Infbyte candidate checkout**.
Do not claim a user must edit those files until an actual
selected project has published/provisioned them. Core
defaults remain available through Foundation composition.

Checking values and build status
--------------------------------

Use the project root and the actual selected runtime:

.. code-block:: bash

   php infbyte config:show app.name
   php infbyte config:show router.matcher
   php infbyte module:list
   php infbyte config:validate
   php infbyte app:ready

For candidate production configuration and release artifacts:

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte optimize
   php infbyte optimize:report

Never print secret-bearing config trees into CI summaries.
An application's own Composer lockfile should pin its
resolved dependency graph; Foundation's **library**
repository intentionally ignores its Composer lockfile.

Why the Foundation package has resources/config
-----------------------------------------------

The Foundation package houses its **template source** in
``resources/config/`` (normally read-only under your
application's ``vendor/infocyph/foundation/``).
That is a **framework maintainer** location.

Its templates supply canonical defaults and schema examples,
but **you configure an Infbyte application in ``my-app/config/``**.
Do not copy a ``resources/config/`` path into deployment
commands, route handling code or installation instructions.

For template provenance only:

https://github.com/infocyph/Foundation/tree/feature/foundation-3/resources/config

You can inspect those templates to understand the current
source contract without suggesting that users edit vendor
files or vendor paths in production.

Troubleshooting and next steps
------------------------------

* **Config missing:** check whether it is shipped Core, optional
  feature-owned or a Foundation-only template.
* **A file exists but feature is inactive:** inspect
  ``config/modules.php``, ``app.capabilities`` and module diagnostics.
* **A changed route doesn't take effect:** inspect
  ``config/router.php``, current release generation and route list.
* **Works in tests but not production:** confirm the backend,
  process environment and immutable installed dependency graph.
* **Email is silently absent:** check the default ``null``
  transport in ``config/notifications.php``.
* **A session disappears:** check whether ``memory``/``array``
  is still selected for cross-process auth/session state.

See :doc:`../start/directory-structure`,
:doc:`../tutorials/choose-capabilities`,
:doc:`../tutorials/database-migrations` and
:doc:`../tutorials/release-and-rollback`.
