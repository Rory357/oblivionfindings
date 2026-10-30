# PKG-06A final published-main reconciliation

Main's renewed review closed T06A-01, T06A-02 and T06A-03 on exact candidate `05724b4776384e12bf9f392886da6a7a1a3d5f4d`. The remaining gate was reconciliation with the next actually published Main. Main's independent six-test/81-assertion correction run and 38 frontend tests remain its own evidence; they are not attributed to this owner.

## Exact source and scope

- Actual fetched GitHub `origin/main`: `79ea01a561f7d2fa5affa592dc096f516d663635`, after the approved Fleet publication.
- Frozen combined application/source: `2d88fdd73d272d062ca8d77b7e951ef2ede8c607`.
- Actual merge parents: the reviewed Assets candidate `05724b4776384e12bf9f392886da6a7a1a3d5f4d` and published Main `79ea01a561f7d2fa5affa592dc096f516d663635`.
- The merge was conflict-free. No local-only PKG-03 source, unapproved PKG-06B source or Main programme edits were imported.
- Subsequent handoff commits contain documentation/evidence only. The final candidate SHA is supplied in the handoff to avoid self-referential commit metadata.

`final-main/application-files.txt` lists 38 application/test/migration/package files relative to the actual new base. The file hashes and reconciliation proof are in the adjacent JSON files.

## Exact delta from the reviewed Assets source

37 of the 38 previously reviewed files are byte-for-byte unchanged. The only changed file is `routes/fleet-assets.php`, which gains the two published Fleet GET endpoints (`/vehicles/fleet-calendar/events` and `/vehicles/fleet-map/data`) inside the existing `fleet.viewAny` group. All Assets register routes remain intact; no route conflict was manually resolved.

Across the 37 application/test/config files added or changed by published Main since the previous `49e0be5b1` base, 36 match the published Git blob exactly. The shared route file is the sole exception because it retains the Assets additions alongside the two Fleet endpoints. In particular, the Fleet calendar's move/resize and requested-wall-time callback contracts and the Transport `client_id` fixture correction are unchanged from published Main.

The three closed corrective contracts remain unchanged: additive immutable-history references and legacy backfill; canonical room reconciliation with authoritative site placement; shared Auckland/en-NZ timestamps and PDF agreement. The workflow and history-reference migrations are still activation prerequisites. No operational migration or deployment occurred during reconciliation.

## Evidence carry-forward and affected verification

The owner's prior 74-test/936-assertion backend run, 38 frontend tests, three separate timezone runs, lint/types and production build remain historical evidence on source `c962e0db1e3b14ed56777d4cc678911b6daf5787`, documented in `REVISION-2.md`. They establish the unchanged Assets implementation, not a fresh execution on the final source.

Published Fleet also changes runtime code outside the Assets file manifest, including shared `EntityCard` and `PageHeaderSearch` and global CSS. Therefore, a fresh combined production build and desktop Assets smoke check are required here; the earlier compiled bundle is not represented as this final source. `EntityCard` adds an unused neutral status option, `PageHeaderSearch` adds an optional accessible label defaulting to its placeholder, and the new CSS rule only targets Fleet map raster tiles.

The affected checks use the final frozen source: the full register workflow suite, the published Transport workspace suite and the published Fleet all-sites suite; combined Assets/Fleet/calendar/Transport frontend regressions; full TypeScript; strict lint over Assets and affected shared components; production build and desktop preview. The backend process uses a uniquely named synthetic test database prefix, separate from other packages.

- **48 backend tests / 1,097 assertions passed** across the three affected suites. The read-only metadata check after process exit confirmed zero remaining process schemas under this run's dedicated prefix.
- **83 frontend tests / 14 files passed**, including the preserved Fleet timing, calendar gesture and Transport command contracts alongside Assets.
- Full TypeScript and strict scoped ESLint passed with no output. The committed source diff check passed.
- The fresh combined production build passed in **4m 43s**, with the existing large-chunk advisory. No runtime or test file changed during verification.

`final-main/` contains six sanitized outputs and their source/evidence hashes, the 38-file manifest and reconciliation proof, plus final desktop screenshots. The browser was reloaded against this exact checkout's built Assets entry `/build/assets/index-CaAGLVia.js`; it matched the final manifest and `public/hot` was absent. Stocktake loaded its saved-count hub and completed ST-2, with its original answers, worker-zone timestamps and PDF/Excel controls; closing the modal left zero dialogs. Inventory loaded five synthetic records, its 100-site assigned-location navigator and one correctly named accessible search control. Captured warning/error entries were empty. The final Inventory and completed-report screenshots were visually inspected. All preview records remain synthetic; no phone/hardware test or operational record mutation was performed.

### Automated reproduction

Run in the candidate checkout with its existing PHP 8.4/Node dependencies. The backend command uses the ignored schema-only helper and its own synthetic process database; it is not an operational migration command.

```powershell
$env:DB_DATABASE='of_assets6421_final_reconcile_20260927'
$env:MYSQL_TEST_SCHEMA_PATH=(Resolve-Path storage/app/register-verification/register-schema.sql).Path
$env:MYSQL_TEST_SCHEMA_TIMEOUT='1800'
& 'C:/Users/steph/.config/herd/bin/php84/php.exe' artisan test tests/Feature/FleetAssets/AssetRegisterWorkflowTest.php tests/Feature/FleetAssets/TransportWorkspaceTest.php tests/Feature/FleetAssets/Pkg02bFleetVehiclesAllSitesTest.php

node node_modules/typescript/bin/tsc --noEmit
node node_modules/vitest/vitest.mjs run resources/js/pages/fleet-assets/assets/register/api.test.ts resources/js/pages/fleet-assets/assets/register/stocktakes.test.tsx resources/js/pages/fleet-assets/assets/register/stocktake-modal.test.tsx resources/js/components/fleet-assets/fleet-workspace-navigation.test.tsx resources/js/components/fleet-assets/vehicle-workspace/booking-time.test.ts resources/js/pages/fleet-assets/vehicles/calendar-time.test.ts resources/js/pages/sites/calendar/protected-press.test.ts resources/js/pages/fleet-assets/vehicles/index.test.tsx resources/js/pages/fleet-assets/vehicles/fleet-map.test.ts resources/js/pages/fleet-assets/vehicles/register-evidence.test.ts resources/js/pages/fleet-assets/vehicles/calendar-export.test.ts resources/js/lib/fleet-return.test.ts resources/js/components/fleet-assets/transport/calendar-actions.test.ts resources/js/components/fleet-assets/vehicle-workspace/record-command.test.tsx --reporter=verbose
node node_modules/eslint/bin/eslint.js resources/js/pages/fleet-assets/assets/index.tsx resources/js/pages/fleet-assets/assets/components/asset-wizard-dialog.tsx resources/js/pages/fleet-assets/assets/register resources/js/components/wizard/shell.tsx resources/js/components/lists/entity-card.tsx resources/js/components/page/page-header.tsx resources/js/pages/sites/calendar/_parts.tsx eslint.config.js --max-warnings=0

$env:PATH='C:/Users/steph/.config/herd/bin/php84;'+$env:PATH
node node_modules/vite/bin/vite.js build
git diff --check 79ea01a561f7d2fa5affa592dc096f516d663635 HEAD
```

## Integration boundary

The same PKG-06A Designer owns integration execution. Actual local-main mutation and GitHub push require Main's final exact-candidate approval and explicit serial slot. The user's existing publication authorization persists; phone/hardware testing remains deferred. Main's dirty programme files and frozen v1-v9 Assets artifacts remain protected. No automatic Maintenance tasks, guide change or duplicate label implementation is included.

The recorded label integration order remains PKG-06A first. PKG-06B's separate fallback migration/shared implementation must be reconciled under its own review, preserving one canonical label contract and any existing label history. No PKG-06B code has been copied into this candidate.
