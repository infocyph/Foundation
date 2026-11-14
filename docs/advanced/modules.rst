.. _optional-features:

Optional modules and capability topology
========================================

Foundation 4 provides **prebuilt Core** and exactly three public
optional module groups. Each of the eight features is selected
individually. This is a breaking change from the published Infbyte 3
skeleton, which still uses older feature identifiers.

The Foundation 4 catalog
------------------------

.. list-table:: Selectable features
   :header-rows: 1
   :widths: 16 28 22 34

   * - Group
     - Qualified feature
     - Native owner
     - Config file
   * - data
     - ``data.database``
     - DBLayer
     - ``database.php``
   * - data
     - ``data.filesystem``
     - Pathwise
     - ``filesystem.php``
   * - security
     - ``security.crypto``
     - Epicrypt
     - ``security.php``
   * - security
     - ``security.validation``
     - ReqShield
     - ``validation.php``
   * - security
     - ``security.mfa``
     - OTP
     - No feature config file
   * - security
     - ``security.passkey``
     - OTP/WebAuthn
     - No feature config file
   * - execution
     - ``execution.messaging``
     - Omnibus
     - ``messaging.php``
   * - execution
     - ``execution.communication``
     - TalkingBytes
     - ``communication.php``

Core supplies authentication, sessions, notifications, caching,
logging, application paths, runtime and CLI policy. CacheLayer,
InterMix, Runwire and Webrick are Core dependencies; they are not
eight additional optional modules.

Inspect before changing the application
---------------------------------------

.. code-block:: bash

   php infbyte module:list
   php infbyte module:show data
   php infbyte module:doctor data.database
   php infbyte module:plan data.database

``module:show`` accepts a group for read-only inspection, but a
group name alone must **not** install all of its features. The
machine-readable v2 plan includes requested/required features,
current states, effects, warnings and blockers.

Install deliberately
--------------------

For a database-backed application:

.. code-block:: bash

   php infbyte module:install data.database --dry-run
   php infbyte module:install data.database
   php infbyte module:enable data.database
   php infbyte module:config:publish data.database
   php infbyte module:doctor data.database

Use the resulting **``config/database.php`` in your Infbyte project** as
application-owned configuration. The Core-only Infbyte candidate does not
ship this file until the selected feature provisions it. The Foundation
package's ``resources/config/database.php`` is only internal template source.
Publication does not overwrite
an existing application file; it cannot safely infer your real
credentials, schema or production topology.

An installation plan is tied to Composer root/installed metadata
and configuration identity. If dependencies change during the
operation, Foundation rejects a stale plan rather than silently
activating a different graph. Re-plan after a conflict.

Schema and data lifecycle
-------------------------

A package may require schema provisioning or native service readiness.
Only provision the selected feature's applicable schemas:

.. code-block:: bash

   php infbyte module:schema:status data.database
   php infbyte module:schema:sync

Check the specific feature's schema contract before running
mutating commands. Removing a module does **not** automatically
drop tables or delete application data. Coordinate upgrades
with retained-state and backup policies.

Development versus production selection
---------------------------------------

Development may discover installed packages when no explicit
capability topology is supplied. A production release must use
an **explicit selected topology**, including native package versions,
configuration policy and compiled dependency fingerprints. An
empty production selection is minimal; it is not "enable everything".

If the first real resolution of an optional dependency fails, do not
solve it by globally enabling all features. Inspect the specific
selection, installed package/version, published config and
``app:ready`` report.

Read the complete `Foundation module contract
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/modules.md>`_.

For a use-case-first walkthrough of the install/enable/config/ready lifecycle,
see :doc:`../tutorials/choose-capabilities`.
