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

## Original-source retained-state cutover evidence

The `cross-major-auth-rehearsal` CI job checks out immutable Foundation 3 source `fc9b3258646ac744e61fd02e18d9b98e5afff2e8` independently from the release candidate. It resolves the original source with selected published DBLayer 5 and the new source with DBLayer 6. Both locks, their package identities and the old manifest are retained as CI artifacts. **The resolved legacy lock is a reconstructed 2026 graph, not an authentic originally tracked lock**, which is unavailable.

Independent PHP processes with each version's own Composer autoloader create native historical SQLite account, session, refresh-token, MFA and raw passkey records; take a quiesced database snapshot; run the current credential-hash migration; re-open retained records with the original source and dependency set; restore the original snapshot; and execute the forward migration again. Immutable retained-record digests and native SHA3-256 passkey lookup storage are checked.

This qualifies only the selected native SQL/auth portion after a successful CI run. It is **not** evidence that a coherent real cache/queue/object-store snapshot, all production database drivers, both full source/dependency release-artifact generations or all application workloads can be rolled back. The canonical P4 criteria remain open until those wider release gates have matching evidence.
