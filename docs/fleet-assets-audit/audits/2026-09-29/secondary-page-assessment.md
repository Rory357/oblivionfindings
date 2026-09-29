# Fleet secondary page assessment — 29 September 2026

This is a source and workflow assessment of the 50 titled Fleet page files in `page-inventory.json` at the `3ffc4c1` baseline and this follow-up. A controller render or an active route establishes reachability; it does not establish visual acceptance. The shared boundary is one operating organisation, approved sites, roles, direct-object denial and privacy rules.

`secondary-verification.json` is the first-batch snapshot at `dab1531`; `secondary-followup-verification.json` records the second batch and its remaining external checks.

## Corrected in this follow-up

- `fleet-assets/daily-check`: active. Replaced the legacy header with the shared PageHeader, made the three check counts filter the list, made the filtered empty state truthful, kept alert access permission-aware, and corrected date-only compliance counts.
- `fleet-assets/bookings/show`: active. Replaced the compact legacy header with the shared profile header, preserved the contextual return link and status, and allowed the status banner to wrap on narrow screens.
- `fleet-assets/vehicles/alerts-config`: active. Replaced the compact legacy header with the shared profile header, retained unsaved input after a failed save, and exposed the save error to assistive technology.
- `fleet-assets/bookings/index`: active. The first batch corrected calendar horizontal scrolling and focus access, status colours, and filtered empty-state copy; the second batch converted its header below.

## Active pages with the shared header or report wrapper

These files already use the shared header or the report wrapper. This source classification does not certify all states and actions.

- `fleet-assets/alerts/index`: canonical Fleet alert queue.
- `fleet-assets/assets/index`: asset register.
- `fleet-assets/assets/labels`: label workspace.
- `fleet-assets/compliance/index`: evidence queue and wizard entry.
- `fleet-assets/dashboard`: Fleet landing.
- `fleet-assets/geofences/index`: geofence and map workspace.
- `fleet-assets/maintenance/work-orders/index`: work-order list.
- `fleet-assets/maintenance/work-orders/report`: work-order report.
- `fleet-assets/maintenance/work-orders/show`: work-order detail.
- `fleet-assets/reports/by-house`: specialist report wrapper.
- `fleet-assets/reports/community-access`: specialist report wrapper.
- `fleet-assets/reports/cost-allocation`: specialist report wrapper.
- `fleet-assets/reports/index`: older operating summary at its explicit route; the `/reports` landing uses the newer operational report library.
- `fleet-assets/reports/reimbursement`: specialist report wrapper.
- `fleet-assets/settings/index`: Fleet settings, including Maps setup.
- `fleet-assets/transports/record`: journey record form.
- `fleet-assets/transports/workspace`: canonical Transport workspace.
- `fleet-assets/vehicles/index`: vehicle register.
- `fleet-assets/vehicles/show`: vehicle profile workspace.

## Active secondary pages converted in the second batch

All 26 remaining controller-rendered pages now use the shared `PageHeader`. The retired hero components remain only in the inactive branches below. The conversions retain canonical record ownership, existing role/site/privacy gates, list filters, and workflow actions. Counts without an exact destination are facts in the subline or body; each rendered header meter opens a matching list, tab, or detail view. Long titles wrap at narrow widths.

The local synthetic Fleet manager session rendered all 17 list/overview pages below with one shared header, Home-rooted breadcrumbs, and no document-width overflow at a 375-pixel viewport override (288–300 CSS pixels on this host). Empty states were present for most lists, so populated-state acceptance remains separate.

- `fleet-assets/bookings/index`: approval, checked-out and overdue meters retain their filtered links; booking wizard and CSV remain available. The prior list/calendar corrections remain.
- `fleet-assets/devices/index`: consent meter opens the existing consent tab; pairing and export remain. Online, battery and pairing facts stay visible without invented filter links.
- `fleet-assets/drivers/index`: eligibility, expiry and risk meters link to the supported status filters; CSV remains.
- `fleet-assets/fuel/index`: month spend/litres and 30-day entries link to their date scopes; log and export remain.
- `fleet-assets/handovers/index`: pending and disputed meters link to the matching queue filters; new handover remains.
- `fleet-assets/incidents/index`: six linked queue meters, period/site/asset/driver/severity/search filters, incident-type launcher, telematics preview, and safety workflow remain. The primary launcher now uses the shared header button to keep its label legible.
- `fleet-assets/inspections/index`: result summary and new inspection remain; unfiltered 30-day statistics are stated as facts rather than misleading filter links.
- `fleet-assets/keys/index`: custody totals remain as factual context; issue, return and transfer controls remain in the work area.
- `fleet-assets/maintenance/checklists/index`: template/run/failure facts, run entry and template creation remain.
- `fleet-assets/maintenance/dashboard`: six work-order/service meters retain their destinations; quick actions, period control and spend summary remain.
- `fleet-assets/maintenance/schedules/index`: due/overdue/active facts and create action remain in both page branches.
- `fleet-assets/mileage/index`: four payment/status/date meters retain their links; claim and export actions remain.
- `fleet-assets/outings/index`: active outing meter, escalation strip, plan action and filters remain. Date filters stack at narrow width.
- `fleet-assets/resident-tracking/index`: active-alert and wandering meters open the governed wandering tab; assign and device actions remain. The browser showed the OSM basemap and zero-resident empty state.
- `fleet-assets/transports/index`: medication meter retains its destination; journey, export and filter controls remain. KPI cards fit the narrow viewport.
- `fleet-assets/transports/medications`: transport-scope return, controlled-drug context and three transit counts remain.
- `fleet-assets/trips/index`: day and after-hours meters retain their date scopes; export and chart/table content remain. Charts scroll within their cards at narrow width.

The nine record or process pages use the profile header and retain their state and contextual actions. `maintenance/checklists/run` and `trips/playback` rendered in the first local browser sweep without document overflow. The other seven initially had no matching synthetic record; the fixture-backed continuation below covers their populated state.

- `fleet-assets/drivers/show`: eligibility, scorecard and HR profile actions; the safety score opens the scorecard tab.
- `fleet-assets/handovers/show`: handover status, vehicle context and acceptance workflow.
- `fleet-assets/inspections/show`: result, vehicle context and evidence workflow.
- `fleet-assets/maintenance/checklists/run`: view-only and new-run branches, with their existing completion workflow.
- `fleet-assets/outings/show`: start, cancel, return-all and complete actions remain guarded by state and permission.
- `fleet-assets/resident-tracking/history`: privacy-checking/ended and active branches retain their access boundary.
- `fleet-assets/transports/pre-check`: completion state and the transport/person context remain.
- `fleet-assets/transports/show`: journey status and medication/pre-check links remain.
- `fleet-assets/trips/playback`: consent and route state, close/delete guards, and trip-detail facts remain.

### Fixture-backed detail continuation

An isolated synthetic MySQL schema was populated with North and South records for the seven remaining routes. A North-site viewer opened all seven North records at the narrow browser viewport. Each rendered one shared header and one main heading, with no document-width overflow after two banner wrapping corrections. The consented North resident history completed its asynchronous privacy check before showing the tracker and empty movement history. The same viewer received 404 for the six South driver, inspection, outing, resident-history and transport URLs, including transport pre-check; the South handover returned the existing tested 403 site denial. No South record content appeared.

The first populated transport pre-check visit returned 500 because its emergency-contact query selected nonexistent `relation` rather than the canonical `relationship` column. The query now selects `relationship` and maps it to the existing `relation` UI prop. The page rendered on recheck, and a focused feature test with an emergency contact passed (16 assertions). The inspection and transport detail banners wrapped their status badges on the narrow viewport. Scoped ESLint, Prettier, PHP syntax, production Vite build, and `git diff --check` passed; the focused TypeScript result is recorded in the JSON evidence.

This continuation used the local production manifest at `http://127.0.0.1:8798`, a separate synthetic database, and synthetic logins. The browser viewport override requested 390×844; measured CSS document width was approximately 300–312 pixels on this host. That is a narrow-layout test, not genuine 125% browser zoom.

### Hosted release and CI disposition

Authenticated Chrome showed `https://oblivionfindings.com/fleet-assets/bookings` still using the earlier gradient hero, with `app-DpCHbvaC.js`, while the local release build used a newer manifest. The pushed header migration had not reached the hosted site at this check. The repository has no deployment workflow; `scripts/deploy-server.sh` is a broad server-side release operation involving migrations, queues, SSR and monitoring services. No configured server access, backup/recovery point or reviewed release window was established here, so hosted deployment and visual acceptance remain open.

GitHub Actions for `cbd9b3c` passed database bootstrap and failed full-repository lint, tests and visual regression. Lint reported 3,340 problems across the repository without a Fleet path in the captured log. Test shards failed first in unrelated Compliance, Control Room, Catering, Auth, Attendance and Security Devices suites; those first-batch failures do not prove all later Fleet tests ran. Captured visual failures were non-Fleet snapshots. These failures are not claimed to be caused or repaired by this focused Fleet change.

The shared header guide and approved Fleet workflows were read as source rules. No source-of-truth guide, `DESIGN.md`, historical mockup, eMAR file, or single-tenant boundary was changed. Hosted deployment and genuine 125% browser zoom remain unverified.

## Inactive legacy branches

- `fleet-assets/map`: the registered map route redirects to the geofences map tab; this source file is not the rendered page. No cosmetic edit was made here.
- `fleet-assets/assets/show`: the controller supplies the `workspace` presenter, which selects `AssetProfileWorkspace`; the legacy compact hero in the fallback branch is not reached by that controller contract. No cosmetic edit was made to the fallback.

There are 48 active pages with the shared header or report wrapper and two inactive legacy branches. That accounts for all 50 inventory entries. No active controller-rendered page remains in the legacy-hero queue; visual acceptance and external checks are tracked separately above.
