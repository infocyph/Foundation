# Foundation 3 — UID 5.0 utilization evidence

This document records the concrete evidence for section 26.2 of `foundation-3-final-runtime-development-plan.md`.

## Ownership result

No UID 5.0 source change is required.

UID remains the owner of generic identifier mechanics: generation, encoding, validation/parsing, monotonic algorithm state and fork-state reset. Foundation remains the owner of correlation lifecycle and semantic identifier policy.

Foundation's default non-web execution fallback is `Runtime\ExecutionId::generate() -> Infocyph\UID\Id::ulid()`.

UID's default ULID mode is monotonic. Foundation therefore gets a compact 26-character, lexicographically sortable execution fallback without introducing machine IDs, filesystem sequence coordination or Foundation-owned generator state.

Externally supplied execution/correlation identifiers remain arbitrary non-empty Foundation correlation strings. They are preserved byte-for-byte and are not normalized into ULIDs merely because Foundation's fallback uses ULID.

## Classified Foundation identity/randomness boundaries

| Boundary | Classification | Final ownership/decision |
| --- | --- | --- |
| `Runtime\ExecutionId::generate()` | execution correlation fallback | UID monotonic ULID generation; Foundation lifecycle wrapper |
| supplied worker/scheduler/command/message IDs | upstream execution correlation | reuse verbatim; never generate a second ID |
| nested command execution | inherited execution correlation | reuse active `ExecutionId` |
| Omnibus message execution | message correlation | preserve normalized authoritative message ID (`omnibus:<message-id>`) |
| scheduler execution | scheduled-run correlation | exactly one generated `ExecutionId` per logical run; reuse in scope/history/events |
| auth/public IDs through `UidAuthIdGenerator` | domain/public identity | Foundation selects UUIDv7 or ULID policy; UID performs generation |
| release generation directory identity | release/build identity | Foundation timestamp + cryptographic entropy format remains build-plane policy |
| `.staging-*` suffixes and test/benchmark temp directories | transient temp entropy | native cryptographic randomness remains appropriate; do not route through UID |
| release/config/router/container digests | artifact/trust fingerprints | cryptographic hashes remain deterministic trust identities; never replace with UID randomness |
| security secrets/nonces/tokens | cryptographic security randomness | security primitive owner remains responsible; UID is not a secret generator |
| DI aliases/provider IDs/scope names | deterministic graph topology | semantic/stable names; never randomize |

No Foundation boundary discovered during the UID pass requires Sonyflake/TBSL/NanoID/ObjectID/RandomId solely for API uniformity.

For `ExecutionId`, ULID is preferred over UUIDv7 because the Foundation boundary needs a compact sortable correlation identifier rather than an externally standardized UUID shape. ULID keeps 128-bit identifier space and sortable time semantics while reducing the canonical textual form from 36 characters to 26. Sonyflake and sequenced TBSL are intentionally not used for this boundary because their coordinated machine/sequence model adds operational state that Foundation execution correlation does not require.

## Correctness evidence

Existing Foundation coverage already proves:

- nested command execution inherits the active execution identity;
- Omnibus-backed execution propagates the message-derived correlation identity;
- interleaved Fiber executions preserve supplied identities without cross-contamination;
- persistent Omnibus/worker execution does not leak the previous unit's identity;
- scheduler overlap/cancellation history reuses one execution identity across the logical run;
- `UidAuthIdGenerator` delegates Foundation auth ID policy to UID primitives.

`tests/Feature/UidRuntimeBoundaryTest.php` adds direct section-26.2 contract coverage for:

- Foundation-generated fallbacks being valid 26-character ULIDs;
- consecutive generated fallbacks preserving monotonic lexical order;
- independent fallback generation producing distinct values;
- arbitrary externally supplied correlation values being preserved byte-for-byte;
- independent worker units receiving fresh fallback identities;
- independent scheduler units receiving fresh fallback identities;
- supplied worker/scheduler identities being preserved;
- a successful scheduler run using one ULID identity across `pending -> running -> succeeded` history records;
- UID ULID fork-state uniqueness when `pcntl_fork` is available, without Foundation adding process-local generator state.

## Performance evidence

`benchmarks/uid-runtime-utilization.php` is the Foundation attribution benchmark for this pass and is exposed as:

```text
composer benchmark:uid
```

It emits the PHPForge representative-benchmark schema to:

```text
build/uid-5-runtime-benchmark.json
```

The benchmark measures:

1. raw UID UUIDv7 generation as the previous-format comparison;
2. raw UID monotonic ULID generation;
3. the Foundation `ExecutionId::generate()` ULID wrapper;
4. worker execution with a caller-supplied identity;
5. worker execution with generated ULID fallback;
6. scheduler execution with a caller-supplied identity;
7. scheduler execution with generated ULID fallback.

Nested correlation reuse is intentionally not microbenchmarked by recursively entering `ExecutionScope` from inside an active worker scope. InterMix correctly rejects duplicate activation of the same scope; nested identity reuse is a higher-level command/message lifecycle behavior and remains covered by the existing lifecycle tests instead.

The active PHPForge workflow benchmark gate uses `benchmark:uid`. The ArrayKit completion evidence and prior successful CI run remain recorded in the canonical plan.

## Production-code decision

Foundation now uses UID monotonic ULID for generated execution fallback while preserving the same ownership split:

```text
UID 5.0
  generic generation / validation / parsing / algorithm state

Foundation 3
  semantic wrapper / algorithm policy / upstream reuse / lifecycle propagation
```

In particular, do not:

- validate every incoming `ExecutionId` as a ULID;
- regenerate message/job/command/scheduler identities that are already authoritative;
- replace release/staging entropy with UID merely to remove `random_bytes()`;
- replace deterministic artifact digests with random identifiers;
- introduce Sonyflake/TBSL sequence coordination for a correlation boundary that does not need it.

## Completion condition

Section 26.2 can be marked complete after the current-head PHPForge matrix passes the ULID correctness tests and validates `build/uid-5-runtime-benchmark.json`. At that point the canonical section-26.2 checklist/tracker should be reconciled to completed state and this evidence linked from the plan.
