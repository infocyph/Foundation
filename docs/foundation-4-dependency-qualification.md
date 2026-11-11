# Foundation 4 — resolved dependency qualification record

This is a **candidate** dependency evidence manifest, not a published release
or an assertion that the old and new Composer installations are interchangeable.
The pre-upgrade source reference is `fc9b3258646ac744e61fd02e18d9b98e5afff2e8`
on `main`; its `composer.json` contains the older-major baseline constraints
in the second column. The original baseline `composer.lock` is **not tracked**
(the repository intentionally ignores lockfiles), so no old-lock hash is
invented. Full source/dependency-upgrade paired comparisons are still required
under P1/P6.

## Current maintenance disposition

2026-11-11 local CI qualification, based on source `365425bc` plus uncommitted remediation:

- Full `composer ic:ci`: **exit 0, 13/13 checks pass**, including PHPStan, Psalm and the complete functional suite. See the [canonical implementation tracker](foundation-4-library-upgrade-plan.md) for scope, evidence and the remaining release boundary.
- Current installed graph: **172 packages**, lock SHA256 `51cabd616ef81d302607e336cb9aed2ea1bcb43b49a18df94d3ccbb10e1ba6b7`. Composer normalization changed only manifest/lock metadata; all package versions and source/dist references were compared before/after and remain identical.
- Installed Runwire **3.0.1**, source `be84df6bc599798f4407df1ef5beb23afd5ceb3e`; required floor remains `^3.0.1`.
- Installed PHPForge **dev-main**, source `ba2ffc3ba608207129883187e57c56c20e112ad4`; its existing `dev-main@dev` requirement is unchanged.
- Current `composer audit --locked --format=json`: **zero advisories**, one abandoned package, **exit 1**. The failed audit status is attributable to abandonment and is kept separate from the passing thirteen-task `ic:ci` suite.
- This task repaired CI against the installed graph. A latest-published-version survey and exact-commit hosted/consumer qualification were not repeated; historical latest-version claims below do not become current claims by implication. `composer.lock` remains gitignored.

| Tracker | Current external maintenance item | Verified installed owner/constraint | Disposition |
| --- | --- | --- | --- |
| M02 | Abandoned `doctrine/annotations` 2.0.2 | Development-only PHPBench 1.7.0 requires `doctrine/annotations ^2.0` | Upstream blocked; still reported by Composer audit. No suppression, fake replacement or vendor edit. |
| M03 | PHPUnit ceiling 13.4.5 | Installed Pest 5.3.5 conflicts with PHPUnit `>13.4.5` | Upstream compatibility ceiling remains; no incompatible override. |
| M03 | JsonMapper major 5 ceiling | Installed Psalm **7.3.0** requires `netresearch/jsonmapper ^5.0`; `danog/advanced-json-rpc` 3.2.3 requires `^5` | Upstream compatibility ceiling remains; owners must support a newer major before Foundation can consume it. |

These maintenance constraints are not resolved defects or waived latest-version requirements. Foundation runtime changes cannot remove upstream tool requirements. The previous November 6/7/10 package counts, lock hashes, source references and workflow artifacts below are immutable historical evidence. Current local CI success does not close the two P6 certifications or authorize merging, tagging or publishing.

## Resolved candidate graph

The [official green source `98e07629`](https://github.com/infocyph/Foundation/actions/runs/42172043870)
recorded the following immutable output in its uploaded
`foundation-release-evidence-*` ZIP on 2026-11-06:

- Candidate source SHA: `98e07629d33989e1c386d9c323a77496c98a5768`.
- PHP: `8.5.12`; workflow pin:
  `a6232b6aefcc32b84b2fb94b8db8f5ac3b276d5d`.
- Resolved **171** Composer packages, **17** direct package
  dependencies (including the dev engineering tools).
- Candidate lock SHA-256:
  `d5b72564daff56897c64861a633abe834e8d728822cced084a962cf119ded278`.
  It is preserved **in the CI artifact**, not added to source control.
- The independent Batch 70 locked Composer audit returned **zero advisories**,
  and reported one abandoned transitive package, `doctrine/annotations`
  (no replacement declared). The existing official PHPForge
  `ic:release:audit` also reports **0 advisories / 1 non-blocking abandoned**.
  The default `composer audit` exit code 1 for the abandoned package halted
  Batch 70 despite clean advisories and all platform checks passing.
  The next workflow explicitly uses `--abandoned=report`: the abandoned
  package remains in the stored JSON and is **not hidden**, while any security
  advisory, malware or other blocking Composer policy still fails CI.

The source commits below are the dereferenced **published Git tag commit SHAs**
for the actual versions in that lock (not tag-object SHAs). Environment/tool
platform requirements were inspected in each tag's `composer.json`.

| Package | Pre-upgrade manifest | Resolved candidate | Published commit SHA | Declared platform / use |
| --- | --- | --- | --- | --- |
| `infocyph/arraykit` | `^5.2` | `5.3.2` | `f338c70b2ac86b9fa7244fdfb21e5a4b3e38df79` | `PHP >=8.4, ext-hash` |
| `infocyph/cachelayer` | `^3.4` | `4.0.2` | `a5c314b6fb9b1d20fdf5cfde044b6f5c235c2e22` | `PHP >=8.4` |
| `infocyph/intermix` | `^10.1.1` | `11.2.1` | `4ddb59961a0076f182378412fbc4ff5031e1f5af` | `PHP >=8.4` |
| `infocyph/runwire` | `Not required` | `3.0` | `054737bc6a8d4025a8d05fabb8ad390f66ee47ae` | `64-bit PHP ^8.4` |
| `infocyph/uid` | `^5.0` | `6.0.1` | `01bedaea0347ca815d0c47c97f4f8915efc1f2d4` | `64-bit PHP ^8.4, ext-ctype` |
| `infocyph/webrick` | `^5.4` | `6.0.4` | `981d095251dc3bf9c65c434f0d84aecb38025f88` | `PHP ^8.4` |
| `infocyph/dblayer` | `^5.1` | `6.1.4` | `c530bf861d2921bc7c59b4ea9ffe4f9dc0620877` | `PHP ^8.4, ext-pdo` |
| `infocyph/epicrypt` | `^3.1` | `3.3` | `65cf0e619d22ece5a2e52cfaa3988120cf170ff6` | `PHP ^8.4, ext-hash/json/openssl/sodium` |
| `infocyph/omnibus` | `^2.6` | `3.0.3` | `ae60cb2701d97103ba8452c9f51e11b7aebe515a` | `PHP ^8.4` |
| `infocyph/otp` | `^6.1` | `7.0.1` | `f40d42e283528cdc25a0c3473e9e2650372e7cfb` | `64-bit PHP ^8.4, ext-ctype` |
| `infocyph/pathwise` | `^4.1` | `4.2.1` | `5e1c18f2f95307d0456e749c374c1dd045a0ecd9` | `PHP >=8.4, ext-fileinfo` |
| `infocyph/reqshield` | `^3.2` | `3.3.1` | `733b54e98d76b43b9b651dae0b859e5bf8152bb8` | `PHP >=8.4, ext-fileinfo/hash/mbstring` |
| `infocyph/talkingbytes` | `^2.1` | `2.3.1` | `be6ca68deb31f730054122a421fe0a400a99a227` | `PHP ^8.4, ext-curl/fileinfo/openssl` |
| `infocyph/phpforge` | `dev-main@dev` | `dev-main a6232b6` | `a6232b6aefcc32b84b2fb94b8db8f5ac3b276d5d` | `Engineering-only; dev dependency` |
| `opis/closure` | `^4.5` | `4.5.0` | `b97e42b95bb72d87507f5e2d137ceb239aea8d6b` | `Optional/dev native closure serialization` |
| `psr/log` | `^3.0.2` | `3.0.2` | `f16e1d5863e37f8d8c2a01719f5b34baa2b714d3` | `Core PSR logging interface` |
| `web-auth/webauthn-lib` | `^5.3.9` | `5.3.12` | `c7aeeb1d35e39b7d32ab5146cce4b668f271a68e` | `Only when security.passkey selected` |

## Composer validation and environment

Core: PHP ^8.4, Composer runtime API ^2.0 and 64-bit host support
required by the selected Core Runwire/UID packages. Ordinary PHP/FPM
does not require Swoole, PCNTL or POSIX. Optional specialist extensions
remain conditional (for example selected Epicrypt requires sodium,
while passkeys do not acquire a Core sodium requirement).

The unchanged four-way PHPForge PHP 8.4/8.5 stable/lowest matrix,
four isolated no-dev consumer configurations, clean installation and
native FPM/Runwire/Swoole/OpenSwoole hosts must remain green.
`composer validate --strict`, `composer ic:release:constraints` and
`composer ic:release:audit` already run in the release-evidence job.
Batch 70 additionally runs native
`composer check-platform-reqs --lock` both with and without dev packages,
and `composer audit --locked --abandoned=report`. The resulting three JSON files are
uploaded alongside the immutable lock digest and dependency inventory.
No `--ignore-platform-reqs`, `--no-audit` or unchecked partial acceptance.

## Historical release-evidence requirements

The original 3.x lock was not committed. Its absence is now a maintainer-approved
policy exception, recorded in the [current handoff](foundation-4-release-certification-handoff.md);
the lock remains gitignored and reconstructed resolution is never labeled
original historical evidence. The earlier requirement below remains useful for
paired-run identity, not as a request to recover an unavailable original lock.
A reproducible reviewed
baseline lock must be captured separately and paired with the original
3.x source and comparison tooling. This candidate snapshot cannot
substitute for that historical baseline. In P6 dependency-upgrade mode,
record separate original and candidate locked graphs and an explicit
allowlist of verified version/source-ref transitions; in source-only mode,
record identical lock hashes before any measurement. The existing
paired-host workflow retains all sustained concurrency and 2%/2.5%
performance limits. Do not use a green small native-host smoke to
declare the paired-host performance matrix complete.

## Batch 70 platform and security evidence

[CI 42175214367](https://github.com/infocyph/Foundation/actions/runs/42175214367)
recorded `platform-core.json` with **5/5** satisfied native platform
requirements, `platform-full.json` with **26/26** satisfied requirements,
and `composer-audit.json` with `advisories: []` plus the one non-blocking
abandoned `doctrine/annotations` entry. All static, functional, benchmark,
no-dev consumer and native-host lanes passed. The workflow verdict was red
only because Composer's default policy exits 1 for abandoned packages.
Batch 71 retains the same audit and JSON output with explicit
`--abandoned=report`; no advisory suppressions or platform overrides.
