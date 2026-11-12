.. _filesystem-guide:

Filesystem and uploads
======================

Foundation 4 composes application storage roots, selected disks and
HTTP transfer policy. **Pathwise/Flysystem** owns storage mechanics,
safe uploads, downloads, archives and backend operations.

Enable the filesystem feature
-----------------------------

.. code-block:: bash

   php infbyte module:plan data.filesystem
   php infbyte module:install data.filesystem
   php infbyte storage:status

The ``data.filesystem`` feature is optional. Foundation's core
``PathManager`` still supports application paths without Pathwise.

Work with a configured disk
---------------------------

With an application runtime available, the Foundation adapter selects
a native Pathwise disk:

.. code-block:: php

   use Infocyph\Foundation\Filesystem\StorageRegistry;

   $storage = $app->make(StorageRegistry::class);
   $public = $storage->disk('public');
   $public->write('reports/today.txt', 'ready');

   $logical = $storage->path('reports/today.txt', 'public');

This example requires a configured ``public`` disk. The logical
path would be ``public://reports/today.txt``; the on-disk location
is determined by the disk configuration.

Uploads and downloads
---------------------

Webrick adapts incoming uploaded files; Foundation selects an application
Pathwise upload processor and policy. Pathwise owns bounded staging,
validation, scanning and cleanup. Never trust client-provided names,
MIME metadata or arbitrary paths as a storage authority.

The default Foundation 4 upload trust policy uses Pathwise's strict
untrusted-data profile. Optional malware scanning can be configured
without starting privileged external scanner processes in the HTTP
worker. An application can require a scanner and fail closed when it
is not configured.

Persistent runtimes
-------------------

Keep file-transfer resources scoped to the operation; do not retain an
upload's temporary path or mutable request object in a shared singleton.
Use configured public-link policies rather than creating arbitrary
filesystem symlinks.

Read the `Foundation filesystem contract
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/filesystem.md>`_
and `Pathwise <https://github.com/infocyph/Pathwise>`_.
