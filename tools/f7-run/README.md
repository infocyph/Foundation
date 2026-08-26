# F7 deferred gate execution

The remaining Foundation OAuth 2.1 F7 gates are implemented but intentionally not auto-executed.

`gate.txt` is the selector used by `.github/workflows/f7-final.yml` on branch `feature/oauth-2.1`.
It stays `none` while implementation work is being prepared. To execute a gate later, change the file to exactly one of:

- `f7.3` — Foundation 2.0 vs OAuth-disabled candidate existing-path median regression, 2% budget.
- `f7.4` — persistent principal reset and sustained worker soak coverage.
- `f7.5` — Composer validation, audit, constraints and runtime compatibility on PHP 8.4 and 8.5.
- `f7.6` — PHPForge syntax, style, sniff, refactor, static/cognitive-complexity, security, architecture, duplicate and comment gates.
- `f7.7` — complete Pest suite with skipped tests blocking across PHP 8.4/8.5 and prefer-lowest/prefer-stable, with integration services.
- `f7.8` — all available `OAuth21*Test.php` protocol/interoperability/conformance coverage with skipped tests blocking.
- `f7.9` — feature-diff guard against suppressions, baselines, exclusions and weakened/raised quality gates.
- `all` — execute every prepared F7.3-F7.9 gate together.

After a point-specific run completes, review the job result and any uploaded evidence before changing the corresponding checkbox in `foundation-plan.md`. A prepared workflow or queued run is not completion evidence.
