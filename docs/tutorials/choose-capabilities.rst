.. _tutorial-capabilities:

Tutorial: choose and activate capabilities
==========================================

Infbyte should not instantiate a full database, queue, mail system and
filesystem on every request merely because the corresponding packages
exist in ``vendor/``. Foundation 4 separates **Composer package
installation**, **optional feature selection**, **application
configuration** and **runtime capability activation**.

This tutorial applies to the **Foundation 4 candidate** only.
The current Infbyte ``main`` skeleton still uses Foundation 3's
older optional-module names.

Know what is already Core
-------------------------

Foundation 4 includes Webrick HTTP, InterMix DI, Runwire integration,
ArrayKit configuration, CacheLayer infrastructure, authentication
composition, browser sessions, logging, notifications and operations
as Core dependencies or services. Being included in Core does not
mean every service must be constructed by every runtime or request.

Optional public modules are grouped by purpose. The exact
**eight qualified features** are:

.. list-table::
   :header-rows: 1
   :widths: 17 24 22 37

   * - Group
     - Feature
     - Owner
     - What it enables
   * - data
     - ``data.database``
     - DBLayer
     - SQL connections, migrations, seeding
   * - data
     - ``data.filesystem``
     - Pathwise
     - Configured disks and transfer policy
   * - security
     - ``security.crypto``
     - Epicrypt
     - Native cryptographic mechanisms
   * - security
     - ``security.validation``
     - ReqShield
     - Validation and sanitization
   * - security
     - ``security.mfa``
     - OTP
     - Application OTP factor integration
   * - security
     - ``security.passkey``
     - OTP + WebAuthn
     - Passkey ceremonies and credentials
   * - execution
     - ``execution.messaging``
     - Omnibus
     - Events, jobs, queues and workers
   * - execution
     - ``execution.communication``
     - TalkingBytes
     - HTTP, email, webhook and gRPC composition

No generic ``module:install data`` means “enable every data
feature.” Groups are read-only views unless specific features
are explicitly named.

Step 1 — start from the use case
--------------------------------

For a basic public ``GET /health`` endpoint you may need no optional
feature. For a JSON API accepting user data, select validation only.
For storing invoices, add database. For file uploads, add filesystem.
For asynchronous report delivery, add messaging and the chosen
transport dependencies.

Do **not** enable every optional package in the name of future
flexibility. That increases attack surface, configuration burden and
the number of potential startup dependencies.

Step 2 — inspect the proposed change
------------------------------------

Use read-only commands before modifying the application:

.. code-block:: bash

   php infbyte module:list
   php infbyte module:show security
   php infbyte module:plan security.validation
   php infbyte module:doctor security.validation

The Foundation 4 machine-readable module-plan schema is version 2.
The plan records requested features, required dependencies,
current states, effects, blockers and warnings. Resolve blockers
rather than guessing which packages must be installed.

``module:plan`` describes work; it does not modify Composer,
application config or runtime graph.

Step 3 — install, then enable
-----------------------------

Installation and activation are deliberately separate:

.. code-block:: bash

   php infbyte module:install security.validation
   php infbyte module:enable security.validation
   php infbyte module:doctor security.validation

``install`` reconciles selected Composer packages, publishes missing
feature-owned configuration and provisions applicable specialist
schema. **It does not enable the feature.**

``enable`` validates dependencies and updates
``config/modules.php`` atomically. A feature's actual application
``app.capabilities`` or module policy must also be consistent
with the selected production topology.

A no-change operation should not rewrite configuration or
invalidate compiled artifacts unnecessarily.

Step 4 — understand dependency examples
----------------------------------------

**Validated input against a SQL record:** choose
``security.validation`` for ReqShield. If the schema uses
DBLayer-backed ``exists`` or ``unique`` rules, also choose
``data.database``. The validator does not own the SQL engine.

**OTP MFA:** the selected ``security.mfa``/OTP path using
``auth.drivers.mfa=otp`` requires the explicit
``security.crypto`` prerequisite. OTP factor state may also
need shared cache/durable storage, depending on the chosen policy.

**Passkeys:** ``security.passkey`` is independently selectable
and may bring ``web-auth/webauthn-lib``; it does not imply
every MFA factor mode is active.

**SQL-backed durable queue:** the selected Omnibus durable transport
requires ``execution.messaging`` and ``data.database``,
an applicable queue/failure-store schema and explicit payload codecs.

**Outbound email/HTTP:** select ``execution.communication``
for TalkingBytes profiles. Installing communication does not
start an inbound gRPC server or make all outbound clients eagerly
connect.

Step 5 — publish only needed configuration
------------------------------------------

Feature-owned configuration lives in the application, while
Foundation's own templates are the canonical references.
For example:

.. code-block:: bash

   php infbyte module:config:publish data.database
   php infbyte module:config:publish security.crypto

Publishing does not overwrite an application-owned file and
does not itself enable a feature. Review the actual published
configuration before production use. Core ``cache.php`` is
not an optional module installation target.

Do not put unrelated security/communication/database values into
the auth config merely to make them accessible from one place.

Step 6 — verify readiness and compile
-------------------------------------

After changing selected package/configuration topology:

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte app:ready
   php infbyte optimize
   php infbyte optimize:report

Production runs a deterministic compiled generation. A missing
required selected package should fail readiness or compilation;
production must not silently create a different provider graph
because a package appeared in ``vendor/``.

Run a representative endpoint/job for every newly activated feature
against the **actual selected backend**. A passing schema check does
not prove an SMTP server or database will be reachable after launch.

Step 7 — disable and remove safely
----------------------------------

When a feature is no longer used, confirm no routes, jobs, config
or retained data rely on it.

.. code-block:: bash

   php infbyte module:disable data.filesystem
   php infbyte module:remove data.filesystem --dry-run
   php infbyte module:remove data.filesystem

Foundation's removal contract requires prior disable, preserves
packages shared by selected siblings, and **does not delete
application data, tables or migrations**. Releasing the module
is not the same as irreversibly dropping user state.

If a Composer or install phase fails partway through, inspect
the returned phases/recovery report and the real installed
graph before using ``module:repair``. Do not blindly remove
packages or rewrite config files to mask inconsistent ownership.

Step 8 — measure what optionality saved
---------------------------------------

Run the ordinary minimal endpoint and the selected-owner endpoint
before and after a topology change. Compare latency, worker memory,
actual backend connections, number of constructed services and
release compilation cost under the **same dependency and host**
configuration.

An optionality claim is only real if the cold path avoids eager
configuration parsing, network connections and per-request service
construction. A green functional test alone cannot prove a
performance gain.

Before declaring a major release ready, repeat production host,
cleanup, data preservation and security validation; these remain
separate from documentation QA.

Troubleshooting
---------------

.. list-table::
   :header-rows: 1
   :widths: 28 37 35

   * - Symptom
     - Common cause
     - Action
   * - Module installed but unavailable
     - Feature not enabled or capability absent
     - Check ``module:doctor`` and topology
   * - Enabling fails
     - Missing prerequisite or invalid package version
     - Read ``module:plan`` blockers
   * - Configuration not published
     - Feature-owned file already present or wrong group
     - Inspect app config and publish policy
   * - Production differs from development
     - Dev discovery versus explicit compiled topology
     - Inspect selected features and active generation
   * - Remove fails
     - Feature enabled or a sibling shares dependency
     - Disable first, inspect plan and retained state
   * - Requests become slower
     - Overly broad middleware or eager service resolution
     - Profile selected graph and actual backend calls

Further reading
---------------

See :doc:`../advanced/modules`,
:doc:`../start/configuration`,
:doc:`../architecture/application-lifecycle`, and the
`Foundation module feature contract
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/modules.md>`_.
