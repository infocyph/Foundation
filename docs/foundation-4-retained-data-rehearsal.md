# Foundation 4 state integrity and recovery

This is a **Foundation 4.0-native** release acceptance guide, not a migration guide for an earlier major. Previous deployment backups, old schema readers, and downgrade compatibility are not release blockers.

## Verify current selected capabilities

1. Produce a fresh Foundation 4 dataset using the selected published native owners. Record source SHA, dependency lock, schema migration IDs and service topology.
2. Exercise password/account/session state, refresh token families, MFA/recovery, passkey credential records, and token revocation/replay denial. Check isolated request and worker lifecycles with real selected service adapters.
3. Exercise Omnibus durable queue retries/dead letters, CacheLayer transaction outbox, cache replay counters and Pathwise files where selected. Validate idempotency, transaction rollback, cancellation and recovery.
4. Snapshot the **4.0-created** database and selected durable state with writes stopped. Restore in an isolated process, preserving keys and external storage when applicable. Verify record digests, permissions, state isolation and no duplicate event replay.
5. Capture CI source SHA, service matrix, backup/restore checksums, per-domain counts and any gaps. Do not promote unit or synthetic SQLite evidence to claims about unexercised production service backends.

## Acceptance boundary

`Foundation4RetainedDataRestoreTest` now creates the current 4.0 auth/OAuth schemas using native DBLayer migrations, records password hashes, sessions, refresh families, MFA fields, passkey counters/record and OAuth refresh state, then checks a SHA-256 snapshot restore in a fresh connection. It proves current-state byte restoration and exact retained record values; it does **not** prove actual Epicrypt secret decryption or a networked database backup procedure.

The selected-owner integration evidence is covered separately by `AuthDomainIntegrationTest`, `BrowserSessionIntegrationTest`, `OAuth21RefreshConcurrencyTest`, `OtpPasskeyIntegrationTest`, `Epicrypt3MfaSecretProtectionTest`, `CacheLayer4PdoOutboxIntegrationTest`, `Omnibus26DurableIntegrationTest`, `PersistentDatabaseLifecycleTest` and `FilesystemPathwise41TrustBoundaryTest`. Their native lifecycle, replay and rollback tests are not equivalent to a full multi-service production snapshot. Batch 5's scoped 4.0-native persistence and recovery acceptance passed on [Security & Standards #38526599007](https://github.com/infocyph/Foundation/actions/runs/38526599007). Production operators must still validate multi-service backups, keys and external object/queue state for their own deployments; the SQLite restore is not a substitute.

Real customer recovery procedures remain an operator responsibility. Foundation 4 release certification does **not** depend on transforming records from older Foundation major versions.
