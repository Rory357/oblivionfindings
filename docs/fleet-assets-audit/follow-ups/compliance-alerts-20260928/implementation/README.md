# Fleet Compliance & Alerts implementation

The user approved implementation of the frozen v5 review on 28 September 2026 and publication to local and GitHub main on 29 September. This directory records the application work; the local v1–v5 review bundles remain unchanged. The implementation commit is `e5b085628`; integration preserves the report corrections from `ee22de096`.

## Entry points and presentation

- Following the user's placement correction, the extra Fleet navigation in the breadcrumb strip is removed. Standard Home-rooted breadcrumbs follow the shared shell rules.
- **Compliance & renewals** and **Fleet alerts** are direct glass action buttons inside the Overview and Vehicles hero banners. Each queue also links to the other inside its hero. Buttons follow the existing read permissions and carry the selected site.
- The separate Alerts severity panel is removed. Four severity donuts in the hero show the status/search universe before the severity filter and filter the queue when selected.
- Both queues use the shared page header, tables, mobile cards, menus, status badges and pagination. Hero clipping is confined to these Fleet pages.
- Source details, evidence, appointment planning and alert responses use the established dialog/wizard shells. Evidence and appointment drafts can be kept on the queue page; their lifetime ends when leaving or reloading that page.
- Vehicle and asset profile return links preserve an allowlisted local queue context.
- The shared entity table has an opt-in content-height row for these two queues, so multi-line evidence and next-action details remain readable. Other lists retain the standard compact rows.

## Canonical sources and writes

`ComplianceQueueProjection` projects permitted vehicles from canonical readiness assessments and compliance versions. Header groups count unique vehicles; rows count requirements. Missing and unknown evidence stay explicit. Insurance dates are contextual and never presented as a coverage decision. Invalid current-version pointers cannot expose a different record's evidence.

The read-only compliance context endpoint reuses `VehicleWorkspacePresenter`, including existing file links and record permissions. Evidence and inspection planning reuse the vehicle and Maintenance commands. Evidence conflict recovery reloads the current version for comparison while retaining the draft and staged files.

`FleetAlertScope` aligns the Overview/Vehicle entry counts and Alerts queue across `fleet`, `asset`, `tracker`, `geofence` and `queclink_fleet`. Row resource links retain provenance checks and permitted asset/vehicle access. Control Room links and response actions use its readable scope and action permissions.

Single and bulk JSON responses lock the current records, check the expected statuses, and call `ControlRoomAlertLifecycleService`. Bulk requests are atomic: an inaccessible or stale member prevents partial changes. Optional acknowledgment/triage notes remain in the canonical activity log. Resolution notes, actor attribution and audit records remain with Control Room. Refreshing a stale response preserves draft notes and presents the currently available action.

No schema, tenancy, legal-applicability rules, restriction-release authority or external integration was introduced. Separately scoped audit findings remain outside this change.

## Verification record

- `frontend.log`: 43 passing focused tests for navigation, safe return paths, command recovery, applicability, evidence drafts/validation and single/bulk alert responses.
- `lint-final.log`: changed TypeScript files pass ESLint with zero warnings.
- `backend-final.log` and `bulk-recovery.log`: 21 distinct backend tests pass across the regression run and corrected focused rerun. The new bulk test initially asserted against the resolution-note column instead of the canonical activity log; the corrected test passes all 14 assertions, including atomic rollback, persisted notes and snapshot access.
- `build-final.log`: production build passes (5m 58s); the existing large-chunk advisory remains.
- `types.log`: final full TypeScript check passes after generated routes are available.
- PHP Pint and `git diff --check` pass. Final completion results are recorded in `verification.json`.
- The subsequent hero-entry placement change is frontend-only. Its checks are recorded in `hero-entry-build-final.log`, `hero-entry-lint.log` and `hero-entry-types.log`; the backend and prior workflow checks above belong to the initial implementation. The shared header's existing `wrapTitle` option now caps its actions at the available width, keeping the buttons inside the banner on narrower screens.
- `frozen-preview-integrity.json`: all 254 hashed files across v1–v5 still match their frozen manifests.

Backend tests use the repository's per-process isolated test databases. The frontend build regenerates ignored Wayfinder route files, so the final TypeScript check runs after that generation completes.

## Browser verification boundary

The real application server is rooted in this checkout at `http://127.0.0.1:8768`; `/fleet-assets/alerts` and `/fleet-assets/compliance` require an existing signed-in account. The frozen preview at port 62477 remains synthetic and unchanged.

Signed in through the normal login form with the existing Demo Admin account on 29 September, following the user's request. No account creation, credential changes, authentication bypass, or fixture seeding was needed. The earlier rejected QA-seeding command was never executed.

Verified the Overview hero entries, Alerts queue and cross-link, Compliance queue, selected-site filtering, and the existing evidence wizard's open/cancel flow. At a measured 390px viewport, Compliance uses cards and has no horizontal page overflow. The alert queue has no current records in this local database; live alert submissions were not performed. Automated tests cover those writes, conflict recovery, and atomic bulk behavior. Evidence was inspected without saving changes.

## Integration validation

- Resolved overlapping Alerts and shell changes while retaining GitHub's activity filters, report-library routes, and Home breadcrumbs. Activity filters remain visible and removable in the new queue; the extra navigation above the hero stays removed.
- `merge-frontend.log`: 53 tests passed in nine files, including the incoming report UI tests.
- `merge-backend.log`: 22 tests passed with 453 assertions, including the incoming activity-filter regression.
- `merge-types.log` and `merge-lint.log`: full TypeScript and merge-file ESLint passed. The later table-height correction also passed ESLint.
- `final-types.log`: final full TypeScript check passed after the completed production build. An earlier overlapping check encountered transient generated-route files; the sequential rerun passed.
- `merge-build.log`: the initial integrated production build passed in 5m 22s. `final-build.log` includes the row-height correction and passed in 5m 59s, with the existing large-chunk advisory.
- Historical `verification.json` and `hero-entry-verification.json` describe the earlier checkpoints; this integration record supersedes their login and publication status.
- The final browser reload confirmed expanding table rows, working hero cross-links carrying the selected Site, and severity filtering. No browser console errors were reported. `compliance-desktop-final.png` captures the corrected table; `compliance-mobile.png` records the narrow card branch. `integration-verification.json` records the final checks.
