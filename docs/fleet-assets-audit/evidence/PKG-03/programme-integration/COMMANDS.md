# Scoped verification commands

Run from dcf0 with Herd PHP 8.4. `MYSQL_TEST_SCHEMA_TIMEOUT=1800` permits bounded local schema setup; the repository TestCase still creates and cleans a per-process test database. No operational database is used by the tests.

```powershell
php artisan test --compact tests/Feature/Finance/BillApprovalSnapshotTest.php tests/Feature/Finance/BillSpendApprovalGateTest.php tests/Feature/FleetAssets/Pkg02bVehicleFinanceTest.php tests/Feature/FleetAssets/Pkg01MaintenanceProtectedSliceTest.php tests/Unit/FleetAssets/MaintenanceCostSummaryTest.php tests/Feature/FleetAssets/Pkg06bAssetProfileTest.php tests/Feature/FleetAssets/TransportWorkspaceTest.php --filter 'BillApprovalSnapshotTest|BillSpendApprovalGateTest|Pkg02bVehicleFinanceTest|Pkg01MaintenanceProtectedSliceTest|MaintenanceCostSummaryTest|test_asset_finance_|test_asset_visibility_|test_allocation_is_atomic|test_replacement' --log-junit docs/fleet-assets-audit/evidence/PKG-03/programme-integration/backend.xml
node node_modules/typescript/bin/tsc --noEmit
node node_modules/vite/bin/vite.js build --outDir .pkg03-final-tools/production
```

The build uses `APP_ENV=testing` and the Herd PHP directory on the process PATH for Wayfinder; it uses the repository's actual Vite configuration. The fresh generated actions remain ignored runtime output, and no tracked source was changed by the build.

The successful Vitest command uses `--config .pkg03-final-tools/vitest.config.ts --reporter=dot` with these exact files:

- `resources/js/components/fleet-assets/maintenance/cost-workspace.test.tsx`
- `resources/js/components/finance/document-preview.test.tsx`
- `resources/js/components/finance/record-batch-wizard.test.tsx`
- `resources/js/pages/finance/bills/Show.test.tsx`
- `resources/js/components/fleet-assets/vehicle-workspace/record-command.test.tsx`
- `resources/js/components/fleet-assets/vehicle-workspace/finance-studio.test.tsx`
- `resources/js/pages/finance/vehicle-reviews/history.test.tsx`
- `resources/js/components/assets/profile/finance-review-dialog.test.tsx`
- `resources/js/components/fleet-assets/vehicle-workspace/booking-request.test.tsx`
- `resources/js/components/fleet-assets/maintenance/date-picker.test.tsx`
- `resources/js/components/fleet-assets/transport/location-field.test.tsx`
- `resources/js/lib/fleet-return.test.ts`

ESLint checks the review dialog/index/history and shared wizard/record-command. Pint checks FinanceReviewNotices, VehicleFinanceReviewQueue, VehicleFinanceService and Pkg06bAssetProfileTest. All are non-mutating final checks. `git diff --check` also passes.

`visual-verifier.zip` restores into `.pkg03-final-tools`. It contains only synthetic fixture data, the read-only server and the test config. See its README for the isolated PDF dependency and exact build/serve instructions. It contains no database, compiled assets or operational credentials.
