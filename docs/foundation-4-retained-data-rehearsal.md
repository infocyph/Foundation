# Foundation 2.1.1 → 4.0 data and restore rehearsal

**Not a completed production migration.** Preserve a consistent *real* 2.1.1 backup and verify a restore in an isolated staging environment. Successful code rollback does **not** restore persistent data.

1. Record source SHA, Composer lock, DB vendor/schema revisions, host topology and every active application capability. Block ingress, scheduled jobs, queue consumers and outbound integrations before taking a consistent backup of database, encryption keys (protected separately), durable queue/outbox, cache replay state and file/object storage. Hash and encrypt the snapshot; never commit it.
2. Restore that snapshot into an isolated network and confirm old 2.1.1 readers can still read original rows and objects. Capture counts/IDs/hashes before any migration.
3. Install the Foundation 4 candidate with exact published specialist majors, rebuild InterMix 11.2 ABI-3 graph and Webrick 6 format-3 web/CLI/worker/scheduler artifacts. Apply **additive** auth/OAuth/MFA/passkey/messaging schema migrations; compare dry-run, applied IDs and post-run readiness. Mixed-version writes require expand/contract ordering.
4. Reopen each retained family below with the actual selected native owner and fresh processes. Do **not** silently rehash password records, reinterpret OAuth families, weaken OTP replay state or deserialize arbitrary queue payloads. If a format is incompatible, document an explicit transform or targeted revocation with operator approval.
5. Verify rollback independently: stop new writers, restore both data and keys/queues/files from snapshot, compare row digests and queue counts, and confirm old readers still work. Record RPO/RTO and which new-format writes cannot be downgraded.

| Retained data | Required outcome | Related automated evidence (not full real-backup proof) |
| --- | --- | --- |
| Password hashes / account statuses | Exact digest retention, sample verification, disabled/suspended denial | `AuthDomainIntegrationTest` |
| Auth/browser sessions, remember tokens | Expiration/rotation/revocation and cross-account separation | `OAuth21SchemaUpgradeTest`, `BrowserSessionIntegrationTest` |
| OAuth refresh families, PATs, codes and consents | Native Epicrypt reuse/revocation, double-consume rejection and key verification | `OAuth21RefreshConcurrencyTest`, `OAuth21DurableRevocationProcessTest` |
| MFA secrets and recovery codes | Correct AAD/key read, single-use recovery, revision CAS | `Epicrypt3MfaSecretProtectionTest` |
| Passkey credential counters and challenge state | Preserve credential ID, RP/origin, counters and verifier state | `OtpPasskeyIntegrationTest`, `OAuth21SchemaUpgradeTest` |
| Omnibus queue envelopes, retries and dead letters | Native-version codec compatibility and no double-delivery | `Omnibus26ReleaseClosureTest`, `SchedulerRuntimeClosureTest` |
| CacheLayer outbox/webhook replay keys | Rollback zero event, committed event once, replay dedupe across nodes | `CacheLayer4PdoOutboxIntegrationTest`, `CacheLayer33AtomicIntegrationTest` |
| Pathwise/object storage | Retain byte hashes and paths; verify ACL/traversal safeguards | `FilesystemPathwise41TrustBoundaryTest` |

**Evidence log:** source/target SHA, lock hashes, signed backup manifest and restore hash, actual migration IDs, per-family before/after counts, token incompatibilities/revocations, replay outcomes, DB version, restore run time, encrypted key IDs (not secrets), and CI. Mark absent services **not applicable with reason**. `OAuth21SchemaUpgradeTest` starts from a 2.0-shaped schema, while `OAuth21SchemaRollbackTest` runs transactional SQLite rollbacks. Neither is a complete production 2.1.1 snapshot restoration. A data restore is an independent Batch 5 acceptance prerequisite.
