# Infbyte documentation development plan

Status: **in progress** (documentation work on Foundation PR #20).
Owner repository: `infocyph/Foundation`.
Branch: `feature/foundation-3`.
Documentation identity: **Infbyte — PHP Application Framework**.
Primary format: reStructuredText (RST), built with Sphinx and published by Read the Docs.
Reference implementation: `infocyph/InterMix` (`.readthedocs.yaml`, Sphinx Book Theme, PHP syntax support and nested guides).

## Purpose and non-negotiable boundaries

Build an approachable, Laravel-style *learning experience* for the Infbyte application framework without copying Laravel APIs or implying that unsupported features exist.

- Documentation **lives inside Foundation**, but is branded and presented as **Infbyte**. Foundation remains the composer package supplying composition, lifecycle and application policy; Infbyte is the application skeleton.
- Ground commands, class names, code examples, module IDs, configuration keys and host guarantees in the actual Foundation 4 source, its tested integration contracts and the matching Infbyte skeleton. Never invent facades, controllers, models, templating/ORM facilities or automatic discovery.
- The current Infbyte `main` application skeleton requires Foundation `^3.0.1`; PR #20 targets unpublished Foundation 4. Mark the 4.x docs **development/pre-release** until Foundation 4 and the compatible Infbyte skeleton are published. Do not claim `composer create-project` currently installs Foundation 4.
- Preserve specialist ownership: InterMix (DI), Webrick (HTTP), Runwire (host), DBLayer (database), CacheLayer (cache), Omnibus (messaging), ReqShield (validation), Pathwise (files), Epicrypt (crypto), TalkingBytes (communication), OTP (factors) and other native packages. Link to native manuals where advanced detail belongs.
- Preserve the existing Foundation engineering guides while migrating their relevant material. Do not prematurely delete release, migration, recovery or performance certification material.
- Do **not** merge/tag/release PR #20. Documentation changes must not weaken its application/security/performance qualification gates.
- One finished documentation batch per commit. Update this tracker in the **same batch commit**, recording evidence and remaining work. Avoid unrelated PHP/runtime edits.

## Content design rules

1. Start each primary guide with what/why/when, prerequisites and a minimal correct example, followed by step-by-step development, failure and security considerations, related guides, and production notes when relevant.
2. Keep beginner material task-oriented; put ownership, compilation and host internals in advanced or internals chapters. Show four explicit runtimes only where useful.
3. Use exact, runnable PHP 8.4+ examples with `declare(strict_types=1);` for full PHP files; do not show pseudocode as working code.
4. Explicitly label features requiring an optional qualified module or a native host. Name the selecting module/feature and supported environment.
5. Guide navigation must be discoverable through Sphinx `toctree`; use stable labels and explicit cross-references. Keep source files in RST, with MyST for still-maintained Markdown technical guides.
6. Prefer tested, minimal examples. Where an end-to-end Infbyte example depends on the downstream skeleton's 4.x migration, mark it pending and verify when available.
7. Do not allow ambiguous release claims or inappropriate comparisons with Laravel; the inspiration is usability, not feature parity.
8. Keep theme overrides small, accessible, and subordinate to the standard Sphinx Book Theme.

## Planned sections

- **Introduction & getting started**: overview, requirements, version status, installation, project layout, configuration, local server and first route/controller.
- **Architecture**: application lifecycle, request lifecycle, services, providers, contexts and native ownership.
- **HTTP fundamentals**: routing, controllers, middleware, request/response, validation, errors and resources.
- **Security**: auth, authorization, browser sessions, CSRF, passwords, tokens, OAuth/OIDC, MFA and passkeys.
- **Data & services**: DBLayer queries/transactions/migrations, cache, filesystem, events, jobs, queues, notifications, mail and outbound communication.
- **CLI, scheduling & testing**: generators, commands, schedules, workers, HTTP/unit/service tests.
- **Advanced & deployment**: modules, release compilation, performance, Runwire, hosting, operations, troubleshooting, retained-state rehearsals.
- **Reference & internals**: CLI/configuration/module contracts, public APIs, architecture, migration, qualification and release evidence.

## Batch tracker

| Batch | Scope | Acceptance criteria | Status |
| --- | --- | --- | --- |
| D0 | Plan and review baseline | Plan checked in; constraints and batch gates recorded | **Done** |
| D1 | Documentation infrastructure | RTD config, Sphinx+RST, Infbyte branding, book theme, coherent initial navigation, preserved Markdown references, reproducible strict docs build | **In progress — hosted validation pending** |
| D2 | Onboarding and architecture | Foundation-4-aware versioning, guided installation, directory structure, first request, configuration, app/provider lifecycle; examples validated | Pending |
| D3 | HTTP and security | Focused Webrick routing/middleware/controllers/validation, authentication/authorization and sessions/CSRF guides with explicit modules | Pending |
| D4 | Data and application services | DBLayer/cache/filesystem/Omnibus/TalkingBytes/notification guides split by developer task; native ownership linked | Pending |
| D5 | CLI, testing, advanced/runtime and deployment | End-to-end workflows, credible production/host notes, upgrade and troubleshooting guides, complete quality gates | Pending |
| D6 | Final audit and publication handoff | Navigation/link/example audit, cross-version wording, skeleton compatibility verified, docs preview and publication readiness reviewed | Pending |

Status convention: Pending → In progress → Done/Blocked (include concrete reasons). Do not mark Done on a speculative or unchecked command.

## D1 implementation details

- Add root `.readthedocs.yaml` specifying Sphinx and Python docs dependencies, anchored to the InterMix pattern.
- Add `docs/conf.py`, pinned-compatible `docs/requirements.txt`, `docs/Makefile`, a minimal stylesheet and supporting navigation.
- Create `docs/index.rst` with Infbyte branding and a prominent **Foundation 4 development documentation** warning; include Sphinx-managed existing Markdown technical guides via MyST.
- Write usable landing/onboarding material and a correct version-compatibility note. Keep Foundation 3 installation commands explicitly scoped to the current public Infbyte skeleton.
- Add a docs CI build with warnings treated as errors and avoid changing the heavy runtime/performance gates.
- Prefer no PDF/ePub build until HTML is green. Never advertise site deployment as complete without a successful Read the Docs build.

## Verification per batch

- `python -m pip install -r docs/requirements.txt` in a fresh supported Python environment.
- `sphinx-build -b html -n -W --keep-going docs docs/_build/html` (Sphinx warnings, unresolved references and missing toctree documents must fail).
- Check internal links, navigation and code highlighting; no broken or orphan pages in the published tree.
- Validate developer commands and full PHP examples against the current Foundation 4 branch; use the downstream Infbyte skeleton only after its compatible version is available.
- Require project CI to remain green independently. A docs-only check cannot certify the Foundation 4 release.
- Track commit SHA, documentation build outcome, link status and executable example evidence here for each batch.

## Evidence ledger

| Batch | Commit | Validation/evidence | Notes |
| --- | --- | --- | --- |
| D0 | `38c947ef9e3596674bada66ae1271c6549c02145` | Review of PR #20, InterMix docs build, and current Foundation/Infbyte docs | Documented Foundation 3 skeleton vs Foundation 4 pre-release mismatch |
| D1 | This batch commit | Added strict Sphinx CI job; hosted result pending; local Python Sphinx unavailable in isolated environment | Do not mark complete until docs build passes |

## Known risks / decisions

- **Mismatch:** Infbyte `main` currently depends on Foundation `^3.0.1`; avoid claiming production Foundation 4 onboarding works with it. After Foundation 4 is published, align the skeleton in a separately tracked downstream change.
- **Sphinx MyST:** Existing Markdown files were authored as GitHub docs. Validate link syntax and toctree inclusion; convert incrementally, not mechanically.
- **Reference docs:** Native package internals evolve independently. Explain supported public integration contracts, link to upstream ownership and pin examples to validated versions.
- **Read the Docs:** A local Sphinx build validates output but is not proof that the hosted project is connected or published.
- **Release readiness:** PR #20 has separate unfulfilled sustained host-qualification gates. Do not mark the framework as released based on docs CI.

## Immediate next action

Verify D1's strict Sphinx CI build and fix warnings if any; mark D1 Done only after green evidence, then begin D2.
