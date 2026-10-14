# Foundation 4 state integrity and recovery

This is a **Foundation 4.0-native** release acceptance guide, not a migration guide for an earlier major. Previous deployment backups, old schema readers, and downgrade compatibility are not release blockers.

## Verify current selected capabilities

1. Produce a fresh Foundation 4 dataset using the selected published native owners. Record source SHA, dependency lock, schema migration IDs and service topology.
2. Exercise password/account/session state, refresh token families, MFA/recovery, passkey credential records, and token revocation/replay denial. Check isolated request and worker lifecycles with real selected service adapters.
3. Exercise Omnibus durable queue retries/dead letters, CacheLayer transaction outbox, cache replay counters and Pathwise files where selected. Validate idempotency, transaction rollback, cancellation and recovery.
4. Snapshot the **4.0-created** database and selected durable state with writes stopped. Restore in an isolated process, preserving keys and external storage when applicable. Verify record digests, permissions, state isolation and no duplicate event replay.
5. Capture CI source SHA, service matrix, backup/restore checksums, per-domain counts and any gaps. Do not promote unit or synthetic SQLite evidence to claims about unexercised production service backends.

## Acceptance boundary

The existing `Foundation4RetainedDataRestoreTest` verifies an isolated SQLite backup/restore path but was originally designed around a simulated earlier schema. It is supporting evidence only until refocused on current 4.0 state. The Batch 4 integration suites already exercise native auth, OAuth, OTP/passkey, DBLayer/CacheLayer outbox and durable Omnibus behavior. Batch 5 still requires a cohesive current-state recovery acceptance; its checklist must remain open until that evidence is verified.

Real customer recovery procedures remain an operator responsibility. Foundation 4 release certification does **not** depend on transforming records from older Foundation major versions.
