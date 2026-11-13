# Infbyte documentation development plan

Status: **Infbyte manual authoring and core documentation QA complete through D15-A; D15-B final source/version/publication audit in progress.** Hosting, full downstream Infbyte CI and Foundation 4 release qualification remain independently gated.
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
| D9-A | Existing Infbyte 4 candidate discovery | Actual candidate installation/source guide, corrected version/publication status, cross-repository evidence and documentation navigation | **Done — strict Sphinx QA passed** |
| D9-B1 | Current-source Infbyte candidate probe | Read-only Foundation PR workflow resolves Infbyte 4 candidate on PHP 8.4/8.5, verifies exact Foundation SHA, Core module inventory, Webrick/TestKit health and trusted release readiness | **Done — both PHP 8.4/8.5 jobs passed** |
| D9-B2 | Infbyte candidate remediation | Refresh full 13-job candidate QA and repair drift directly in Infbyte PR #11 | **Open — Infbyte PR #11 full CI failing; read-only Foundation compatibility is green but not an equivalent release gate** |
| D9-C | Stable release/Read the Docs hosting | Foundation P6 and exact SHA qualification, published ^4.0 Composer cutover, externally hosted RTD build/version routing | **Blocked — external release/publication prerequisites** |
| D10-A | Infbyte-first paths and config depth | Verify exact Infbyte 4 config files, rewrite setup/config reference/project structure with examples and production advice, correct related guides | **Done — strict Sphinx and app-path contract QA passed** |
| D10-B | Laravel-depth user guides | Expand route/controller and task-oriented application chapters with real sample code, middleware, tests, pitfalls and production treatment | **Done — strict Sphinx/PHP docs QA passed** |
| D10-C | Project-path and public API audit | Scan all developer RST pages for misleading Foundation-internal paths; verify docs against Infbyte candidate source and strict Sphinx/PHP lint | **Done — strict docs and cross-repo CI passed** |
| D11-A | Developer-oriented data and storage guides | Detailed application-path-first database, cache, and filesystem guides: selection, native usage, security, performance, tests, troubleshooting | **Done — strict docs QA passed** |
| D11-B | Detailed messaging and communication services | Deepen notifications, TalkingBytes communication, Omnibus events/jobs, queue workers, deployment/failure semantics and app-owned config | **Done — strict docs QA passed** |
| D11-C | Detailed security and validation | Deepen authentication, authorization and request validation from actual Infbyte config and public PHP contracts | **Done — strict documentation QA passed** |
| D12-A | Browser session and middleware developer recipes | Infbyte-first route-selected web/session/CSRF/authorization lifecycle, config ownership, tested examples and production state coordination | **Done — strict documentation QA passed** |
| D12-B | MFA/passkeys and production security guide depth | Detailed optional feature selection, enrollment/verification boundaries, recovery, replay, testing, and operations | **Done — strict documentation and candidate CI passed** |
| D13-A | Application service providers | Infbyte bootstrap/providers.php, native InterMix DI examples, per-runtime group selection, scoping, compilation, verification, troubleshooting | **Done — strict docs and Infbyte candidate CI passed** |
| D13-B | Application test strategy and native fakes | Infbyte tests/ structure, real health TestKit fixture, selected optional TestKit helpers, fake/real transport boundaries, failure-path, persistent-host and release tests | **Done — strict docs and Infbyte candidate CI passed** |
| D14-A | CLI commands and scheduler walkthroughs | Deepen actual Infbyte routes/console.php and routes/schedule.php, command definitions, schedules, locking, job/worker distinction and production checks | **Done — strict Sphinx / complete PHP documentation QA passed on exact head** |
| D14-B | Core onboarding and lifecycle expansion | Explain application creation, developer setup, first route, application/request runtime lifecycle, exact Infbyte project paths and failure diagnoses | **Done — strict Sphinx and Infbyte candidate CI passed** |
| D15-A | Operational, testing and service guide sweep | Deepen short logging, database testing, worker operations, persistent-host guidance and deployment/advanced chapters | **Done — strict Sphinx and Infbyte candidate CI passed** |
| D15-B | Final consistency and publication readiness audit | Scan every RST/link/filename/CLI/API/example, run strict Sphinx/complete-PHP, cross-repo Infbyte candidate CI, close tracker and flag external release gates accurately | **In progress — exact-head CI pending; external link/hosting remains publication-gated** |
| D12-C | Native HTTP middleware and requests/responses depth | Expand developer-facing Webrick middleware/request/response chapters with actual Infbyte routes, auth/CSRF policy, typed request inputs, immutable responses, host tests and production gotchas | **Done — strict docs and Infbyte candidate CI passed** |

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

## D9 — Publication and cross-repository compatibility handoff (2026-11-12)

**Findings:** An unpublished Infbyte 4 candidate **already exists** in
`infocyph/Infbyte` PR #11, branch `feature/infbyte-4`, at
`cf91ad9e17307e4325cbabd09000eb73abb835d5` before this verification.
Its development Composer manifest requires Foundation
`dev-feature/foundation-3` with a GitHub VCS override. Public
Infbyte `main` still uses Foundation `^3.0.1`. Foundation PR #20
head at discovery: `94966baa0c4ff0d0c6c1f5144b87a8a61c951cd0`.

- **Historical success:** Infbyte PR #11 candidate workflow
  `39106180694` passed **13/13** jobs on October 18, against an
  **older** Foundation dev branch source. It does not requalify
  the current Foundation branch.
- **Preserved policy failure:** Infbyte Security & Standards workflow
  `39106181013` reported four PHPForge QA failures, all from the
  enforced stable-runtime constraint rejecting
  `dev-feature/foundation-3`. Do not hide or rename these checks.
- **Concrete drift:** Infbyte's `Foundation4ModuleIntegrationTest.php`
  asserts DBLayer `^6.1.1`; the latest Foundation 4
  `ModuleArchitectureCatalog` declares `^6.1.4`. Requalify against
  the selected catalog rather than pin outdated expectations.
- **Read the Docs:** no verified hosted Infbyte URL/project build was
  found; absence from search is **not** proof that an admin project
  does not exist. Connection, version route and final build remain
  unverified.
- **Write barrier:** The connected GitHub integration returned HTTP
  403 `Resource not accessible by integration` for both a tree write
  and a contents-create attempt against Infbyte's `feature/infbyte-4`.
  No changes were pushed to Infbyte PR #11 in this turn. Grant the
  GitHub integration write access before attempting Infbyte D9-B.

**D9-A** updates the Infbyte manual inside Foundation with a real
candidate-oriented development route, without claiming an installable
stable version. D9-B must capture exact resolved Foundation SHA in
fresh Infbyte candidate CI, fix stale test expectations, exercise
TestKit against the registered Webrick health endpoint, and run all
13 candidate jobs. The strict published-package guard remains unchanged
until Foundation 4 is truly published. D9-C remains blocked by
Foundation's independent P6 performance and final-release qualification,
real Composer `^4.0` cutover, and hosted Read the Docs verification.

No PR merge, tag, release, or Read the Docs publication is authorized
by this evidence alone.

### D9-A and D9-B1 execution record

- **D9-A:** Foundation docs commit `15c4bc3e2cda175965e91b955838a6baf03d8045`, warning fix `1778a766544033e67b94a76359297863edc52d13`. Strict Sphinx job `131384164123` in Security & Standards workflow `43275859180` **passed**. The original warnings were two heading adornments and one malformed inline literal (reported four times by Sphinx).
- **D9-B1 (this commit):** Add a standalone **read-only** Foundation PR workflow that checks out Infbyte `feature/infbyte-4`, resolves its genuine development dependency on PHP 8.4/8.5, requires the Composer-installed Foundation source SHA to equal the current Foundation PR head SHA, checks the three-group/eight-feature catalog, tests the real Webrick health route through Foundation TestKit, and verifies Core compiled-release manifest integrity. This is supplemental current-source evidence; it is **not** the complete 13-job Infbyte acceptance matrix or the stable-runtime release gate.
- **D9-B2:** Infbyte PR #11 source mutations and GitHub Actions reruns were both rejected by the connected app with 403. The affected stale DBLayer `^6.1.1` test versus Foundation `^6.1.4` remains **unresolved in Infbyte**. No branch writes or CI reruns were claimed there.
- **D9-C:** Foundation's full P6 acceptance, stable Composer release/cutover, hosted Infbyte Read the Docs version and all native consumer examples remain prerequisites.

### D9-B1 validation closure

- Foundation commit `d278059502db1e48cf825328d1a5b2582a8f0770` added the cross-repository **read-only** candidate workflow.
- Workflow `43276200146` passed **both** PHP 8.4 and PHP 8.5 jobs: `131385131893` (8.4) and `131385131142` (8.5).
- Both jobs' Composer lock references matched the exact Foundation PR head `d278059502db1e48cf825328d1a5b2582a8f0770`. Module topology, Infbyte Webrick health/TestKit dispatch, and trusted Core release manifest integrity passed. These checks were executed from Infbyte `feature/infbyte-4` in a read-only checkout.
- The **full Infbyte 13-job matrix was not rerun**; the October 18 success remains historical only. Four PHPForge stable-runtime failures on Infbyte's development branch are policy-expected until a real `^4.0` cutover. Infbyte branch source fixes remain blocked by 403 GitHub integration write permission. No stable release or hosted Read the Docs publication was performed.

## D10 — Infbyte application documentation correction (2026-11-13)

**Editorial direction:** This is an **Infbyte developer manual living inside Foundation**, not a Foundation internal-source manual. Every ordinary `config/`, `app/`, `routes/`, `bootstrap/`, `storage/` example must describe the Infbyte project checkout. Never claim that Foundation's `resources/config/` is the location to edit application configuration.

**Source verified:** Infbyte `feature/infbyte-4` ships exactly six Core application config files: `config/app.php`, `auth.php`, `cache.php`, `modules.php`, `notifications.php`, `router.php`; optional feature configuration such as `config/database.php` is absent until requested. Foundation `resources/config/` provides internal templates, not user-editable project config.

**D10-A:** replace the previously short getting-started configuration, project directory and configuration-reference chapters with detailed source-grounded explanations, representative PHP/CLI examples, optional-feature publication, debugging, security, production and rollback notes. Amend the database walkthrough and maintainer guidance. Keep source links for maintainers explicitly marked internal.

**D10-B:** use verified Infbyte route/controller examples and the real file layout to expand the onboarding/HTTP and testing instructions; add realistic success/failure paths rather than only listing methods. Full examples must pass PHP syntax lint and no unsupported Laravel facades, Eloquent or Blade APIs may be claimed.

**D10-C:** review all RST chapters for misleading paths and stale source assertions; keep independent external Infbyte/Packagist/hosted RTD publication gates open.

**Infbyte PR #11 access restored** during D10: commit `d4dc214dce55e8c0217dd39a24c1e50878e0320f` added a native TestKit/health smoke, corrected stale DBLayer planner fixture, and CI source provenance to the existing candidate branch. Its fresh full QA has new failures; do not claim green until those are resolved. No merges or releases.

### D10-A validation and D10-B scope

- **D10-A** source commit `eda1c7a94354c6c9dd6bf6b4bde122bccc843cbd`, heading correction `dcf779b004bd586e19a6891c0fe474279509c0ed`, and stronger application-path contract guard `31d2bd73ef4ad713b5e149b9df3fc459ed6d1c3f`. Strict Sphinx and documentation public-contract checks **passed** in doc job `131650619734` (Security & Standards `43353677084`); the guard checks `config/<file>` application targets alongside Foundation-owned templates.
- **D10-B (this batch):** replace short onboarding/routing/controller/HTTP testing pages with detailed task-first examples grounded in real Infbyte `feature/infbyte-4` files; cover new routes and controllers, CLI registration, in-process TestKit, 404/405, middleware and auth boundaries, production compiled routing, file placement, performance and troubleshooting. Each developer-created route is explicitly labeled and not claimed as preinstalled. Strict Sphinx and complete PHP block lint required before closing.
- **Independent Infbyte CI:** write access restored, and Infbyte PR #11 commit `d4dc214dce55e8c0217dd39a24c1e50878e0320f` triggered fresh matrix. Candidate PHP 8.4/8.5 core and PHPForge jobs reported failures beyond the previous stable guard, including analysis of generated `storage/releases/` products. Do not mark full consumer integration green or suppress QA. Address in independent Infbyte remediation batches.

### D10-B validation and D10-C placement audit

- **D10-B:** `fe178147dacc1d21f2087d30dcd58644240f232a` rewritten first-application, first-route, HTTP routing/controllers and HTTP TestKit chapters. Strict Sphinx/PHP documentation job `131652936888` in workflow `43354369553` **passed**. The registered `/api/health` endpoint is sourced from Infbyte; example `/api/welcome` and `/api/search` routes are explicitly application-created.
- **D10-C (this batch):** expand the Infbyte 4 development-server guide; correct optional feature config wording in modules; check remaining RST chapters for misleading `resources/config/` references, stale published-starter assertions and application path ambiguity. Add an independent CI step in the read-only candidate integration workflow verifying the six Core config files and actual routes/controllers/entrypoints exist in Infbyte; verify optional feature config files are absent in the Core-only starting skeleton. This is **real cross-repository layout validation**, not a static documentation-only assertion.
- **Current publication gates remain:** Foundation 4 P6 exact-source/host qualification, fresh green Infbyte 4 full matrix and stable `^4.0` cutover, and hosted Read the Docs version routing. Documentation CI cannot waive those gates.

## D11 — Laravel-depth data and service manual (2026-11-13)

### D10-C closeout

- **Commit:** `904d227aa1b651099333e2f9da393b4f7a772971`.
- **Passed:** strict documentation/Sphinx job `131654822648` in Security & Standards `43354957754`; read-only Infbyte candidate compatibility `43354957265` passed PHP 8.4 and PHP 8.5. The cross-repository check asserts the actual six Core config files, selected route/controller/entrypoint files and absence of optional configs in an untouched Core-only Infbyte checkout.
- **Source-ownership rule:** every ordinary `config/`, `routes/`, `app/`, `bootstrap/`, `storage/` reference is relative to the **Infbyte project root**. Foundation's `resources/config/` must be labeled only as the underlying framework template source.

### D11-A implementation (this batch)

- [x] Rework `docs/data/cache.rst` from short architecture notes into a detailed **Infbyte `config/cache.php`** guide: actual Core configuration, CacheLayer PSR SimpleCache `get/set` and `remember`, named stores and locks, shared versus process-local backend selection, schema CLI, performance, tests and diagnostics.
- [x] Rework `docs/data/filesystem.rst` into a detailed **optional `data.filesystem` / Infbyte `config/filesystem.php`** guide: actual Pathwise disk roots, native `StorageRegistry`, bounded uploads/downloads, malware policy, public link management, safe persistent hosting and tests.
- [x] Rework `docs/data/database.rst` into a detailed **optional `data.database` / Infbyte `config/database.php`** guide: source-derived connection/migration/seed settings, native DBLayer ownership, CLI inspection, transactional safety, schema provision, deployment and rollback.
- [x] Verify the D11-A exact-head strict Sphinx build, application-path/public-method guard and full PHP block lint before marking Done. Do not conflate documentation QA with Foundation release qualification or current Infbyte full QA.

### D11-B follow-up scope

Deepen the remaining short user-facing services guides using actual Infbyte app paths and relevant specialist APIs. Do not add unsupported Laravel features. Retain Foundation technical guides only as explicitly labeled maintainer references. Keep both PRs draft and unmerged; D11 does not waive the independent Foundation P6 or Infbyte stable-cutover gates.

### D11-A validation and D11-B execution

- **D11-A:** `765b8b03a5df048f5c8537f46aa90524bd3203f2` committed detailed database, CacheLayer and Pathwise chapters. The strict docs/Sphinx job `131657739449` in Security & Standards `43355853411` **passed**. These chapters document true Infbyte project config locations and optional feature ownership, and retain actual native API examples.
- **D11-B (this commit):** rewrite `docs/services/communication.rst`, `notifications.rst`, `events-jobs.rst` and `queues-workers.rst` as substantive task-oriented developer guides. Explain that `config/notifications.php` ships in Infbyte Core, while `config/communication.php` and `config/messaging.php` appear only after selecting optional features. Show sourced application/Omnibus/TalkingBytes contracts, the distinction between sync/volatile/durable delivery, safe retries, codec policy, host lifecycle, tests, performance, and failure/operational response. Every non-shipped application class/profile is labeled illustrative. Exact-head Sphinx and PHP example checks are pending.
- **D11-C:** next detailed expansion focuses on auth/security/validation where overview pages remain short; this is documentation, not release approval.

### D11-B validation and D11-C execution

- **D11-B:** `0b214e1a0058bf1a5edc8d58f238809ca4e87eca` expanded communication, notification, events/jobs, and queue/worker chapters with accurate Infbyte configuration paths and production guidance. Strict docs/Sphinx job `131660222759` from workflow `43356593371` **passed**. No runtime PHP code changed.
- **D11-C (this commit):** rewritten, source-checked `docs/security/authentication.rst`, `docs/security/authorization.rst`, and `docs/http/validation.rst` with real Infbyte Core auth settings, optional validation config, typed `AuthServices` and `FormRequest` APIs, security/middleware ordering, typed validation results, production concurrency, test coverage, and troubleshooting. No invented Laravel facades.
- **QA gate:** exact-head Sphinx -W, PHP example lint, public-contract drift and read-only Infbyte PHP 8.4/8.5 compatibility must be checked before declaring D11-C green. Foundation P6 and Infbyte full QA/security-stability still remain independent release gates.

### D11-C documentation QA closure

- **D11-C source:** `c0be0d5abea4986bebbd7654c6e4744ff9206ecd`.
- **Exact-head documentation QA:** Sphinx/public API/PHP example documentation job `131661982863` from Security & Standards run `43357169898` **passed**. This verifies documentation; it does **not** certify the surrounding Foundation security/static-analysis/performance matrix or release.
- **Next developer-focused sections:** browser sessions/CSRF, selective middleware and persistent-host isolation (D12-A); advanced authentication, passkeys/MFA/replay, and production readiness (D12-B). Both should use **Infbyte project paths** and the actual application-owned `config/auth.php`; never direct a developer to edit Foundation `resources/config/`.

### D12-C — HTTP guide enrichment (separate from D12-A/B)

- Expanded `docs/http/middleware.rst` with native Webrick execution order, Infbyte application route options, selected session/CSRF/principal policy, error handling, performance and persistent-host testing.
- Expanded `docs/http/requests-responses.rst` with a full application-owned query-controller example, typed Webrick request accessors, validation/authorization separation, immutable `Response` factories, content negotiation, safe streaming, JSON resources and negative tests.
- All `config/`, `routes/`, `app/`, and `storage/` paths are in the **Infbyte project**, not Foundation package internals. The guides distinguish actual Infbyte starter routes from developer-created examples.
- **Gate:** verify strict exact-head Sphinx, public-contract drift, complete PHP syntax and read-only candidate compatibility. Do not mark published Foundation/Infbyte release gates complete from docs CI alone.

### D12-A — Infbyte browser session and CSRF guide

- Expanded `docs/security/sessions-csrf.rst` as a full Infbyte application tutorial, sourced against `src/Session/BrowserSession.php` and Foundation Core `resources/config/session.php` (template provenance **only**). The starting Infbyte 4 app does **not** ship `config/session.php`; when custom configuration is needed, copy the installed template to the **Infbyte project's `config/session.php`** and edit it there, never in Foundation/vendor.
- Covers route-selected `web` and `web-auth` middleware, browser session API, session fixation rotation, actual CSRF header/body/origin policy, store selection, concurrency/lease coordination, prune CLI, persistent host isolation and negative-path tests. Example controller is explicitly developer-created.
- Exact-head strict Sphinx/public-method contract/PHP syntax and read-only Infbyte candidate compatibility must pass before closing D12-A. This is not Foundation final release qualification.

### D12-C verification recorded

- Commit `183ba0e3eeaa72e0e6101c8427b0edf9f5bd4991` passed exact-head strict Sphinx/public-contract/PHP example documentation job `131663990251` in workflow `43357781203`.
- Foundation's read-only Infbyte candidate workflow `43357780650` passed for PHP 8.4 and 8.5 on the same Foundation SHA.
- Both reports validate documentation/Core compatibility, **not** full Infbyte #11 PHPForge QA, Foundation P6 sustained host certification, a stable Foundation 4 package or hosted Read the Docs publication.

### D12-A QA and D12-B execution (2026-11-13)

- **D12-A:** browser session guide commit `c543aaa1ff4890640dbf2018cd2521ae5ce9741a` (the initial run was cancelled after a newer docs tracker commit); strict Sphinx/public-contract/PHP example job `131664752353` in workflow `43358040818` on later exact Foundation head `e3190144d6f55404f259aff46947f5cfe9e5a7b1` **passed**. This validates the session guide alongside the D12-C HTTP changes; read-only candidate compatibility also passed on the same head.
- **D12-B (this batch):** expand `docs/security/advanced-authentication.rst` and `docs/security/production.rst` into Infbyte application-first guides, source-checking `AuthServices`, `MfaManager`, `PasskeyManager`, actual `config/auth.php` and Foundation's optional feature catalog. Show exact feature selection, OTP challenge/replay, passkey initiation/completion boundaries, recovery, app security settings, trusted release, persistent-host isolation, deployment tests and safe rollback. Examples that require an app-specific ceremony or backend are explicitly contextual.
- **QA:** run strict Sphinx, complete-PHP-block lint, source contract guard, and read-only Infbyte PHP 8.4/8.5 candidate checks on exact D12-B head. These **do not** satisfy Foundation P6 host qualification, Infbyte full PHPForge static analysis, stable ^4.0 Composer cutover or actual hosted Read the Docs publication.

### D12-B validated; D13-A service-provider tutorial

- **D12-B source:** `e0e90c0b9ec98fecce1f01803807f4df3b138f57`. Exact-head Sphinx/public API/PHP example job `131667181175` from Security & Standards workflow `43358779293` **passed**. Read-only Infbyte candidate workflow `43358778875` passed both PHP 8.4 and 8.5. This is docs and minimal Core compatibility evidence only; Foundation release and Infbyte full static-analysis gates remain separate.
- **D13-A (this batch):** expand `docs/architecture/service-providers.rst` with real **Infbyte candidate** `bootstrap/providers.php` group map (common/web/cli/worker/scheduler), app-owned provider file, source-checked Foundation `ServiceProviderInterface` and native InterMix `ContainerBuilder` behavior, runtime selection, lifecycle, testing, security/performance and compiled-generation impact. The existing CI-linted example in `docs/examples/infbyte-clock-provider.php.txt` remains the canonical complete source; do not invent a second container/framework facade.
- **Acceptance:** strict Sphinx and complete PHP example syntax, public method/config path guard and read-only Infbyte PHP 8.4/8.5 on the exact batch commit before closure. Keep Foundation PR #20 and Infbyte PR #11 draft and unmerged; hosted RTD and published 4.x installation remain blocked.

### D13-A validated; D13-B developer testing guides

- **D13-A:** provider guide commit `a38d120b9d2fe31b28f2ed413984c9664c99c545`; exact-head strict Sphinx/public-contract/complete PHP job `131668406378` in workflow `43359159487` **passed**. Read-only Infbyte 4 candidate compatibility workflow `43359159170` **passed** on PHP 8.4/8.5. Other Foundation release, source-qualification and full Infbyte QA jobs are separate.
- **D13-B (this batch):** replace brief `docs/testing/overview.rst` and `docs/testing/fakes.rst` with application-first testing guidance. Source-check Infbyte candidate `tests/` layout and `composer.json`, current Foundation `TestKit::http/fakeCache/fakeHttp/fakeMessaging/fakeNotifications/freezeTime` and the native integration owners. Add a complete Pest health test against the actually registered Infbyte `/api/health` route, intentional negative cases, optional feature prerequisites, fake-vs-real distinctions, execution-scoped fixture isolation, production host and release acceptance. No invented `artisan test` command or claim that a null/fake transport proves delivery.
- **Gate:** require strict Sphinx warnings-as-errors, PHP complete block lint, source contract guard and PHP 8.4/8.5 candidate compatibility on the exact D13-B head. Do not interpret docs QA as stable Foundation/Infbyte release qualification.

### D13-B exact-head QA closure

- **D13-B source:** `96a636dddfbb2410a61ea5484c8c8e9fb506fffa`. Strict Sphinx/public-method/config path/complete PHP block documentation job `131669981937` in workflow `43359652506` **passed**. Read-only Infbyte candidate compatibility workflow `43359652168` passed on PHP 8.4 and 8.5. Documentation remains stored in Foundation and addresses only actual Infbyte project files.
- **D14-A next:** expand the still-short console/scheduling chapters using the Infbyte 4 candidate's real `routes/console.php` and `routes/schedule.php`, Foundation CommandHandlerInterface/Schedule source and existing CI-linted example files. Distinguish an application-defined command from a preinstalled CLI command, show concrete registration, tests, retry/overlap/lock policy, and release compilation. Full static analysis and host qualification remain independent blockers.

### D14-A — Infbyte CLI and scheduling walkthroughs

- **Source verified:** Actual Infbyte 4 candidate `routes/console.php` is an empty application command list; `routes/schedule.php` is an empty `Schedule` callback. Foundation's source-defined `CommandHandlerInterface`, `CommandDefinition::argument/option`, `CommandContext`, `ParsedInput`, `Schedule` and `ScheduledCommand` were checked before documenting code and CLI behavior.
- **D14-A (this commit):** rewrite `docs/console/commands.rst` and `docs/console/scheduling.rst` with explicit application file location, existing CI-linted command/schedule examples, actual registration keys, options, execution scopes, CLI inspection, recurrence, shared CacheLayer locks, supervision, production generation, security, negative tests and troubleshooting. Developer-created `reports:daily` is clearly identified as absent from a fresh Infbyte starter until the user registers it. No Laravel Artisan claim.
- **Acceptance:** strict Sphinx `-n -W --keep-going`, complete PHP example syntax, documented public API/config-path guard and PHP 8.4/8.5 read-only Infbyte candidate integration. This does not waive the Foundation P6 and published Infbyte 4 release gates.

### Tracker reconciliation — November 13, 2026

- **Current source checkpoint:** `156c5d63f4a7e7474b8148eaa93b8f9e6a96cfc6` on Foundation PR #20. D14-A strict Sphinx docs job `131671742661` in workflow `43360201386` **passed**. The same SHA's read-only Infbyte 4 candidate compatibility workflow `43360201049` and native HTTP host smoke `43360201234` **passed**. Full Foundation source qualification is still separately evaluated.
- **Infbyte PR #11:** `d4dc214dce55e8c0217dd39a24c1e50878e0320f` has failing Security & Standards `43352044978` and Foundation 4 Candidate Integration `43352044897` runs. Previous recorded plugin 403 permissions failure is no longer treated as the sole blocker. Successful *read-only* cross-repository checks on Foundation do **not** erase those failures. Do not present the Infbyte app as released or the whole consumer suite as green.
- **Remaining docs batches:** D14-B (developer onboarding/lifecycle), D15-A (short operations and testing chapters), D15-B (strict full navigation/content/example/path/publication audit). Finish each batch with a single commit, a synchronized plan row and exact-head CI proof. Keep separate Foundation P6 and downstream Infbyte release gates open until genuinely qualified.

### D14-B — Infbyte developer onboarding and lifecycle expansion

- Expanded `docs/start/overview.rst` and `installation.rst`, distinguishing published Foundation 3 from the development Infbyte 4 candidate and documenting real `my-app/` entrypoints and the initial Core-only layout. Added a practical install/health/route/config/driver failure matrix.
- Expanded `docs/architecture/application-lifecycle.rst` and `request-lifecycle.rst` with exact candidate `bootstrap/app.php`, `bootstrap/providers.php`, `config/router.php`, `routes/api.php`, CLI/public entrypoints, scoped InterMix runtime ownership, selected middleware, trusted releases, and troubleshooting. Examples sourced against current Infbyte PR #11; no Laravel facade or automatic discovery is claimed.
- Acceptance requires **strict Sphinx warnings-as-errors**, complete PHP syntax, cross-repo Infbyte path/API checks, and current source checks; this work must not make false Foundation 4/Infbyte 4 release claims.

### D15-A — Operational, test and worker guides

- Rewrote application-oriented `docs/services/resources-logging.rst`: source-checked PSR-3 logger, JsonResource contract, Core default `null` logging driver, safe application `my-app/config/logging.php` customization, redaction, file policy and negative tests. Initial Core-only Infbyte candidate does not ship this optional override file.
- Reworked `docs/testing/database.rst` around real `TestKit::database()` / `DatabaseTestManager::begin/rollback/transaction/refresh` contracts, disposable Infbyte test connection, rollback and retained-state scenarios.
- Expanded `docs/testing/persistent-runtimes.rst` with TestKit and true host/cancellation/lease/generation test distinctions.
- Expanded `docs/console/workers.rst` with source-checked application `WorkerProvider::run(WorkerRuntime):int`, `WorkerRuntime::execute()`, maintenance versus Omnibus workers, supervision and recovery.
- Strict Sphinx, complete PHP syntax, path/source contracts and read-only Infbyte candidate compatibility must pass at the exact head. Foundation release qualification remains independent.

### D15-A validation and D15-B final audit

- **D14-B:** `25a0921d72894964f02446e3e6faefd3b1c2dc1e`; strict Sphinx/PHP document job `131678158442` in workflow `43362186547` **passed**, and read-only candidate workflow `43362186226` passed PHP 8.4/8.5.
- **D15-A:** `0f30110ded3f02c2c7d7d44daecfb3e03ae05c8d`; strict documentation job `131679285797` in workflow `43362530815` **passed**, read-only candidate workflow `43362530538` passed PHP 8.4/8.5. Other Foundation host/source qualification jobs are tracked separately.
- **D15-B (this batch):** refresh `docs/start/foundation4-candidate.rst` and `docs/reference/publication-readiness.rst` to remove obsolete October compatibility evidence; show actual `feature/infbyte-4` / `dev-feature/foundation-3` state, the latest passing read-only Core probe, and **still failing** downstream Infbyte PR #11 CI on `d4dc214dce55e8c0217dd39a24c1e50878e0320f`. Add checks to the read-only cross-repo workflow for absence of `config/session.php` and `config/logging.php` in the fresh Core-only app, distinguishing optional Core override templates from shipped application files. Fix 2026 copyright rendering in the Sphinx theme.
- **Final docs QA required:** strict Sphinx HTML with warnings-as-errors, existing local `:doc:` and reference validation, PHP example lint, source-contract guard and Infbyte current-source candidate check on **both PHP 8.4/8.5**. Hosted Read the Docs version alias/external link health and any stable `^4.0` consumer test depend on external release/publication gates and must **not** be marked green from docs CI.
