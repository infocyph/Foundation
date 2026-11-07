# Foundation 4 state integrity and recovery

This guide distinguishes **Foundation 4-native state verification** from the separately required **Foundation 3 → 4 cutover and rollback**. The old source/dependency/data combination and coherent data restore must be qualified before the major release. Current-version-only tests cannot replace historical upgrade evidence.

## Verify current selected capabilities

1. Produce a fresh Foundation 4 dataset using the selected published native owners. Record source SHA, dependency lock, schema migration IDs and service topology.
2. Exercise password/account/session state, refresh token families, MFA/recovery, passkey credential records, and token revocation/replay denial. Check isolated request and worker lifecycles with real selected service adapters.
3. Exercise Omnibus durable queue retries/dead letters, CacheLayer transaction outbox, cache replay counters and Pathwise files where selected. Validate idempotency, transaction rollback, cancellation and recovery.
4. Snapshot the **4.0-created** database and selected durable state with writes stopped. Restore in an isolated process, preserving keys and external storage when applicable. Verify record digests, permissions, state isolation and no duplicate event replay.
5. Capture CI source SHA, service matrix, backup/restore checksums, per-domain counts and any gaps. Do not promote unit or synthetic SQLite evidence to claims about unexercised production service backends.

## Acceptance boundary

`Foundation4RetainedDataRestoreTest` now creates the current 4.0 auth/OAuth schemas using native DBLayer migrations, records password hashes, sessions, refresh families, MFA fields, passkey counters/record and OAuth refresh state, then checks a SHA-256 snapshot restore in a fresh connection. It proves current-state byte restoration and exact retained record values; it does **not** prove actual Epicrypt secret decryption or a networked database backup procedure.

The selected-owner integration evidence is covered separately by `AuthDomainIntegrationTest`, `BrowserSessionIntegrationTest`, `OAuth21RefreshConcurrencyTest`, `OtpPasskeyIntegrationTest`, `Epicrypt3MfaSecretProtectionTest`, `CacheLayer4PdoOutboxIntegrationTest`, `OmnibusDurableIntegrationTest`, `PersistentDatabaseLifecycleTest` and `FilesystemPathwiseTrustBoundaryTest`. Their native lifecycle, replay and rollback tests are not equivalent to a full multi-service production snapshot. The [QA workflow](../.github/workflows/security-standards.yml) runs the current-state persistence and recovery tests. Production operators must still validate multi-service backups, keys and external object/queue state for their own deployments; the SQLite restore is not a substitute.

Real customer recovery procedures remain an operator responsibility. Foundation 4 release certification requires an independently verified Foundation 3 → 4 migration and recovery path. A full multi-service application backup, operational key restoration and recovery drill remain deployment-specific responsibilities.

## Separate source/dependency graph artifact rebuild and rollback

The official cross-major CI job also runs `foundation3_to_4_release_artifact_probe.php` in independent PHP processes with each source revision's published Composer graph. The version-specific native `FoundationReleaseCompiler` builds the web, CLI, worker and scheduler artifacts into distinct immutable generations under one release root. The probe boots every runtime using the source that built it, switches the active generation from Foundation 3 to Foundation 4 and back, then reactivates Foundation 4. It checks all four generated containers, web matcher artifacts, runtime modes, release-manifest SHA-256 and the installed dependency fingerprint on each switch. It **fails** if the two resolved dependency fingerprints are identical or if switching changes the stored generation identity.

This verifies artifact integrity and old/new source and package-graph switching on the selected published packages; it does not replace a production-equivalent multi-service snapshot or certify that existing 3.x runtime binaries can execute under 4.x code. The original 3.x Composer lock remains absent; the original source is real, while its dependency set is a separately recorded reconstruction. Official P4 generation and selected-state acceptance is based on the exact-head fully green Batch 114 CI and the coordinated SQL+Redis/Omnibus, auth and four-runtime evidence below. This does not supersede application-specific live multi-service disaster recovery.

## One consistent old-source durable SQL and Redis restore

The hosted cross-major rehearsal now selects the original published **Omnibus 2.6** dependency alongside DBLayer 5.1 and CacheLayer 3.4, separately from the Foundation 4 Omnibus 3 / DBLayer 6 / CacheLayer 4 graph. It registers a stable, persisted Omnibus message-codec alias and uses each version's native `Foundation::worker`, `MessagingDatabaseSchema`, `MessageBus`, `DBLayerTransport` and `DBLayerFailureStore` in independent processes.

Under quiesced writes, the original source creates real auth/session/refresh/MFA/passkey records, a durable pending queue entry, a dead-letter/failure record and a Redis replay-denial entry. One SQLite file snapshot preserves the auth and Omnibus SQL tables together, while a Redis `DUMP`/TTL snapshot preserves the matching cache state. The candidate source first upgrades the auth digest, reads the old queued message and dead-letter state using Omnibus 3, and reads Redis using CacheLayer 4. The old source then proves it can read the candidate-touched durable state before recovery. The original SQL and Redis snapshots are restored as one quiesced logical checkpoint and read under original source/dependencies/artifacts before a second Foundation 4 roll-forward.

The original-data digest, queue-row/failure-row digest, original credential identity, cache value and release manifest/dependency fingerprints are recorded in separately labeled CI artifacts. This is a selected published SQLite/Redis two-store **application-state rehearsal**, not evidence of a fully validated PostgreSQL/Redis Cluster production backup, independent object storage, external secrets or in-flight application-owned jobs. Those require deployment-specific production-equivalent validation.

## Native Redis cache replay-state cutover

The selected cross-major CI job now uses a dedicated disposable Redis 8 service and version-specific native CacheLayer factories. It persists legacy session/replay-denial cache state under the original Foundation 3/CacheLayer 3 graph, checks current Foundation 4/CacheLayer 4 can read that state, snapshots the actual server-side Redis entries using `DUMP` and millisecond TTL, mutates candidate state, restores the old cache snapshot using `RESTORE`, and checks both old-source rollback and new-source roll-forward read the same original replay-denial value. Binary Redis snapshots, service counts and version-specific source references are retained as artifacts. The isolated cache store is never a shared production Redis database.

The Redis check qualifies this selected cache namespace and serialization path. A whole-application restore still requires matching PostgreSQL/MySQL/SQLite data, Omnibus durable queue receipts and failure records, key material, transactional outbox state and any application-owned storage at the **same consistent recovery point**. Those multi-service requirements are addressed in the coordinated native SQL/Redis/Omnibus rehearsal above, rather than relying on this isolated Redis slice. Production-specific physical recovery still needs its own validation.

## Original-source retained-state cutover evidence

The `cross-major-auth-rehearsal` CI job checks out immutable Foundation 3 source `fc9b3258646ac744e61fd02e18d9b98e5afff2e8` independently from the release candidate. It resolves the original source with selected published DBLayer 5 and the new source with DBLayer 6. Both locks, their package identities and the old manifest are retained as CI artifacts. **The resolved legacy lock is a reconstructed 2026 graph, not an authentic originally tracked lock**, which is unavailable.

Independent PHP processes with each version's own Composer autoloader create native historical SQLite account, session, refresh-token, MFA and raw passkey records; take a quiesced database snapshot; run the current credential-hash migration; re-open retained records with the original source and dependency set; restore the original snapshot; and execute the forward migration again. Immutable retained-record digests and native SHA3-256 passkey lookup storage are checked.

This individual test qualifies only the SQL/auth portion. The coordinated original/new source plus queue and Redis checkpoint and all four generated release artifacts are certified by the separate hosted cross-major job, as recorded in Batch 114. It does **not** claim physical backups of every production database backend, external object stores, encryption-key recovery or a live customer migration; these remain application-operator obligations.
