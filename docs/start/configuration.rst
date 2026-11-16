.. _onboarding-configuration:

Configuring your Infbyte application
====================================

Infbyte is an **application**. Its configuration belongs in the root
``config/`` directory of **your Infbyte project**, not in the
Foundation package source. Foundation composes these files into the
runtime graph; owning libraries implement the selected behavior.

This guide uses the actual **Infbyte 4 candidate** on
``feature/infbyte-4``. The candidate is not a published
Foundation 4 release. For installation and compatibility rules,
see :doc:`foundation4-candidate`.

Where you edit configuration
----------------------------

With a candidate application checkout, run:

.. code-block:: bash

   cd my-app
   php infbyte config:show app.name
   php infbyte module:list

Open ``my-app/config/app.php`` to change the application identity.
Open ``my-app/config/router.php`` to select route files and matcher
policy. Both locations are relative to **the Infbyte project root**.

The candidate includes these configuration files:

.. list-table::
   :header-rows: 1
   :widths: 27 38 35

   * - File in your project
     - What you configure
     - Important distinction
   * - ``config/app.php``
     - Application name, environment, URL, debug, capability topology,
       development container/cache options
     - Infbyte application preferences
   * - ``config/auth.php``
     - Auth drivers, secrets by environment name, MFA/passkey policy
     - Core auth; optional drivers require selected features
   * - ``config/cache.php``
     - CacheLayer stores, counters and locks
     - Core; distributed state needs an appropriate shared backend
   * - ``config/modules.php``
     - Explicit enabled optional feature IDs
     - Package installation alone does not activate a feature
   * - ``config/notifications.php``
     - Notification channel and email sender/transport policy
     - Default null delivery does not send email
   * - ``config/router.php``
     - Explicit Webrick route file list and matcher policy
     - Route files are not scanned automatically

Files like ``config/database.php`` and ``config/validation.php``
are **not shipped in the default Core-only Infbyte candidate**.
Their selected specialist feature publishes the application-owned
configuration when needed.

Step 1 — adjust identity and environment
----------------------------------------

The existing ``config/app.php`` declares the application environment
and production debug defaults using Foundation's environment helpers:

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
   ];

This is an **abbreviated illustration** of the real file. Do not
replace the full existing ``config/app.php`` with this snippet:
the actual candidate additionally defines ``capabilities``,
``config_cache`` and ``container`` policy.

Your candidate's ``.env.example`` starts with:

.. code-block:: text

   APP_NAME=Infbyte
   APP_ENV=local
   APP_DEBUG=true
   APP_URL=http://localhost
   APP_CAPABILITIES=

Use real process/deployment environment variables for production.
``.env.example`` is a variable reference, not a credential store.
Do not assume every host automatically loads a local ``.env`` file;
check the selected entrypoint/environment loader and deployment
mechanism. Never commit production credentials or print full config
values into diagnostic logs.

Step 2 — understand two independent selections
----------------------------------------------

**Runtime capabilities** come from ``app.capabilities`` in
``config/app.php`` (the candidate reads ``APP_CAPABILITIES``).
**Optional features** are enabled in ``config/modules.php``.

The Core-only candidate initially has:

.. code-block:: php

   <?php

   declare(strict_types=1);

   return [
       'features' => [],
   ];

This means a basic Webrick health endpoint can run without
activating DBLayer, Pathwise, Omnibus or TalkingBytes. Merely
installing a package does not request a runtime capability.

Before using an optional validator in your application:

.. code-block:: bash

   php infbyte module:plan security.validation
   php infbyte module:install security.validation
   php infbyte module:enable security.validation
   php infbyte module:doctor security.validation

This is a **mutating** development workflow. Inspect the plan
and prerequisites before executing it. The selected feature
owns ``config/validation.php`` in **your Infbyte project**.
Adjust the application capability topology intentionally;
do not put arbitrary package names into ``APP_CAPABILITIES``.

A common mistake is to configure ``AUTH_MFA=otp`` without
selecting the OTP/crypto features and shared replay state.
Consult :doc:`../tutorials/choose-capabilities` for the
dependency/enablement matrix.

Step 3 — configure routes where Infbyte expects them
----------------------------------------------------

The actual candidate's ``config/router.php`` includes:

.. code-block:: php

   <?php

   declare(strict_types=1);

   return [
       'files' => ['api.php'],
       'matcher' => env_string('ROUTER_MATCHER', 'fused'),
   ];

Those entries refer to application route sources under
``routes/``, so ``api.php`` means ``routes/api.php``.
Create an additional route file only when you also select
it in the ``files`` configuration array.

The actual sample routes are ``GET /api/health`` and
``GET /json``. They are implemented through Webrick
``Registrar`` and the application
``App\Http\Controllers\SystemController``.

.. code-block:: bash

   php infbyte route:list
   php infbyte config:show router.matcher

For route registration, callable handlers and HTTP behavior,
see :doc:`../http/routing` and
:doc:`first-application`.

Step 4 — configure shared state appropriately
---------------------------------------------

In ``config/cache.php`` the candidate defaults to the
named ``local`` store, and ``auth.php`` defaults to
``AUTH_STORAGE=memory`` with ``AUTH_CACHE=array``.
Those make development and isolated tests inexpensive.

They are **not automatically safe defaults for horizontally
scaled production authentication**. Cross-process session
coordination, OTP replay prevention, locks, queue ownership and
auth state require a shared atomic/durable backend selected
for that purpose.

Choose and test the actual backend; do not merely change a
label from ``array`` to ``redis`` without provisioning Redis,
dependencies, security policy and failure behavior.

The default ``config/notifications.php`` selects null
email delivery. For account verification or resets, configure
a real delivery transport and sender profile before promising
that notifications reach users.

Step 5 — add a database only if the app needs it
------------------------------------------------

No ``config/database.php`` is present in the initial Core-only
Infbyte candidate. To add DBLayer:

.. code-block:: bash

   php infbyte module:plan data.database
   php infbyte module:install data.database
   php infbyte module:enable data.database
   php infbyte module:doctor data.database

The database feature publishes its configuration to
**``my-app/config/database.php``** when applicable.
Its schema defaults originate from Foundation's internal
templates, but you do not create/edit an Infbyte application's
configuration in the Foundation package directory.

After reviewing the new file, configure connections, run
``php infbyte migrate:status`` and inspect changes before
applying migrations. See
:doc:`../tutorials/database-migrations`.

Step 6 — validate and compile
-----------------------------

During ordinary development:

.. code-block:: bash

   php infbyte config:validate
   php infbyte app:ready

For candidate production preparation, verify stricter policy,
then create an immutable generation:

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte optimize
   php infbyte optimize:report

``config:cache`` and ``config:clear`` manage development
ArrayKit configuration caches under ``bootstrap/cache/``.
They are **not** equivalent to Foundation's verified
four-runtime production generation under ``storage/releases/``.

A source/config change only reaches a production process
after a reviewed generation rebuild and host activation.
Never disable the trust check to make a stale or invalid
generation boot.

Diagnosing common configuration problems
----------------------------------------

.. list-table::
   :header-rows: 1
   :widths: 27 37 36

   * - Symptom
     - Likely cause
     - Check
   * - ``config/database.php`` absent
     - DBLayer feature not installed/published
     - ``module:plan`` and ``module:doctor data.database``
   * - Feature installed but unusable
     - Not explicitly enabled or capability missing
     - ``config/modules.php`` and ``config/app.php``
   * - New route returns 404
     - Route file not listed or compiled generation stale
     - ``config/router.php``, ``route:list``, release SHA
   * - Email not sent
     - Default null transport or wrong sender profile
     - ``config/notifications.php`` and transport credentials
   * - Browser auth state disappears across workers
     - Process-local storage/coordination in use
     - Auth and cache drivers plus actual shared backend
   * - Production rejects bootstrap
     - Config/vendor graph or manifest trust mismatch
     - ``app:ready`` and ``optimize:report``

Next steps
----------

Read :doc:`directory-structure`,
:doc:`../reference/configuration` and
:doc:`../tutorials/choose-capabilities`.

**Framework-maintainer note:** The Foundation *package* repository
contains the template source at ``resources/config/``. This is
internal framework provenance, **not** an Infbyte application path.
