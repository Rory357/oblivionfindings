# Fleet entry and header correction checkpoint

> Historical checkpoint, superseded for publication status on 29 September 2026: Reports was integrated through `ee22de096` and Compliance through `c2f89b358`. The former approval blocker below describes the earlier state; it is not a current publication blocker. See [the integration audit](../audits/2026-09-29/README.md) for current validation and remaining acceptance work. The original checkpoint is retained below.

**This is not full programme acceptance.** Work is isolated on `codex/fleet-entry-corrections`, based on `52dafa6728ebe343631453d4863f645d97c59506`. Main, the separate follow-ups directory and production have not been changed by this work.

The main checkout acquired concurrent, uncommitted Fleet changes during verification, including Compliance and Alerts controllers, shared Fleet navigation, vehicle screens and routes. Those edits have been left untouched. Integration must reconcile the overlapping files with their owner before merging this branch; this checkpoint is not an instruction to overwrite or stage main's work.

## Implemented

- Both sidebar links to `/fleet-assets/reports` open the operational report library. `/fleet-assets/reports/builder` intentionally opens the builder. The old operating summary remains at `/fleet-assets/reports/operating-summary`; specialist report and export routes remain available.
- Seven permitted report views have stable URLs and server-validated source access. Library links reset focused-view queries. Back and refresh restore focused views. Scheduled notifications open saved runs, which load on entry.
- Library, saved and focused reports use the standard header band and filters. The builder retains its approved compact composition. Focused meters show actual generated measures or a not-run/unknown state. Saved and empty results have explicit states.
- Builder drafts are keyed by viewer and reporting area. Browsing the library or focused reports does not overwrite an existing draft. Recovery revalidates its definition.
- Operating summary, usage by house, community access, cost allocation and reimbursement use shared PageHeader components. Fabricated trend indicators were removed; readable expiry dates replace fractional-day strings.
- Reimbursement failures show an error rather than an empty report. Responses for changed parameters are discarded, preventing stale payroll exports. Rate copy no longer claims the selected value is the applicable IRD rate; no reimbursement policy is established here.
- Alerts retain the canonical Control Room owner and access scope. Meters open matching time-window lists and preserve the selected asset. All statuses differs from the unresolved default. Sorting is keyboard accessible, the workflow row wraps, and severity counts identify the current page. The Control Room link requires view permission.
- Fleet breadcrumbs start at Home. Shared expiry badges say “No expiry alerts” rather than asserting current evidence. Shared header clipping prevents focus during resize from scrolling the identity out of its own header, using the same approach already present for My Day.

## Validation

- OperationalReportsTest and FleetReportSiteAccessTest: 27 tests, 469 assertions passed, including landing, builder, report-only sources, denied roles, private exports and legacy report site boundaries.
- FleetControlRoomAlertHeroScopeTest: 3 tests, 203 assertions passed, including activity counter/list parity and site/asset boundaries.
- Vitest: 36 tests passed across workspace navigation, draft/history navigation, alert resolution and shared expiry badges. Two additional reimbursement failure/stale-response tests passed.
- TypeScript, targeted lint and the final production build passed. Build identity and verification results are recorded in `verification.txt`.
- Real browser verification used integrated Laravel at `http://127.0.0.1:8789`, an isolated synthetic database and a unique session cookie. Design previews were not used as implementation evidence.
- Actual sidebar navigation reached Overview, Fleet, Transport, Assets, Maintenance, Maps & boundaries, Reports and Settings. Fleet/Assets showed synthetic records; Maps loaded two vehicles and one asset. This is landing-page coverage, not exhaustive acceptance of every action.
- Fleet → Reports and Reporting → Fleet Reports opened the library. All seven tab URLs settled correctly. Back, refresh, contextual library navigation and draft recovery were exercised.
- A report-only user saw only permitted report sources and the North site, and could not recover the manager's draft. A user without report access had no Fleet navigation and received 403 for both library and builder URLs.
- Scope and view-picker dialogs opened and dismissed at mobile widths. Light and dark themes were exercised. Measured CSS viewport sizes and final screenshots are retained with the verification record.
- Final report-library layout was checked at 1536 × 711 and 390 × 844 CSS pixels in light and dark themes, with an additional desktop override measuring 1536 × 864. After opening/dismissing the view picker and resizing through a narrower viewport, the header had `overflow: clip` and `scrollTop: 0`. Returning to the page top showed the intact title. Escape closed the scope dialog and restored focus to More filters after its closing animation.
- The operating summary showed 2 synthetic trips and 54.5 km, with calendar expiry dates. All four specialist reports loaded through the contextual menus. Selecting North in Usage by house produced 1 trip and 12.5 km. Reimbursement generated 2 trips / 54.5 km and removed the result and export control after its rate changed. The test trips have no assigned driver and are presented as Unknown; this is not payroll approval.
- Alerts showed 2 canonical unresolved/critical alerts. Acknowledged today opened its activity URL and returned an empty matching list. The empty-state copy now describes this filtered view. Browser console inspection after these checks returned no errors.

## Approval blocker: Compliance

The Compliance controller and page remain unchanged. Automatic approval review rejected the controller correction because it changes evidence interpretation, status computation, scoping and response fields, and the reviewer found insufficient trusted authorization. An atomic replacement was also rejected. Explicit approval is pending; this checkpoint does not bypass that decision.

The prepared correction would read existing current vehicle compliance versions for registration, WoF, CoF and RUC, verify current-version ownership, and use existing readiness projections. Insurance stays vehicle-profile evidence. It preserves approved-site/role boundaries, distinguishes unknown and recorded-not-applicable states, links to vehicle service/evidence, and replaces the overlapping-count percentage with disjoint, explainable queue counts. No second evidence store, tenant boundary or schema removal is proposed.

Main now contains a concurrent Compliance queue implementation. If approval is granted, review and reconcile that implementation first; do not blindly apply the earlier controller proposal over it.

Required regressions for that correction remain: expired and due-today calendar dates; missing, failed and not-applicable evidence; applicability basis; RUC coverage; mismatched current-version pointers; direct-object denial; and records outside approved sites.

## Remaining scope

The broad request is not complete. Source inventory still finds legacy headers in Daily checks; Compliance; booking list/details; fuel; device list; driver list/details; maintenance overview, schedules and checklists; inspections; keys; handovers; mileage; incidents; trips/playback; outings; client location/history; legacy journey records, pre-checks and medications; and vehicle alert configuration. Some occurrences are inactive fallbacks, including the legacy map page and old asset profile branch. Each reachable page still needs its own design/ownership assessment and appropriate correction. Shared navigation alone is not acceptance.

Verification limits:

- A genuine browser-menu 125% zoom setting was not independently established. Device pixel ratio and viewport override measurements are not claimed as zoom proof.
- The browser download observer timed out for Trips CSV. Backend export checks passed, but the native download was not confirmed.
- Populated, failed and permission-revoked states were not exhaustively browser-tested for every secondary page. Specialist headers and header clipping passed the focused checks above; this does not extend acceptance to the remaining legacy pages.
- No merge, push or deployment is part of this checkpoint. The deployed `.com` site has not been verified and must not be described as fixed.

## Authority and environment

Single-organisation boundaries follow `docs/architecture/single-tenant-application.md`. DESIGN.md and design_styles are unchanged. The approved PKG-09B v2 packet distinguishes the compact builder from full library/focused bands. The checked master prompt was rev 10 + A1–A8, SHA-256 `B2EC6CAB1D0047A2B1DFC724E37B2A356BEB356093B9CC0934F4352CA1748114`.

Fixture scripts, dependencies, private session files and builds are ignored development artifacts, not commit content. Evidence contains synthetic records only.
