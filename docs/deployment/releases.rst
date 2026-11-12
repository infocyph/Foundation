.. _releases-guide:

Immutable releases and safe rollback
====================================

Foundation 4 publishes a single verified generation for web, CLI,
worker and scheduler applications. This prevents partially upgraded
graphs and ensures the running process uses a consistent combination
of configuration, dependency identity and generated artifacts.

The release lifecycle
---------------------

A successful ``php infbyte optimize``:

1. Resolves configuration, selected capabilities and installed packages.
2. Creates a staging generation with a normalized ``config.php``
   snapshot and native InterMix/Webrick production artifacts.
3. Validates built artifacts, dependency fingerprints and manifest
   identities before activation.
4. Renames the staged generation into an immutable generation path.
5. Atomically switches the active generation pointer.
6. Retains in-use older generations until they can safely be pruned.

Build/clear/prune operations serialize through a release-root lock.
A losing concurrent build must fail before publication rather than
racing the active pointer.

Verifying the active generation
-------------------------------

Inspect an intended candidate from a controlled deployment context:

.. code-block:: bash

   php infbyte optimize:report
   php infbyte app:ready

A successful report shows build/dependency readiness, not proof
that the exact source has passed all host-performance gates.

Production loaders verify the manifest against an **external** trusted
SHA-256 and check the currently installed dependency graph. The
generation includes ``foundation.php``, ``config.php``
and the compiled sub-artifacts; a digest read from the same writable
directory is not an independent trust anchor.

Rolling activation
------------------

Keep a previous generation available while existing processes
finish their work. Each loaded generation holds a shared OS
lease on an immutable runtime marker; a prune needs an exclusive
non-blocking lease and skips in-use generations.

For a multi-process deployment:

1. Stage and verify the new generation and compatible data state.
2. Configure the supervisor to pass the **new trusted digest**
   to replacement processes.
3. Stop acceptance of new work on retiring workers; allow bounded
   requests/jobs to drain or cancel according to ownership rules.
4. Start replacement workers and verify their actual loaded
   generation, host adapter and selected capabilities.
5. Shift traffic only after endpoint and background-work checks.
6. Retain the prior generation until processes release it and the
   data compatibility window has closed.

Do not swap one running container's production service graph
in place. New processes must load the chosen generation.

Rollback is more than a pointer change
--------------------------------------

Code/runtime rollback atomically reactivates a retained generation
and restarts workers with the digest of **that** manifest.
It does not undo a migration or delete data created by the new
generation.

Database rows, persistent queues, failure stores, sessions, replay
markers and crypto key formats must remain readable by both
generations during a rolling interval or require a tested
restore/migration procedure. Prefer additive schema changes
before removing fields consumed by old code.

When cryptographic keys rotate, configure bounded fallback
verification keys so old state remains readable while a new
active key writes fresh state, subject to each owning security
library's documented policy.

Failure and recovery
--------------------

**Build interrupted:** the active pointer must remain on the
previous verified generation. Investigate staging output without
manually activating partial artifacts.

**Manifest mismatch:** stop promotion; confirm the external digest
and source/vendor fingerprint, then rebuild if needed.

**Worker still on old generation:** signal cooperative shutdown
with ``runtime:reload`` or ``worker:restart``, and let the
supervisor replace it. Do not delete an in-use generation.

**Data cannot be read after rollback:** stop affected writers,
restore or migrate a coherent code/dependency/data snapshot.
Do not attempt to repair through a forced older binary without
proving compatibility.

Read the maintained `Foundation release operation contract
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/operations.md>`_
and `retained state rehearsal
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/foundation-4-retained-data-rehearsal.md>`_.

See :doc:`overview` and :doc:`troubleshooting`.
