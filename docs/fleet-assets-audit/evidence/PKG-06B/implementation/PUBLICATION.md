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

## Local-only verification state

`phpunit.pkg06b.xml`, `storage/framework/pkg06b-browser.json`, `storage/framework/pkg06b-dev-hot.txt`, dependencies and compiled build output are deliberately excluded from the commit. The local XML contains this machine's test-database configuration; no database password is included in the publication. The adjacent helper scripts use an explicitly identified synthetic database and do not supply credentials themselves. Preserve the running loopback preview/fixture while review depends on it.

Production migrations/configuration, malware scanner activation, deployment and physical label printer/scanner calibration remain separate release work. No live database change is included in this publication request.
