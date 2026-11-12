# Infbyte documentation development plan

Status: **internal documentation work complete** (D0–D8-B); publication externally blocked and Foundation PR #20 remains draft.
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
| D1 | Documentation infrastructure | RTD config, Sphinx+RST, Infbyte branding, book theme, coherent initial navigation, preserved Markdown references, reproducible strict docs build | **Done — hosted Sphinx job passed** |
| D2 | Onboarding and architecture | Foundation-4-aware versioning, guided installation, directory structure, first request, configuration, app/provider lifecycle; examples validated | **Done — strict docs job passed** |
| D3 | HTTP and security | Focused Webrick routing/middleware/controllers/validation, authentication/authorization and sessions/CSRF guides with explicit modules | **Done — strict docs job passed** |
| D4 | Data and application services | DBLayer/cache/filesystem/Omnibus/TalkingBytes/notification guides split by developer task; native ownership linked | **Done — strict docs job passed** |
| D5-A | CLI, scheduling, worker lifecycle and testing | Detailed RST tutorials, real command/schedule contracts, PHP examples and test doubles | **Done — documented PHP fixed, full QA subsequently passed** |
| D5-B | Runtime, production deployment and operations | Production hosting, releases, performance, rollback, readiness and troubleshooting | **Done — Sphinx, Psalm and PHPForge QA passed** |
| D6 | Final audit and publication handoff | Navigation/link/example audit, cross-version wording, skeleton compatibility verified, docs preview and publication readiness reviewed | **Docs QA passed; blocked on external compatibility/publication gates** |
| D7-A | Detailed application tutorials | Four source-verified, task-oriented, cross-linked tutorials: validation, browser sessions, migrations, durable queues; strict Sphinx/PHP lint | **Done — exact-head documentation CI passed** |
| D7-B | Advanced developer recipes and crosslinks | Four more detailed task guides (JSON APIs, feature lifecycle, testing, release/rollback) and links from core reference pages | **Done — exact-head documentation CI passed** |
| D8-A | Public API and configuration reference | Verify current source signatures and add structured reference for application, providers, HTTP/auth, console/scheduling/testing, and configuration | **Done — Sphinx and PHP lint passed** |
| D8-B | Consistency, link and example audit | Automated public-API drift guard, internal navigation and version truth audit; final publication blockers recorded | **Done — exact-head strict QA passed** |

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
| D1 | `6df4619ee9e0fd210700eca79060476c4dcbef72` (correction after first D1 commits) | **Success:** strict Sphinx job `131277336677` / workflow `43245447050` | All earlier title, Markdown toctree, cross-reference and highlighting warnings excluded or resolved; engineering guides retained. |
| D2 | `10418cb345d70ac6cf02ba05b7acf200ffecd369` | **Success:** strict Sphinx job `131283174224` / workflow `43247106040`; verified provider contract and source examples | Eight new onboarding, architecture and PHP example files; PHP 8.4 provider syntax check in docs job. |
| D3 | `1d7ed1b069685f2df9acfe422d611c28ce3d2d29` | **Success:** strict Sphinx job `131286418314` / workflow `43248110945` | Nine RST HTTP/security guides and version-accurate native ownership references. |
| D4 | `95cbccc171299094d5057a6b026fe7fec328733a` | **Success:** strict Sphinx job `131289281083` / workflow `43248911748` | Eight RST data/service chapters and native ownership links. |
| D5-A | `9ef4e41ed6faddf9eae7fc2a6b2f8a1743b49625`, correction `3bca6676613fe438121f85c68b12e0ce8e93a002` | Sphinx jobs `131314992272` and `131321699842` **passed**; follow-up full PHPForge analysis/QA matrix verified on D5-B SHA. | Three docs-only PHP examples converted to linted `.php.txt` assets; no analyzer suppressions. |
| D5-B | `1e138ff22f725a9cdf16177fb609061e851e5639` | **Success:** strict Sphinx `131324845876`; PHPForge analysis `131324970467`, `131324970504`; QA `131324970470`, `131324970488`, `131324970560`, `131324970578` (PHP 8.4/8.5, both dependency policies) | Ten detailed runtime/deployment/security/reference guides; no failed jobs in verified matrix. |
| D6 | `f84e46be9c4109a6fdf4d0ebb773d7d6224e78ea` | **Success:** strict Sphinx and PHP 8.4 examples + full RST PHP-block lint, job `131329749069` / workflow `43260300927` | Maintainer guide and automatic complete-PHP syntax audit landed; externally hosted RTD and compatible Infbyte 4 skeleton not yet verified. |
| D7-A | `946c61fccccfb80f3597029b09cf0c6b1fd5e184` | **Success:** Sphinx / PHP block lint documentation job `131337191338` in Security & Standards run `43262417970`; no failed jobs observed | Four source-verified 200–325-line walkthroughs, no changes to runtime PHP. |
| D7-B | `7a3fc6cac8776bbd27f1590d41b5ba3d80ffd90f` | **Success:** strict Sphinx / complete-PHP-block validation job `131343691579`, Security & Standards run `43264259735`; no failed jobs in inspected result | Four detailed workflows and eight chapter crosslinks; runtime PHP unchanged. |
| D8-A | `e6f86469304e078be04925debb7d3a8193f25921` | **Success:** Infbyte Sphinx job `131366938794` on Security & Standards run `43270866746` | Four source-checked API/config reference pages; PHP example syntax also verified. |
| D8-B | `996bc3f507be0585358d3920cea7023a6b881596` | **Success:** documentation job `131370206001` / Security & Standards `43271797475`: Sphinx strict build succeeded; 118 methods, 12 templates, 3 groups/8 features, 38 complete PHP blocks, zero drift or syntax errors | Read the Docs hosting, Infbyte 4 starter and Foundation release qualification remain external blockers; linkcheck/consumer runtime tests are not represented by this job. |

## Known risks / decisions

- **Mismatch:** Infbyte `main` currently depends on Foundation `^3.0.1`; avoid claiming production Foundation 4 onboarding works with it. After Foundation 4 is published, align the skeleton in a separately tracked downstream change.
- **Sphinx MyST:** Existing Markdown files were authored as GitHub docs. Validate link syntax and toctree inclusion; convert incrementally, not mechanically.
- **Reference docs:** Native package internals evolve independently. Explain supported public integration contracts, link to upstream ownership and pin examples to validated versions.
- **Read the Docs:** A local Sphinx build validates output but is not proof that the hosted project is connected or published.
- **Release readiness:** PR #20 has separate unfulfilled sustained host-qualification gates. Do not mark the framework as released based on docs CI.

## Public API and documentation closeout (D8)

- D8-A: reference the current Foundation 4 method and configuration contracts accurately, retaining links to owner package APIs and separating Foundation 3 starter code from unreleased Foundation 4 examples.
- D8-B: implement a lightweight source-API drift guard against documented public names; review RST navigation, version warnings, unsupported/ambiguous commands and publication requirements. No premature Infbyte-skeleton upgrades or RTD publication claims.

## Detailed tutorial expansion (D7)

D6's *internal* documentation gates passed, but publication still depends on a released compatible skeleton and externally hosted version. D7 adds instructional depth without altering those release gates.

- D7-A: four complete workflow tutorials with decision points, exact native ownership, source-grounded code snippets, safety constraints, integration-test expectations and failure diagnoses.
- D7-B: add full workflows for JSON resources/errors, qualified feature lifecycle, testing, and release/rollback; connect short HTTP, security, data, services, testing and deployment references to their corresponding tutorials. Review against Foundation 4 public contracts without implying that the Foundation 3 starter has been migrated.

## Immediate next action

D7-A strict Sphinx/PHP-block validation passed. All internal D0–D8-B documentation work and exact-head Sphinx/public-method/feature/PHP-block checks passed. The next action is an **external publication handoff**, not an unverified documentation code change: release/verify the matching Infbyte 4 skeleton, complete the independent Foundation 4 exact-source/host/retained-state gates, connect and verify an actual Read the Docs Infbyte project, and test representative consumer examples and external links against the published versions. Do not merge/tag/release from documentation evidence alone. D6 publication remains **externally blocked** until the compatible Infbyte 4 skeleton, released Foundation 4 contract, hosted Read the Docs project/version and Foundation release qualification have been verified. Do not merge, tag or publish from this tracker alone.
