# PKG-06A — Main review corrections

Main subsequently closed these findings. The final reconciliation with published Fleet is recorded in [FINAL-MAIN-RECONCILIATION.md](FINAL-MAIN-RECONCILIATION.md); this document preserves the correction candidate's own evidence.

This successor addresses Main's three findings against candidate `80731cf56b4f8224769afaa91573ad1c1e8240dd`. The original review packet and logs describe that earlier candidate and remain as historical evidence. Current source, actual published-main base and results are recorded in the revision-2 evidence manifest and final handoff.

Application correction commit: `d8c7cf90d`. Exact frozen application/source merge: `c962e0db1e3b14ed56777d4cc678911b6daf5787`. Actual fetched published-main base: `49e0be5b1c1716aeb4e681529bb71fdce2a7abc0` (approved Transport publication). The merge was conflict-free and did not read from local `main` or import PKG-03 local-only work. Subsequent review evidence commits do not alter application source.

## T06A-01: retained stocktake history

Stocktake reference synchronization is now additive. It includes canonical asset IDs from current entries, explicit activity IDs and older server-owned undo snapshots. Undo removes the extra item from the current checklist while retaining both its immutable audit events and its authorization reference. Duplicate events now carry the canonical ID directly. Display names and keys are never used to infer an identity.

Direct record, mutation/resume and export checks inspect these history identities as well as the reference table. Lists, summary counts, follow-ups, coverage and the resume panel continue to use the reference-backed visibility query. A new data-only migration, `2026_09_27_140000_retain_stocktake_history_asset_references.php`, backfills older saved counts in bounded chunks, idempotently, without changing entries/activity/version/timestamps. Rollback does not remove the privacy references.

Regression coverage includes draft and completed counts, add-extra/Undo, an asset moving outside approved sites, hidden canonical profile, list/resume/coverage exclusion, direct record and both export formats returning 404, denied further edits, unchanged original evidence, and older activity shapes lacking an explicit event asset ID. The backfill is run twice in its regression to check retry safety.

An additional regression removes the viewer's secondary-site access while leaving the extra asset in place, proving that revocation also hides retained history and both exports. The initial backfill assertion compared an in-memory JSON string with MySQL's normalized stored representation. It was corrected to compare the persisted row before and after the backfill; this was an assertion-formatting failure, not an evidence rewrite. The final run is recorded separately from that diagnostic run.

## T06A-02: canonical room reconciliation

Ordinary Asset updates resolve the authoritative destination site, including a client-derived site, and keep existing placement/status/assignment/device guards. An unchanged site preserves its room. A site change with no explicit room clears the old room in the same transaction. An explicit room selection must belong to the destination site and is read under lock. The site-room composite constraint remains intact. Room IDs are included in before/after audit evidence.

The shared edit wizard explains that changing site/client clears a room belonging to another site. Regressions cover a room created through the register, same-site edits, a permitted site move, a valid replacement room, an invalid cross-site room rejection, and a CSV-imported room followed by a client-derived site move. Existing canonical mutation boundary tests remain part of verification.

## T06A-03: worker audit timestamps

The register's `stamp()` delegates to the existing `formatDateTimeLong` helper, preserving the shared `en-NZ` / `Pacific/Auckland` contract and the record year. No global formatting policy or report timezone is changed. Missing/invalid timestamps use `Not recorded`.

The timestamp regression checks Main's exact `2026-09-27T01:00:00Z` example as `27 September 2026, 2:00 pm`, a UTC date boundary and Auckland daylight-saving transition. The same test file is run in separately started UTC, New York and Auckland processes. All register consumers (observations, completion, activity, import and label history) receive the fix through the same helper.

A backend regression renders the actual stocktake PDF template for that same instant and checks `27 Sep 2026, 2:00 pm NZDT`, confirming agreement with the worker display contract.

## Ownership and rollout boundaries

The PKG-06A Designer retains source and integration execution. Main reviews the exact successor and controls the serial publication slot. No local-main merge, GitHub push, operational migration or deployment is performed before the recorded decision/slot. The user has already requested eventual local-main and GitHub-main publication; no repeat user confirmation is being sought. Phone/device testing remains deferred. Automatic Maintenance tasks are outside scope.

Only published `origin/main` is used as an integration base. PKG-03 local-only changes and Main's dirty programme records are excluded. The existing PKG-06B shared-label ownership/migration/branding plan remains unchanged; no unapproved 06B code is imported.

The initial workflow migration and the history-reference backfill must both be applied when the application is activated. The backfill has been exercised only in an isolated synthetic test database during this correction pass.

## Final verification on the frozen successor

- **74 backend/infrastructure tests passed, 936 assertions**, including 22 register workflow regressions, canonical Asset controller/mutation tests, Vehicle Trip report/export regressions, and database bootstrap/cache checks. This includes all six new review regressions described above.
- **38 frontend tests passed** across register API, stocktake hub/modal and shared Fleet navigation.
- **4 API tests passed in each of three separate processes** using `TZ=UTC`, `TZ=America/New_York` and `TZ=Pacific/Auckland`.
- Full TypeScript, scoped ESLint with zero warnings and read-only Pint checks passed.
- Production Vite build passed in **6m 38s**. Existing large-chunk warnings remain.
- All **38 application file hashes** match the frozen manifest. No application, test, migration or package file changed after source commit `c962e0db1e3b14ed56777d4cc678911b6daf5787`.

`revision-2/` contains the application file list/hashes, nine sanitized QA logs with provenance, and fresh desktop screenshots. Original diagnostic runs are not substituted for the final results. `VERIFICATION.md` and the sibling original logs outside this folder remain historical evidence for the first candidate.

The final browser pass reloaded the production build on the existing synthetic loopback preview at port 8774. Inventory loaded five synthetic assets and the assigned-location navigator for 100 sites. Stocktake loaded its three counts, resume panel and hub tabs. Opening completed ST-2 showed the original three outcomes, full worker-zone timestamps and PDF/Excel controls. Closing it left no dialog. Captured warnings/errors were empty. The observed `/build/assets/index-1ArLwLIW.js` matches this checkout's build manifest; `public/hot` is absent. These screenshots were visually inspected. No phone, camera or physical scanner test was performed in this correction pass.

### Reproduction

Use the existing PHP 8.4 and Node runtimes in the candidate checkout. Backend verification uses the isolated per-process test database and the ignored local schema-only helper; do not run these commands against an operational database.

```powershell
$env:MYSQL_TEST_SCHEMA_PATH=(Resolve-Path storage/app/register-verification/register-schema.sql).Path
$env:MYSQL_TEST_SCHEMA_TIMEOUT='1800'
& 'C:/Users/steph/.config/herd/bin/php84/php.exe' artisan test tests/Feature/FleetAssets/AssetRegisterWorkflowTest.php tests/Feature/AssetControllerTest.php tests/Feature/FleetAssets/AssetMutationBoundaryTest.php tests/Feature/FleetAssets/Pkg02bVehicleTripHistoryTest.php tests/Unit/TestDatabaseBootstrapFailClosedTest.php tests/Unit/Support/SchemaCacheScopeTest.php

node node_modules/typescript/bin/tsc --noEmit
node node_modules/vitest/vitest.mjs run resources/js/pages/fleet-assets/assets/register/api.test.ts resources/js/pages/fleet-assets/assets/register/stocktakes.test.tsx resources/js/pages/fleet-assets/assets/register/stocktake-modal.test.tsx resources/js/components/fleet-assets/fleet-workspace-navigation.test.tsx --reporter=verbose
node node_modules/eslint/bin/eslint.js resources/js/pages/fleet-assets/assets/register/api.ts resources/js/pages/fleet-assets/assets/register/api.test.ts resources/js/pages/fleet-assets/assets/components/asset-wizard-dialog.tsx --max-warnings=0
& 'C:/Users/steph/.config/herd/bin/php84/php.exe' vendor/bin/pint --test app/Services/Assets/AssetStocktakeService.php app/Http/Controllers/FleetAssets/AssetController.php database/migrations/2026_09_27_140000_retain_stocktake_history_asset_references.php tests/Feature/FleetAssets/AssetRegisterWorkflowTest.php

foreach ($reviewTimezone in @('UTC','America/New_York','Pacific/Auckland')) {
    $env:TZ=$reviewTimezone
    node node_modules/vitest/vitest.mjs run resources/js/pages/fleet-assets/assets/register/api.test.ts --reporter=verbose
}
Remove-Item Env:TZ
$env:PATH='C:/Users/steph/.config/herd/bin/php84;'+$env:PATH
node node_modules/vite/bin/vite.js build
git diff --check 49e0be5b1c1716aeb4e681529bb71fdce2a7abc0 HEAD
```

PKG-06B has since reported exercising its guarded 130000 fallback migration only on its own synthetic fixture. This does not change the recorded 06A-first integration plan or authorize importing its work. Main should confirm operational migration history and the shared-file/branding resolution when reviewing that package; 06A's combined migration still assumes it creates the label table first.
