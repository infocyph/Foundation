.. _production-operations:

Production operations and maintenance
=====================================

An Infbyte deployment needs controlled operational commands and
**external** supervision for its web and background processes.
Foundation provides command/runtime policy, but does not replace
the host's web server, scheduler, DB engine or process supervisor.

Readiness and diagnostics
-------------------------

Start with the least intrusive checks:

.. code-block:: bash

   php infbyte about
   php infbyte env:show
   php infbyte config:validate --production
   php infbyte app:ready
   php infbyte route:list

``app:ready`` checks activated package/schema/storage policy,
not the real HTTP listener. For an actual health probe, hit a
known low-cost route through the selected host with expected
proxy and TLS configuration.

Maintenance mode
----------------

For planned maintenance, Foundation can return HTTP 503 from
its web boundary:

.. code-block:: bash

   php infbyte maintenance:enable --retry=60 --message='Maintenance in progress'
   php infbyte maintenance:status
   php infbyte maintenance:disable

The default maintenance backend is file-based. A multi-node
deployment needs a visible shared state policy; configure
``operations.maintenance.driver=cache`` with a suitable
CacheLayer backend if all nodes must respond consistently.

Always test maintenance toggling under the actual host before
relying on it during a risky migration. Include an external
recovery path if the cache/maintenance store becomes unavailable.

Request cooperative process replacement
---------------------------------------

.. code-block:: bash

   php infbyte runtime:reload
   php infbyte worker:restart
   php infbyte schedule:interrupt

These commands atomically update generation-control state.
They signal running processes; **they do not restart or spawn
them**. Configure systemd, Supervisor, container orchestrators
or a comparable owner to replace drained processes.

The file-backed control uses a stable lock plus atomic replacement;
cache-backed control uses CacheLayer coordination. Distributed
setups need shared visibility for generation tokens.

Inspect workers and executions
------------------------------

.. code-block:: bash

   php infbyte worker:list
   php infbyte worker:status
   php infbyte schedule:list
   php infbyte execution:list --kind=schedule --limit=50

Execution history is disabled by default because recording every
transition adds I/O. Set ``operations.history.enabled=true``
only when the use case justifies its cost.

Runtime heartbeat records are observability metadata, **not**
process supervision authority. A stale visible record does not
authorize killing an unrelated PID.

Logs and sensitive data
-----------------------

Foundation resolves a PSR-3 logger with ``null``,
JSON Lines file and ``error_log`` driver options.
Configure structured redaction and log rotation through
the application and host as appropriate.

Do not log tokens, passwords, session cookies, environment keys,
raw webhook secrets or full request bodies. Error rendering to
a client is separate from operational reporting; client responses
must not expose internal traces merely because an exception
was logged.

Production checklist
--------------------

After a change, confirm:

* expected active generation and externally supplied digest;
* actual request success and negative-path behavior;
* correct database/cache/queue connectivity and bounded backlog;
* scheduler/worker heartbeat, cooperative shutdown and replacement;
* restart and rollback procedure with retained data;
* logging redaction and acceptable load/error rates.

For source-specific command flags and detailed operational status,
consult `Foundation operations
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/operations.md>`_.
