# Filesystem and storage

Foundation composes application storage; Pathwise owns filesystem behavior.

Install the optional filesystem module when the application needs configured storage, uploads, downloads, or the Webrick file-transfer bridge:

```bash
php infbyte module:install filesystem
```

`PathManager` remains part of Foundation core and does not require Pathwise. It owns application locations such as `storage/`, `public/`, `bootstrap/`, `routes/`, and `resources/`.

## Ownership

Foundation owns only application-specific filesystem policy:

- application disk names and `filesystem.disks` configuration;
- selection of the default application disk;
- resolving relative local roots against the application base path;
- upload and download policy from `filesystem.uploads` and `filesystem.downloads`;
- the Webrick uploaded-file and streamed-response bridge;
- public-to-storage symbolic links;
- explicit X-Sendfile and X-Accel-Redirect enablement.

Pathwise and Flysystem own the filesystem engine:

- reads, writes, streams, copies, moves, deletes, listings, visibility, URLs, and metadata;
- local and remote storage adapters;
- archives, synchronization, indexing, deduplication, retention, watching, queueing, and policy engines;
- upload validation, chunk assembly, naming and content checks;
- download validation, ranges, metadata and stream copying.

Foundation intentionally has no broad `FilesystemManager` facade for these operations.

## Configured disks

`StorageRegistry` maps Foundation's configured disk names to native Flysystem operators:

```php
use Infocyph\Foundation\Filesystem\StorageRegistry;

$storage = $app->make(StorageRegistry::class);
$public = $storage->disk('public');

$public->write('reports/today.txt', 'ready');
```

Requesting the native interface resolves the configured default disk:

```php
use League\Flysystem\FilesystemOperator;

$files = $app->make(FilesystemOperator::class);
$files->write('state.json', '{}');
```

For generic Pathwise workflows, use Pathwise directly. `StorageRegistry::path()` is available when a configured application disk needs to be expressed as a mounted Pathwise path:

```php
use Infocyph\Foundation\Filesystem\StorageRegistry;
use Infocyph\Pathwise\PathwiseFacade;

$storage = $app->make(StorageRegistry::class);
$files = PathwiseFacade::at($storage->path('exports', 'public'));
```

Disk initialization is lazy. Foundation replaces only the mount names declared by `filesystem.disks`; it does not reset Pathwise's process-wide mount registry, so independent Pathwise mounts are not discarded when the Foundation filesystem capability activates.

## Uploads

Foundation converts `filesystem.uploads` into a native Pathwise `UploadProcessor`:

```php
use Infocyph\Pathwise\StreamHandler\UploadProcessor;

$uploads = $app->make(UploadProcessor::class);
```

The processor is transient because its policy is mutable. Foundation configures its destination, extension policy, validation limits, chunk limits, naming strategy, malware-scan requirement, and strict content checks; Pathwise performs the upload work.

Strict content-type validation is enabled by default. This preserves Pathwise's secure default and rejects MIME/extension or magic-signature mismatches unless the application explicitly opts out.

For Webrick requests, resolve `FilesystemUploadRequestHandler`. It only translates `UploadedFile` and request metadata into Pathwise's upload inputs; it does not implement an upload engine.

## Downloads

Foundation likewise configures the native Pathwise `DownloadProcessor`:

```php
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;

$downloads = $app->make(DownloadProcessor::class);
```

`FilesystemResponseFactory` is the Webrick bridge for normal downloads, inline responses, range/conditional handling, and optional web-server offload. Pathwise prepares and streams the file; Foundation maps that result into Webrick responses.

X-Sendfile is local-filesystem-only and requires:

```text
filesystem.offload.x_sendfile.enabled = true
```

X-Accel-Redirect requires:

```text
filesystem.offload.x_accel_redirect.enabled = true
```

Calling either offload method while its capability is disabled fails instead of emitting a trusted server header accidentally.

## Storage links

`storage:link` remains Foundation-owned because it connects application layout rather than providing a generic filesystem operation:

```bash
php infbyte storage:link
```

Every configured link must remain inside the public directory and every target must remain inside application storage. Existing correct links are preserved; conflicting paths are rejected.

## Persistent runtimes and fork safety

Resolving `StorageRegistry` itself does not open configured storage backends. Native Flysystem operators are created when a disk is first needed. Custom service providers intended for pooled worker mode should therefore keep filesystem resolution out of `register()` just as they avoid opening database, cache, HTTP, or message-broker connections before fork.
