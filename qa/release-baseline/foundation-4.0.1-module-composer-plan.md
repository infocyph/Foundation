# Foundation 4.0.1 — module Composer installation mode

## Reproduction and scope

[Published consumer acceptance run 45435877095](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45435877095): nine Core/default profiles pass; eleven no-dev feature profiles reinstall development dependencies after module install. Composer require/remove support --update-no-dev, but Foundation 4.0 does not forward the installed mode. Keep explicit COMPOSER_NO_DEV=1 as a supported deployment option, not a substitute for automatic mode preservation.

## Batch 1 — implementation and verification

- [x] CORE-01 / CONFIG-02: preserve installed Composer mode for package-changing operations, including dry run; use the same application-root installed.json metadata already used by the snapshot, not a PHPForge-specific heuristic.
- [x] FLOW-02: add focused development/no-dev command regression coverage; document the supported production contract.
- [x] Review malformed, absent and legacy metadata behavior and add repair/edge-case coverage. Reject malformed mode metadata; retain legacy fallback and explicit environment override. Real Composer verification pending.
- [ ] EXEC-03: hosted QA and analyzers across PHP 8.4–8.6; no suppressed checks.
- [x] Replace stale branch-bound consumer gate with six hosted public Infbyte/candidate Foundation lifecycle jobs, no environment override; candidate is an isolated path package, not published. Remove both obsolete 3→4 gates and revise guard tests and historical docs; retain native 4.x state/host gates and original history.
- [ ] Verify all six real consumer jobs; requalify released patch via experiment repo after maintainer publication.
- [ ] Close review findings, record exact-head evidence, retire plan, maintainer approval.

No application/request hot-path changes, dependency upgrades, merge, tag or publication. Current initial fix is unverified until hosted tests finish.

Batch 2: historical jobs failed at unavailable branch checkout in run 45439500616. Existing native regression gates retained; new candidate lifecycle job required for release. Psalm MixedAssignment boundary explicitly typed without suppressing diagnostics. Hosted results pending.

Batch 3: inspected failed PHP 8.4 prefer-lowest job 138606471151 at initial head; sole Pint issue was blank_line_before_statement in the executor. Corrected spacing before try. Experiment run 45436509096 passed 20/20 using COMPOSER_NO_DEV=1; candidate automatic mode preservation still requires its own verification. Batch 2 run 45440482153 superseded by this exact-head formatting correction.

Batch 4: review finding PRRT_kwDOS1n2gs67egvc (Psalm MixedAssignment on decoded Composer installed metadata). Replace the generic mixed annotation with the precise set of associative JSON decode return types, retain runtime array and boolean mode validation and malformed-input regression coverage. No suppressions. Prior run 45440634566 already passed all three real no-dev lifecycle lanes; fresh exact-head analyzer and hosted qualification pending.
