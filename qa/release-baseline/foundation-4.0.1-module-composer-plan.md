# Foundation 4.0.1 — module Composer installation mode

## Reproduction and scope

[Published consumer acceptance run 45435877095](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45435877095): nine Core/default profiles pass; eleven no-dev feature profiles reinstall development dependencies after module install. Composer require/remove support --update-no-dev, but Foundation 4.0 does not forward the installed mode. Keep explicit COMPOSER_NO_DEV=1 as a supported deployment option, not a substitute for automatic mode preservation.

## Batch 1 — implementation and verification

- [x] CORE-01 / CONFIG-02: preserve installed Composer mode for package-changing operations, including dry run; use the same application-root installed.json metadata already used by the snapshot, not a PHPForge-specific heuristic.
- [x] FLOW-02: add focused development/no-dev command regression coverage; document the supported production contract.
- [ ] Review malformed, absent and legacy metadata behavior, repair coverage, installed-mode consistency and package ownership. Validate failure behavior and maintain no-dev isolation across real Composer operations.
- [ ] EXEC-03: hosted QA and analyzers across PHP 8.4–8.6; no suppressed checks.
- [ ] Run actual public Infbyte consumer with candidate Foundation source in isolated acceptance scope (no production dependency pins); verify install/repair/remove without COMPOSER_NO_DEV override, then requalify released patch through normal Packagist.
- [ ] Close review findings, record exact-head evidence, retire plan, maintainer approval.

No application/request hot-path changes, dependency upgrades, merge, tag or publication. Current initial fix is unverified until hosted tests finish.
