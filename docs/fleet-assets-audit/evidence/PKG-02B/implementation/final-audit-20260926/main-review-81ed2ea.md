# PKG-02B — Main final implementation review

Owner: MAIN ASTRA. Revision: 1. Updated: 2026-09-26.

**Disposition: Changes requested.** This exact candidate is not Approved for integration. One confirmed P2 correction, FINAL-01, is required within the existing export scope. No additional product decision or new worker is needed.

## Exact candidate and custody

- Candidate: `81ed2ea57e66b291f09437a9ea80a88dd4448c6a`.
- Comparison base: published main `4ea64c547ed85a5b7504e59599db351f6eba7deb`.
- Checkout: `C:/Users/steph/.codex/worktrees/pkg02b-final-audit/oblivionfindings`, branch `codex/pkg02b-final-audit`.
- Main independently checked exact HEAD and a clean tracked/untracked status before and after the review runs. Ignored runtime/test artifacts are separate from that Git claim.
- Same Designer: `01a0c2bb-fcff-7cb1-8bab-882d84477c6c`, **OF | PKG-02B Vehicle Profile | DESIGNER…**. Existing Astra Extra High custody continues.
- Main's dirty programme checkout remains at `fa7b5291988cebfb6beaa6e6e10c6c660fb2a959`. Main made no application fix, branch/reset/stash, commit, merge or push. Rory's design references and earlier frozen previews were not edited.

Main read the candidate's `docs/fleet-assets-audit/evidence/PKG-02B/implementation/FINAL-AUDIT-20260926.md`, local-map handoff, relevant changed application/tests and linked canonical authority paths. This is substantive source review under the existing final review gate, not approval based only on the Designer's reported test counts.

## FINAL-01 — P2: workbook duration total changes on recalculation

Source anchors in the reviewed candidate:

- `app/Services/Fleet/VehicleTripReportExporter.php:132` rounds each trip's seconds to integer minutes.
- The same exporter at lines 166 and 180 rounds the aggregate seconds independently for the report total.
- `app/Services/Fleet/VehicleTripWorkbook.php:31–34` writes that aggregate as the cached total alongside a formula summing the already-rounded row values.

For two 40-second trips, both row cells contain **1** minute. The total cell stores cached value **1** with formula `SUM(J8:J9)`, whose result is **2**. Consequently a spreadsheet application that recalculates this formula produces a different duration from the cached report. Larger collections of short trips can amplify the discrepancy.

Main independently generated actual workbook bytes using the candidate's `VehicleTripWorkbook`, with synthetic inputs matching the exporter's rounding. The saved probe is [PKG-02B-main-xlsx-rounding-probe.php](PKG-02B-main-xlsx-rounding-probe.php). It reads the generated OOXML and returned:

```json
{
  "input_trip_seconds": [40, 40],
  "row_minutes": [1, 1],
  "total_formula": "SUM(J8:J9)",
  "cached_total_minutes": 1,
  "formula_recalculation_minutes": 2,
  "database_bootstrapped": false
}
```

The probe ran successfully without bootstrapping Laravel or using a database. Main evaluated the formula's explicit numeric operands; this is not a claim of opening and recalculating the workbook in the Excel desktop application.

**Required correction:** preserve one consistent duration basis for numeric cells, formula results and cached totals. Prefer exact seconds or fractional minutes with appropriate display formatting and aggregate rounding, so the authoritative report duration remains accurate. Do not resolve this only by replacing the cached aggregate with the sum of independently rounded rows. Add a regression covering non-whole-minute trips, including accumulated upward and downward rounding, and demonstrate agreement between the generated formula result, cached value and report total. Check the PDF/Excel shared-data boundary so the fix does not unintentionally change the other format.

## Main's independent verification

Executed from the exact isolated candidate using its preflighted test profile:

1. Frontend: `node node_modules/vitest/vitest.mjs run --config storage/pkg02b-audit/vitest.config.mjs` — **13 files, 176 tests passed**, 16.00 seconds. Includes Vehicle Profile components and current navigation tests.
2. Map unit tests: PHP 8.4.16 with `vendor/phpunit/phpunit/phpunit -c storage/pkg02b-audit/phpunit.xml tests/Unit/Maps/OsmReportMapTest.php --do-not-cache-result` — **4 tests, 21 assertions passed**. Synthetic temporary local map datasets.
3. Focused feature tests: PHP 8.4.16 with `vendor/pestphp/pest/bin/pest -c storage/pkg02b-audit/phpunit.xml tests/Feature/FleetAssets --filter 'Pkg02bVehicleCalendarTest|Pkg02bVehicleFinanceTest|Pkg02bVehicleTripHistoryTest' --do-not-cache-result` — **43 tests, 1,005 assertions passed**, 283.89 seconds. Calendar/appointment commands, Finance boundaries, trip privacy, exports and local-map embedding are included.
4. Application/test whitespace check: `git diff --check 4ea64c547ed85a5b7504e59599db351f6eba7deb 81ed2ea57e66b291f09437a9ea80a88dd4448c6a -- app resources routes database tests config` — passed.

The initial feature command used PHPUnit against a directory containing Pest tests and stopped with an instruction to use Pest; the successful run above used the correct launcher. That launcher failure is not a product test failure. The full-range whitespace check is **not green**: committed raw console evidence logs contain trailing whitespace/ANSI output and blank EOF lines. This is separate from the passing scoped application/test check; preserve original evidence if producing normalized review copies.

Main independently ran `storage/pkg02b-audit/preflight.php` before and after tests. Both passed; the final read-only check reported no remaining package schemas. The actual feature process used `oblivion_findings_pkg02b_final_test_50376`. App/Tests autoload resolved to this checkout, and mail, queue, broadcast, cache/session and per-process test database isolation were checked. No operating database migration or live operational test was run.

- TestCase SHA256: `89872e92712993d42361d9d444a80a92ec5f633eedfc2179c78447adf337330f`.
- Test profile SHA256: `41e9970f0ca1fcee7e29a19b881847a0d8adea223183e8edb84eafe409b20525`.

## Source and rendered coverage

Main traced appointment receipts/replay/optimistic versions and undo through canonical Maintenance scheduling; source-aware document and reminder permissions/replay; Finance privacy and independent decision authority; unavailable-window ownership; immutable signal/Control Room correlation and Maintenance assessment linkage; uncertain multipart retries; and local-map installation/rendering and report privacy gates. No additional confirmed blocker was found in those reviewed paths. The application remains single-tenant with role, approved-site, record ownership and privacy boundaries.

For local maps, Main inspected the local-only dataset import, fixed layer/SRS/geometry handling, bounded read/render path, atomic manifest publication, attribution and explicit route-sketch fallback. Main separately read Stephan's actual Designer-thread request to build the OpenStreetMap export capability inside Oblivion. Regional dataset installation, operating capacity and deployment setup remain prerequisites, not implied completed work.

Main used its own temporary Chrome tab against `http://127.0.0.1:8774/fleet-assets/vehicles/7?tab=trips` with the existing synthetic audit fixture. The loaded `build/assets/app-B7HwHZWq.js` matches the candidate build manifest. The vehicle remained **Needs assessment**; trip rows, driver uncertainty, telemetry explanation, export controls, and the vehicle's Alerts & Control Room queue/detail rendered. The loaded response preserved its original signal, source trip, unconfirmed driver and fleet-threshold qualifier, with explicit separation between closing the alert and releasing the vehicle. No console errors were captured in this sampled review. Main performed no triage, release, upload, export generation or other business-data mutation in the browser, and closed only its own review tab.

Main did not independently repeat every Designer browser scenario, regenerate all Designer downloads, certify live hardware/provider behaviour or verify genuine 125% zoom. Those reported results retain their original evidence and limits. These independent runs are focused checks, not a full-suite, hosted-CI or deployment acceptance claim.

The seven published navigation entries visible in this candidate are consistent with its base. The separately approved eight-entry Transport workspace plan remains a later design change and is not an extra acceptance condition invented for this Vehicle Profile correction.

## Required next step and gate

The same Designer should correct FINAL-01, run its regression and affected export checks, preserve the original candidate/evidence and present the exact amended source for renewed Main review. Existing required package QA still applies. The Designer stated it will read Main's disposition here; Main has not started a routine messaging loop or another worker.

Do not integrate this unapproved correction candidate on the strength of the earlier general permission to push to the test server. That publication authority remains valid after the required technical gate succeeds. Package acceptance and real operating setup (roles/sites/backup, check policy, scanner/queue, local map dataset and other recorded prerequisites) remain open; this review does not create new product requirements or waive them.
