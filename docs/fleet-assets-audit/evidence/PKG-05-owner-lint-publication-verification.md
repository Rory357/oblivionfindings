# PKG-05 final publication and hosted CI handoff

Captured 27 September 2026, 17:48 NZDT. Owner worktree: `C:/Users/steph/.codex/worktrees/b9b9/oblivionfindings`.

## Exact authorized successor

Main approved the four-file lint-packaging successor `a6b9fae6a73516548b02e64c41cf1fc94ce43f01`, parent `49e0be5b1c1716aeb4e681529bb71fdce2a7abc0`, and granted its exclusive serial publication slot. An ordinary fast-forward and non-force push completed. Actual local Main and GitHub `refs/heads/main` both resolve to the approved successor.

- Main tree: `41fc7644a1d32f59b169a8284a0aadaeaf4c14a1`.
- Integrated `eslint.config.js` blob: `5b3cd4e6e760f9bfdcf84c55c479d5cabbfe4122`.
- All 67 pre-existing dirty/untracked Main files retain identical statuses and SHA256 values, both immediately after fast-forward and at this final capture. No missing files or later changed hashes.
- `public/.user.ini` SHA256 remains `5b89b33882c30a9b7882c456bda8503f7bd0341187cf7ac0873c5ec512f5c82a`.
- Actual Main application/config paths match HEAD; the Transport owner checkout is clean. No broad staging, reset, clean, stash, migration, deployment, operational-data mutation, or guide/frozen-preview edit was performed for this correction.

## Integrated checks

The validator ran against the actual Main checkout/config, not only the owner branch. All 576 frozen files have identical bytes to the approved parent; all 166 frozen code/bundle files are now ignored by ESLint. The six production Transport/shared-calendar examples remain included with unchanged effective rules. Unrelated preview scope is unchanged. Scoped actual-Main lint passes with an empty log.

The successor changes only the narrow PKG-05 frozen-preview ignore and its evidence. The previously completed runtime build, 38 focused frontend tests, staged backend evidence, and real-browser workflow/layout checks remain applicable; no additional whole-suite runtime pass is claimed for this tooling-only correction. The user's existing browser tab was refreshed to the verified runtime and the corrected Return & keys modal is open.

## Terminal CI assessment for published runtime commit 49e0be5b1

- Database bootstrap run 36294111093: **success**.
- Tests run 36294111080: **failure**.
- Linter run 36294111128: **cancelled**, superseded by the approved successor's push under workflow concurrency. Cancellation is not a pass.
- Visual regression run 36294111139: **cancelled**, likewise superseded. Cancellation is not a pass.

No hosted workflow was manually cancelled by the Transport owner. CI is not green.

Read-only lint identified a candidate-specific packaging defect in the immutable v6 preview: 26 files produced 12 errors and 28 warnings. This is explicitly not classified as inherited. The approved a6b9fae6a successor corrects that scope while preserving every preview byte and all production lint rules. Base ba5bff2e8 also has a failed linter run with 1,018 diagnostics, but this does not establish that every eventual successor diagnostic is inherited.

The terminal tests log contains 34 displayed failure entries versus 31 at the exact pre-integration base `ba5bff2e8b6c22796369443f1cdac918039950dd`. Twenty-nine candidate failure signatures match the base log. Five newly observed failures lack a matching base signature and remain **unclassified pending separate reproduction/investigation**:

1. `ControlRoomAlertNestedProvenanceTest.php:112`: expected asset id 2, got null.
2. `ControlRoomAlertNestedProvenanceTest.php:217`: expected local-context poisoned workspace to be non-null, got null.
3. `ControlRoomOperationalSurfaceSiteIsolationTest.php:352`: expected one alert, got zero.
4. `ComplianceDashboardSiteScopeTest.php:278`: expected Control Room open count 2, got 1.
5. `ControlRoomJourneyAuthorizationTest.php:363`: expected two incident journeys, got one.

These involve Control Room alerts, workspace/count scope, and incident journeys. Relevant tests/services/factories were not changed by PKG-05, but unchanged files alone do not prove absence of a regression. No blanket inherited-failure or regression-free claim is made. Truncated PHPUnit names and fail-fast batching limit the comparison. The two base failures absent from this run are not claimed fixed. Hosted CI is not claimed to have executed the entire new Transport test set; local staged evidence is separate.

The successor's four automatic workflows (36295099307 bootstrap, 36295099379 linter, 36295099355 tests, 36295099242 visual) are all in progress at the final capture. Their eventual outcomes are not asserted.

## Evidence files

All paths below are relative to this packet's directory:

- `pkg05-lint-publication-before.json`, `pkg05-lint-publication-after.json`: protected statuses/hashes and final refs/tree/blob.
- `pkg05-lint-main-fast-forward.log`, `pkg05-lint-main-push.log`: successful publication.
- `pkg05-main-lint-scope.json`, `pkg05-main-packaging-lint.log`: integrated frozen-scope and source-lint checks.
- `pkg05-hosted-runs.json`, `pkg05-lint-hosted-runs.json`, `pkg05-base-hosted-runs.json`: workflow snapshots.
- `pkg05-hosted-test-comparison.json`, `pkg05-head-tests-failures.log`, `pkg05-base-tests-failures.log`: exact-head/base failed-test evidence.
- `pkg05-frozen-v6-lint.json`, `pkg05-base-lint-failures.log`: packaging finding and base lint evidence.
- `PKG-05-PUBLICATION-VERIFICATION.md`: original runtime integration, browser and focused-check evidence.

Requested Main action: independently reconcile this final packet, record the terminal49 CI limits and separate successor status, and release the publication slot. No further source push or deployment is requested. User acceptance remains distinct from implementation/publication verification.
