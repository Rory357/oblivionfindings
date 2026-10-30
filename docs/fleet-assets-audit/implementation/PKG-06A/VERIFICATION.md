# PKG-06A integrated verification

Application source: `556e582cbd43aff875e1640ebc9e32fb9f52f6a0`.
Published main integration base: `ba5bff2e8b6c22796369443f1cdac918039950dd`.
Worktree: `6421`. Date: 27 September 2026 (Pacific/Auckland).

## Automated checks

- **68 backend/infrastructure tests passed, 855 assertions**, including 16 Assets register workflow tests, existing AssetController and AssetMutationBoundary tests, 14 Vehicle Trip history/export tests, and the four database/bootstrap/cache tests. This includes the retained recorded-seconds XLSX precision regression and locally generated report map/privacy checks. See `backend.log`.
- **37 frontend tests passed** across register API, stocktake hub/modal and shared Fleet navigation. See `ui.log`.
- **Full TypeScript check is clean.** `types.log` is empty. The six old navigation test diagnostics reported before reconciliation are absent on current main.
- **Scoped ESLint passes** with zero warnings; `eslint.log` is empty.
- **Pint passes** for the label safeguards, regression tests and resolved test harness. See `pint.log`.
- `git diff --check` against the actual published main base passes.
- Frozen preview integrity: **368 artifacts match** the v1–v9 manifests. Only the two explicitly identified active v9 server logs are excluded; see `frozen-integrity.json`. None of these frozen artifacts is included in the application commit.

The initial integrated backend invocation contained a wrong path (`tests/Feature/FleetAssets/AssetControllerTest.php`) and ran no tests. The successful command below uses the verified path. That invocation is not counted as a test result.

## Commands

Run in the candidate checkout, using the existing Node installation and Herd PHP 8.4. Backend commands use the repository's isolated per-process testing database. The schema override is a local, schema-only helper excluded from Git; no database contents or credentials are included in this packet.

```powershell
$env:MYSQL_TEST_SCHEMA_PATH=(Resolve-Path storage/app/register-verification/register-schema.sql).Path
$env:MYSQL_TEST_SCHEMA_TIMEOUT='1800'
& 'C:/Users/steph/.config/herd/bin/php84/php.exe' artisan test tests/Feature/FleetAssets/AssetRegisterWorkflowTest.php tests/Feature/AssetControllerTest.php tests/Feature/FleetAssets/AssetMutationBoundaryTest.php tests/Feature/FleetAssets/Pkg02bVehicleTripHistoryTest.php tests/Unit/TestDatabaseBootstrapFailClosedTest.php tests/Unit/Support/SchemaCacheScopeTest.php

node node_modules/typescript/bin/tsc --noEmit
node node_modules/vitest/vitest.mjs run resources/js/pages/fleet-assets/assets/register/api.test.ts resources/js/pages/fleet-assets/assets/register/stocktakes.test.tsx resources/js/pages/fleet-assets/assets/register/stocktake-modal.test.tsx resources/js/components/fleet-assets/fleet-workspace-navigation.test.tsx --reporter=verbose
node node_modules/eslint/bin/eslint.js resources/js/pages/fleet-assets/assets/index.tsx resources/js/pages/fleet-assets/assets/components/asset-wizard-dialog.tsx resources/js/pages/fleet-assets/assets/register resources/js/components/wizard/shell.tsx --max-warnings=0
& 'C:/Users/steph/.config/herd/bin/php84/php.exe' vendor/bin/pint app/Http/Controllers/FleetAssets/AssetLabelController.php tests/Feature/FleetAssets/AssetRegisterWorkflowTest.php tests/TestCase.php --format=txt

$env:PATH='C:/Users/steph/.config/herd/bin/php84;'+$env:PATH
node node_modules/vite/bin/vite.js build
git diff --check ba5bff2e8b6c22796369443f1cdac918039950dd HEAD
```

## Evidence handling

`log-provenance.json` identifies the ignored original logs and hashes, the sanitized review copies and their hashes, and the application commit. Absolute worktree paths in log text are replaced with `<worktree>`; ANSI styling and trailing whitespace are removed and line endings normalized to LF. No test outcome text is removed. Empty successful type/lint outputs are retained as empty files. The application file list and SHA-256 hashes are recorded separately. The final evidence commit is documentation-only, so it can reference this already frozen source commit without a self-referential commit hash.

Browser evidence uses only the separate synthetic preview, normal authentication and built application assets on loopback port 8774. No `.env`, schema/record dump, preview credentials, server request log or real user export is included. Phone/device testing is explicitly deferred by Stephan; no hardware success is claimed.

## Production build and integrated browser check

The Vite production build passed in 6m 20s. It retains existing large-chunk warnings; see `build.log`. The application source was frozen before the build and tests and has not changed since.

The built app was reloaded through the in-app browser on port 8774. Stocktake loaded three saved counts with the resume panel and hub tabs. Opening completed ST-2 displayed its immutable three-item checklist, original outcomes and PDF/Excel buttons in the shared modal. Closing the modal and returning to Inventory loaded five synthetic assets, the assigned-location sidebar and the 100-site directory entry. The observed module preload `/build/assets/index-BEJFccP9.js` matches this checkout's final build manifest. `public/hot` is absent. Browser warning/error capture returned an empty list on this final check.

The three screenshots (`inventory.png`, `stocktake-hub.png`, `completed-stocktake.png`) were captured from this reconciled build on 27 September 2026. `browser-verification.json` records the host, source commit, build manifest hash and observed entry. These are fresh post-integration screenshots, not copies of the frozen mockup. Export bytes/authorization and the Vehicle Trip duration precision are covered by the integrated backend run; the final browser pass did not request camera access or perform phone testing.
