# Filesystem and storage

Foundation composes application storage policy; Pathwise 4 owns filesystem mechanics.

Install the optional filesystem module when the application needs configured storage, uploads, downloads, or the Webrick file-transfer bridge:

```bash
php infbyte module:install filesystem
```

`PathManager` remains Foundation core and does not require Pathwise. It owns application locations such as `storage/`, `public/`, `bootstrap/`, `routes/`, and `resources/`.

## Ownership

Foundation owns application disk configuration/default selection, relative local-root resolution, upload/download/offload/link policy, Webrick adaptation, and scanner composition. Pathwise/Flysystem own filesystem operators, storage adapters, upload staging/validation/chunking, malware scan execution, download ranges/iteration, safe symlinks, archives, synchronization, retention, watching, queues, and other generic filesystem mechanics.

Foundation intentionally has no broad filesystem engine or second named-storage registry.

## StorageContext and configured disks

Each Foundation application/generation creates exactly one Pathwise `StorageContext`. Disk operators stay lazy inside that context, and canonical logical paths use the configured disk name directly:

```php
use Infocyph\Foundation\Filesystem\StorageRegistry;

$storage = $app->make(StorageRegistry::class);
$public = $storage->disk('public');
$public->write('reports/today.txt', 'ready');

$logical = $storage->path('reports/today.txt', 'public');
// public://reports/today.txt
```

`StorageRegistry` is a thin application-policy adapter. It never registers Pathwise mounts, replaces a Pathwise global default, hashes application paths into mount names, or mutates another application's topology. Two applications in one persistent process may therefore both use disk names such as `uploads` without cross-talk.

The native `FilesystemOperator` DI binding resolves the configured default disk. `StorageRegistry::context()` exposes the application-owned Pathwise context for advanced Pathwise workflows that explicitly need it.

Relative roots of local disks are resolved against the Foundation base path before `StorageContext` construction. Unknown disks, malformed names/configuration, or an invalid default fail during application boot without opening configured storage backends.

## Uploads

`FilesystemTransferFactory` creates a fresh Pathwise `UploadProcessor` for each resolution and injects the application's `StorageContext`. Destination paths are canonical context paths such as `uploads://incoming`.

For Webrick requests, `FilesystemUploadRequestHandler` adapts an `UploadedFile` directly with `UploadSource::fromMover()`. Foundation does not create `foundation-upload-*` staging files or own generic upload cleanup. Pathwise materializes the source privately, validates/scans it, and cleans staging deterministically on success or failure.

Normal and chunked uploads preserve Foundation's configured destination, extension/type/size/image limits, naming policy, and strict content validation while Pathwise owns their mechanics.

### Malware scanning

`filesystem.uploads.malware_scan.mode` accepts:

- `off` — never scan;
- `when_configured` — secure default; scan when a scanner is configured;
- `required` — scanner configuration is mandatory and Foundation fails boot before traffic when no scanner is available.

`filesystem.uploads.malware_scan.driver` may be `clamav`, `service`, or omitted. When omitted, Foundation uses an application-bound Pathwise `MalwareScannerInterface` if one exists. `service` requires that binding. `clamav` composes Pathwise's `ClamAvDaemonScanner` directly.

The ClamAV integration uses bounded clamd `INSTREAM`. Prefer a Unix socket; loopback TCP is permitted by Pathwise, while remote TCP requires explicit opt-in. Foundation never launches `clamscan`, `maldet`, `sudo`, or another privileged scanner process from a request worker. LMD may integrate with ClamAV at the host/signature layer without changing this PHP boundary.

Custom, ICAP, AMWScan, cloud, or other scanners should implement Pathwise `MalwareScannerInterface` in an application provider rather than adding vendor branches to Foundation.

## Downloads

Foundation creates a fresh Pathwise `DownloadProcessor`, injects the same application `StorageContext`, and maps application policy onto it. `FilesystemResponseFactory` asks Pathwise to prepare a download and uses Pathwise `streamChunks()` for adapter-backed/logical streaming.

Pathwise owns validation, exact range positioning, seek/discard behavior, byte iteration, and resource closure. Foundation/Webrick retain HTTP conditionals, status/headers, `FileBody` selection for eligible local files, iterable response bodies, HEAD handling, and native output.

This keeps full, single-range, suffix/open-ended, invalid, and unsatisfiable range semantics in one lower-layer implementation while preserving Webrick as the HTTP owner.

## Server offload

X-Sendfile and X-Accel-Redirect are explicit application/server capabilities. Calling either while disabled fails closed. X-Sendfile accepts only a local storage identity and emits the resolved local path only after the same Pathwise download policy has been prepared. X-Accel-Redirect requires a trusted matching server-side internal location.

## Storage links

`storage:link`, `storage:status`, and `storage:unlink` remain Foundation commands because the mappings are application layout. The generic link mechanics are Pathwise-owned:

```bash
php infbyte storage:status
php infbyte storage:link
php infbyte storage:unlink
```

Foundation resolves configured mappings and supplies its public directory as the allowed link root and storage directory as the allowed target root to Pathwise `SafeSymlinkManager`. Pathwise owns traversal protection, containment, correct-link idempotency, conflicting-path rejection, broken-link status, current-target verification, and safe removal.

## Persistent runtimes and fork safety

Filesystem composition is application-instance scoped. `StorageContext` caches operators only inside its owning Foundation application; upload/download processors are transient; scanner resolution contains no request/job state. No request mutates Pathwise global mount/default topology.

`StorageRegistry` construction validates topology but does not construct disk backends. Operators remain lazy until first use, so provider composition remains safe before worker forks. Runtime graphs that do not select the filesystem capability contain no Foundation filesystem services.
