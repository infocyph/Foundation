.. _filesystem-guide:

Working with files and uploads in Infbyte
=========================================

Infbyte's optional ``data.filesystem`` feature composes
**Pathwise** storage into the application. Foundation
selects disk names and application path policy;
Pathwise/Flysystem owns file operations and transfer
security. No additional Foundation filesystem facade
is required.

**Where you configure it:** ``my-app/config/filesystem.php``
**after** the filesystem feature is selected/published.
A fresh Core-only Infbyte 4 candidate does **not**
ship that file.

When to use filesystem storage
------------------------------

Use this feature for persistent application documents,
user uploads, generated reports, controlled downloads
or public assets that need explicit storage policy.

If your code only needs an application path such as
``storage/logs/``, Foundation Core's ``PathManager``
can provide that without enabling the Pathwise feature.

Step 1 — select the filesystem feature
--------------------------------------

From the Infbyte application project root:

.. code-block:: bash

   php infbyte module:plan data.filesystem
   php infbyte module:install data.filesystem
   php infbyte module:enable data.filesystem
   php infbyte module:doctor data.filesystem

Inspect the plan and installed native package before
activating the feature. Installation can publish
``config/filesystem.php`` into the **Infbyte** app,
but does not activate the feature automatically.

Step 2 — inspect configured disks
---------------------------------

The current Foundation 4 filesystem template, when
published into an Infbyte application, provides
application-relative local disks:

.. list-table::
   :header-rows: 1
   :widths: 23 35 42

   * - Disk
     - Default local root
     - Typical purpose
   * - ``local``
     - ``storage/app``
     - Private application data
   * - ``public``
     - ``storage/app/public``
     - Public data subject to explicit link/server policy
   * - ``uploads``
     - ``storage/uploads``
     - Untrusted user-upload staging and persistence

The default disk uses ``FILESYSTEM_DISK``, falling
back to ``local``. These disk roots are **relative
to your Infbyte project**, not the Foundation
library's ``resources/`` directory.

The supplied public link mapping is
``public/storage`` to ``storage/app/public``.
The existence of the mapping does not mean every
deployment should create it automatically.

Step 3 — write and read a file
------------------------------

From an application service with access to a
configured Foundation ``Application $app``:

.. code-block:: php

   use Infocyph\Foundation\Filesystem\StorageRegistry;

   $storage = $app->make(StorageRegistry::class);

   $disk = $storage->disk('public');
   $disk->write('reports/today.txt', 'Ready');

   $contents = $disk->read('reports/today.txt');
   $logicalPath = $storage->path('reports/today.txt', 'public');

``StorageRegistry::disk()`` returns a native
Flysystem ``FilesystemOperator``. ``write()``
and ``read()`` are native filesystem operations,
not custom Foundation wrappers.

The ``public`` disk must be present in the selected
application configuration. If it is local, the
configured root maps the logical relative key to
a physical location beneath
``my-app/storage/app/public/``.

Do not concatenate a user-supplied path with a
host filesystem root. Use the selected disk's
native normalized path and authorization policy.

Step 4 — choose between logical and local paths
-----------------------------------------------

``StorageRegistry::path('reports/today.txt', 'public')``
produces the logical disk-qualified identity.
``localPath()`` is for an eligible local disk
when an actual on-disk path is needed.

A logical path is **not** permission to expose
the underlying file through a public URL. Always
control what an authenticated user is allowed to
download or publish.

For remote storage drivers, there may be no
meaningful local OS path. Don't assume every
Pathwise disk corresponds to a local directory.

Step 5 — safely accept uploads
------------------------------

Uploads originate in an untrusted HTTP request.
Webrick owns HTTP adaptation and request/body limits;
Foundation selects the Pathwise processor and
application upload policy.

The selected ``config/filesystem.php`` describes:

* ``uploads.disk`` and ``uploads.directory``
  for the destination.
* ``uploads.max_file_size`` — 5 MiB in the
  current template unless configured otherwise.
* ``uploads.max_chunk_count`` and
  ``uploads.max_chunk_size`` for bounded staging.
* ``uploads.allowed_file_types``,
  ``allowed_extensions`` and
  ``blocked_extensions`` for filtering.
* ``uploads.malware_scan`` for optional scanner
  selection and fail-closed policy.
* ``uploads.validation_profile`` for optional
  native validation.

The current blocked extension list includes
PHP scripts, executable files and shell/batch
file types. File extension and client MIME type
alone cannot prove file safety. Validate actual
content and size with the selected trust profile.

Pathwise 4.2's strict untrusted-data profile
requires bounded staging; it is not safe to
read an arbitrary upload fully into process memory
just to inspect it.

Step 6 — malware scanning policy
--------------------------------

The default scanner selection mode is
``when_configured``. A scanner can be selected
through an actual application binding or a
configured clamd service.

For a deployment that **requires** scanning,
select ``malware_scan.mode=required``, configure
and test the scanner and reject uploads when
it is unavailable.

Do not spawn privileged shell antivirus commands
from HTTP handlers. The native Pathwise scanner
uses bounded transport and timeout policy.

Step 7 — public files and download policy
-----------------------------------------

For a configured local public root, review:

.. code-block:: bash

   php infbyte storage:status
   php infbyte storage:link

``storage:link`` is a mutating operation. Verify
the intended source/target and public-root
containment policy before creating a symlink.
The Pathwise manager owns safe link validation,
not a blind application shell command.

Downloads can choose a configured disk,
permitted roots, blocked extensions and
bounded streaming/range policies. Do not
serve an arbitrary file path supplied by
the requester.

Offload options such as ``X-Sendfile`` or
``X-Accel-Redirect`` require corresponding
web-server configuration and a verified
authorization boundary. They are not made
safe simply by turning on one config switch.

Step 8 — test filesystem behavior
---------------------------------

In an Infbyte test application with the selected
feature, use a temporary local disk root and
test these cases:

1. Writing and reading an authorized object.
2. Missing-file behavior and error mapping.
3. Rejection of ``../`` traversal attempts.
4. Oversized content, malformed MIME and
   blocked extensions.
5. Scanner required but not configured.
6. Unauthorized download/public-link access.
7. Cleanup after a failed upload or cancelled
   persistent worker.
8. Different requests/users cannot reuse the
   same temporary upload state.

A fake or local test disk cannot certify
remote object-store authentication, network
failure semantics or durability. Keep real
backend acceptance separate from unit tests.

Persistent workers and performance
----------------------------------

Don't retain a Webrick upload object, temporary
file path or mutable request across persistent
Runwire executions. Pathwise/Foundations's
execution boundary should clean operation
resources after each request/job.

Bound file size, upload chunks and download
streams before choosing concurrency. Avoid
buffering entire media files in memory when
a native streamed transfer is appropriate.

The application must not create privileged
symlinks or write outside selected roots as
an alternative to configuring the proper disk.

Troubleshooting
---------------

.. list-table::
   :header-rows: 1
   :widths: 30 33 37

   * - Symptom
     - Likely cause
     - Safe check
   * - ``config/filesystem.php`` absent
     - Optional feature not selected/published
     - ``module:plan data.filesystem``
   * - Disk not found
     - Wrong disk name or disabled feature
     - Selected config and ``StorageRegistry::disks()``
   * - Writes fail locally
     - Root ownership/permission or invalid path
     - Check actual Infbyte storage directories
   * - Uploaded executable rejected
     - Correct default blocked extension policy
     - Keep policy; validate trusted format instead
   * - Public URL is broken
     - Link/server mapping not configured
     - ``storage:status`` and web-server root
   * - Long downloads exhaust worker memory
     - Buffering rather than bounded streaming
     - Native Pathwise/Webrick transfer policy
   * - File appears under Foundation source
     - Confused template path with app disk root
     - Edit ``my-app/config/filesystem.php``

Further reading
---------------

See :doc:`../start/directory-structure`,
:doc:`../reference/configuration`,
:doc:`../tutorials/choose-capabilities` and
the native Pathwise project:

https://github.com/infocyph/Pathwise
