# PKG-06B publication handoff

Stephan explicitly requested publication in the Asset Profile chat on 27 September 2026: “can you notifi main so that we can get this merged commit to main and main github”. This authorises preparing the scoped commit and coordinating local-main and GitHub-main publication with Main. It does not claim that Main's technical review or integration has already passed.

Candidate branch: `codex/asset-profile-implementation`, worktree `8821`. Verified implementation/test parent: `4ea64c547ed85a5b7504e59599db351f6eba7deb`. Observed main/origin-main at intake: `ba5bff2e8b6c22796369443f1cdac918039950dd`. Main integration coordinator: chat `01a0b8c5-186f-7681-83fc-40229d94ef87` (“Follow Revision 10 approval gates”). GitHub destination: `Rory357/oblivionfindings`, branch `main`.

## Scope and evidence

Read `IMPLEMENTATION.md` and `completion-verification.json` in this directory for the implemented behaviour, exact test counts and release boundaries. The candidate includes protected versioned documents/shared viewer and design guidance; custody/receipt/kit/ownership/history; original check and source evidence; scoped scans; Finance review and posted cost projections; stable branded individual and bulk QR exports; and shared desktop/mobile date controls. Frozen v1–v9 design artifacts are retained. All 42 v9 manifest hashes remained unchanged.

Local verification: 34 backend tests / 593 assertions across the fresh 31-test regression and three focused rollback tests; 39 UI tests; TypeScript, ESLint, Pint and build passed. Final compiled preview loaded `app-Jte2U_jh.js`. These results apply to the verified implementation parent plus this candidate, not to a future reconciled main candidate.

Frozen preview bundles contain generated-library trailing whitespace (including PDF-format strings). Do not trim or rebuild them. Scoped Git attributes preserve their byte hashes and exclude only generated `app.js` bundles and the frozen v8 `qr-export-validation.cjs` bundle from whitespace checking; production source remains checked normally.

## Integration work requiring coordination

- Reconcile newer canonical assignment/receipt changes in `AssetAssignmentService`, AssetController/Profile entry and routes. Do not replace new Main behaviour with whole older files.
- Preserve newer shared command-retry/navigation changes and the canonical single-organisation authorization boundary.
- PKG-06A owns the parallel Assets register work. This branch integrates its focused label model/controller/exporter/PDF source plus an Asset Profile-accessible bulk workspace. Reconcile one controller/API/table owner. This branch's `2026_09_27_130000_create_asset_label_batches.php` is guarded by `hasTable` and retains history on rollback; PKG-06A has an earlier combined register migration creating the same table.
- Main's working programme documents and unrelated changes must remain untouched. Use the serial integration slot after the exact-source technical review. Do not sweep local-only packages into an authorised GitHub push.
- Repeat affected integration tests/build/browser checks on the actual reconciled candidate before publication. Do not describe existing candidate tests as integrated-main evidence.

## Reconciliation performed for Main review

Initial implementation commit: `9ac468d33a620f57b232bfa31b90f8b1c7cc4e95`. Approved-main source `ba5bff2e8b6c22796369443f1cdac918039950dd` was merged into this isolated branch. Its canonical assignment receipt endpoint/service/privacy projections, current-assignment lookup beyond bounded history, assignment target permissions and original-body retry handling are retained. The new profile exposes those assignment receipts with explicit attestation through the canonical service and includes staff/client/whānau assignment selection. The overview's existing `?tab=assignments` link now opens Current custody. Responsibility alone no longer displays as a confirmed custodian. Source changes will be frozen in the exact review commit after verification.

The file-rule authority and bounded calendar/mobile conformance change are recorded with exact user messages in `RULE-AUTHORITY.md`. Publication itself is not the authority for editing the guides.

### Shared label ownership and migration disposition

The candidate has one `asset_label_batches` model/table, `AssetLabelController`, `AssetLabelExporter` and `pdf.asset-labels` view, using `/fleet-assets/asset-register/labels`. No second API, stocktake/import subsystem or sibling branch merge was introduced. The PKG-06B variants are submitted for substantive review themselves; PKG-06A `80731cf56` remains an unapproved sibling, not an accepted dependency. Label retries compare normalized selection/layout, check current visibility and expiry, and require update permission to create a missing QR token. Older layout records default to A4 with branding when comparing the retry contract.

Actual local synthetic fixture state is in `integration-fixture.json`: the guarded standalone fallback migration `2026_09_27_130000_create_asset_label_batches` is applied in batch 3, with three existing export histories retained. PKG-06A's combined `2026_09_27_120000_create_asset_register_workflows` is absent/unapplied in this candidate and fixture. No live database was inspected or migrated. Its current sibling source creates/drops the label table without a guard; the PKG-06A Designer was notified to reconcile that shared section before combined integration so either application order retains history. Do not drop/recreate this table or reapply an unguarded sibling migration.

The custom-media/optional-branding extension supports minimum 50 × 46 mm labels so the printed identity and code fit. PKG-06A's prior 40 mm presets need to align with that contract when it is integrated. Shared routes must retain one owner; reconcile changes by function rather than choosing entire older files.

### Current-main refresh

The first reconciliation was saved as `767477ca0b66f96789aa4b400c25970ec25d319c`, after 49 backend tests / 841 assertions and the first integration build passed. Main then independently verified Transport publication at `49e0be5b1c1716aeb4e681529bb71fdce2a7abc0`. This exact newer main source is included in the final candidate. Its shared DatePicker custom-trigger option is retained alongside opt-in clearing and viewport placement. Its search callbacks, wizard navigation option, routes and eight-section navigation are retained. The final current-main verification uses separately named `current-main-*` evidence; earlier `integration-*` checks are not presented as verification of the later tree.

All nine frozen design manifests were checked against staged Git blobs: 371 recorded entries, zero hash mismatches, including all 42 v9 files. See `current-main-frozen.json`. The verifier also confirms machine-local credentials and runtime configuration are not staged. Raw test/build logs are retained verbatim; source whitespace checks are against the candidate's delta from current main, excluding raw logs and the explicitly marked frozen generated bundles.

Final verification on the current-main runtime source: 57 UI tests passed in 10 files; repository-wide TypeScript and focused ESLint passed; PHP formatting passed; production build passed in 6m 4s. The real loopback browser loaded `app-FMwTQuLf.js`. It followed the overview assignment deep link, rejected an unchecked receipt attestation, reviewed/saved the synthetic receipt through the canonical service and displayed its actor/time/note. The shared calendar was verified at desktop and 390 px mobile width, bounded from x=16 to x=374 with reachable actions. Browser console showed no errors. See `current-main-browser.json` and accompanying screenshots.

The expanded fresh backend run (`current-main-backend.xml`) passed the 49 Asset Profile/Finance/documents/overview tests with 841 assertions, and exposed one stale Transport test fixture among its 14 Transport tests. That fixture omitted the `client_id` now required by the already-published booking endpoint, so its supposed valid allocation was rejected before reaching room validation. Only the test was corrected: it now supplies the canonical client and separately asserts rejection when client identity is omitted. The entire Transport suite then passed in rollback transactions on the identified synthetic fixture: 14 tests / 619 assertions (`current-main-transport.xml`). Final covered set: 63 distinct backend tests / 1,460 assertions across the passing subset and focused rerun, not a claim that the original combined run passed. No production access check was weakened. The original failure and focused reproduction logs are retained for review.

## Local-only verification state

Final review packet: `MAIN-REVIEW.md`. Main's later `a6b9fae6a` commit changes only Transport lint packaging/documentation and is included; verified runtime code remains unchanged. PKG-06B uses the same narrow packaging approach for its frozen previews and versioned evidence, with `lint-scope.json` proving production components and tests remain subject to lint.

`phpunit.pkg06b.xml`, `storage/framework/pkg06b-browser.json`, `storage/framework/pkg06b-dev-hot.txt`, dependencies and compiled build output are deliberately excluded from the commit. The local XML contains this machine's test-database configuration; no database password is included in the publication. The adjacent helper scripts use an explicitly identified synthetic database and do not supply credentials themselves. Preserve the running loopback preview/fixture while review depends on it.

Production migrations/configuration, malware scanner activation, deployment and physical label printer/scanner calibration remain separate release work. No live database change is included in this publication request.
