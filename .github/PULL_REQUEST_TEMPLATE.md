<!--
Thank you for your contribution to Waffle!

Please provide a clear description of your work and ensure you have completed the
following checklist. This helps us review your contribution efficiently.

This template targets the Beta 0 stabilization release. All gates below MUST pass
locally (Docker) before requesting review.
-->

# Description

A clear and concise description of the changes you have made. What problem does this solve? What feature does it add?

# Related Issue

Fixes #[issue number] (if applicable)

# Beta 0 Gates (mandatory)

Before submitting your pull request, please confirm every item below:

- [ ] **PHP 8.5.5+ features used where applicable** — Property Hooks for DTO validation, Asymmetric Visibility (`public private(set)`), `readonly` classes, typed constants. The `mixed` type has not been introduced without architect approval.
- [ ] **Mago — formatter:** `composer formatter` produces no diff (`mago fmt` is idempotent on my changes).
- [ ] **Mago — linter:** `composer linter` reports zero errors and zero warnings.
- [ ] **Mago — analyzer:** `composer analyzer` reports zero errors. **No baseline file** (`mago-analyzer-baseline.toml`, `mago-linter-baseline.toml`) has been added, modified, or relied upon.
- [ ] **Mago — guard:** `composer guard` (or `vendor/bin/mago guard`) reports `No issues found`. My changes do not violate the component's declared perimeter in `mago.toml`.
- [ ] **PHPUnit 11+ tests:** `composer tests` is green. Coverage for new code keeps the component at `>= 95%` line coverage.
- [ ] **FrankenPHP statelessness:** No `$_SESSION`, `$_SERVER`/`$_GET`/`$_POST` superglobals, `sys_get_temp_dir()`, or other process-global mutation has been introduced. PSR-7 `ServerRequest` is used end-to-end.
- [ ] **Contracts-only dependencies:** This component's `composer.json require` still points only to `waffle-commons/contracts` (plus any explicitly approved additions). No new cross-component coupling.
- [ ] **Documentation updated:** README, PHPDoc, and (if applicable) the relevant Diátaxis page under `waffle-commons/documentation/` reflect the change.
- [ ] **CONTRIBUTING.md** read and acknowledged.

# Reviewer notes

<!-- Optional: anything reviewers should look at first, areas of doubt, or follow-up tickets. -->
