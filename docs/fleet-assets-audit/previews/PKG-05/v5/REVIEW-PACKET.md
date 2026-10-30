# Transport v5 — visual builder, dedicated calendar and consistent headings

Status: finished synthetic desktop design candidate for Stephan's review. This preview replaces the planner experience and corrects the layout across every Transport view. Application implementation remains outside this design release.

Open [the visual planner](http://127.0.0.1:4399/#/fleet-assets/transports/planner). Start with Elliot White, choose **Choose vehicle & team**, select **Rimu** and **Liam Chen**, then **Review this plan → Check availability → Save transport plan**. Use **See in calendar** to find that exact linked booking. Reload restores the example records and clears drafts.

## Design decision

The easiest starting point is a guided visual builder: one passenger request, three short steps, and a live picture of the plan. A request queue stays on the left; the current decision is in the centre; the route, times and chosen resources remain visible on the right. The next action stays at the bottom of the visible editing area. Wider desktops show vehicle choices side by side.

1. **Journey & time:** confirm pickup, destination, expected return and the full booking window. Existing shared calendar/clock controls handle date and time. Assessed seating, accessibility and escort needs are visible.
2. **Vehicle & team:** search visible vehicle cards. Suitable choices state capacity/site fit; unsuitable choices explain why they cannot be selected. Known unconfirmed returns appear on the vehicle card and driver choice. Select a driver, required escort and equipment without navigating away.
3. **Review & save:** see the whole proposal, change a specific section, and check all selected resources together. A successful current result enables saving. The confirmation explains the next booking decision and offers the same booking in the calendar.

The calendar remains one click away. It uses the same Fleet booking identities and the existing shared Month, Week, Day, Agenda and Timeline components. Transport is a focused view of those bookings. This candidate does not introduce another reservation engine or automatic route optimisation.

## Three improvement passes

- **Clarity and visual structure:** replaced the long allocation form with the three-step workspace; added visible searchable vehicle cards, request filters, a persistent plan summary, compact shared date/time fields and a sticky action bar. Existing hero meters, search, scope filters and workspace navigation remain.
- **Working interactions and recovery:** added explicit Keep draft, Reset, and keep/discard before switching requests, opening a full record or viewing the calendar. Temporary stale/error source states retain the preview draft, while returning to ready requires a fresh availability check. Role changes or denied access clear private drafts. Saving from the Ready filter keeps the confirmation visible; changing dates updates the selected date without losing the saved record.
- **Availability, permissions and verification:** availability now inspects the same synthetic booking records for vehicle, driver, escort and shared-kit overlap. Checked-out vehicles and crew awaiting return are not silently released by a scheduled end time. Plan changes, request/source versions and source availability invalidate previous checks. Unknown setup cannot be fixed by a generic Refresh button. Read-only and denied states were exercised along with desktop resizing, keyboard focus, context-menu entry and retained source connections.

## Calendar and heading correction requested during review

Calendar is now a dedicated Transport tab with its own route, [Transport calendar](http://127.0.0.1:4399/#/fleet-assets/transports/calendar). Its page uses the shared PageLayout and PageHeader, with Month / Week / Day / Agenda / Timeline connected to the hero and Back to Planner. The first hero meter shows the viewed day, full month and year. Previous, Next, Today and shared JumpToDate update it. Shared CalendarSourcePills, CalendarContextMenu and TodayRail replace the previous custom toolbar and explanation banner. Vehicle and Site controls stay in the hero. Timeline scrolls within its own surface and brings the selected date into view.

All six workspace headings sit immediately below the hero at the 20-pixel rhythm, with the shared text-section-title typography and Home-rooted breadcrumbs. Queue status filters moved into the hero. Results are counted once in the content heading, and export is alongside it. Returns help is a collapsed explanation below its heading. The five original operational views remain, with Calendar added at Stephan's explicit request.

Blank calendar times open the existing transport-request modal with that date/time prefilled. Visible Request transport reaches the same form. Existing entries open their linked Fleet booking; right-click and Shift+F10 use the shared calendar menu and preserve source actions. Rescheduling remains in the linked plan builder, with no independent drag mutation. Date navigation now preserves focus within the calendar instead of moving it to the page root.

## How the retained workspace fits together

Requests and approval actions retain their source identity. Requesting transport does not require knowing the vehicle. Assessment and missing information are addressed before the builder. The saved proposal references one Fleet booking; an authorised decision and preparation still follow. A saved plan does not record departure or create a completed journey.

Journeys keep passenger preparation, departure, return, accountability and required source handoffs distinct. The prominent next action identifies its owner. An actual passenger journey can link explicit vehicle-trip history entries, subject to separate source access. Search and PDF export remain available for records/history and permitted vehicle movements.

Returns use quick-view modals with Summary, Keys & site and History, plus the full record when more detail is needed. A receipt, later missing-item receipt, actual key-safe placement and optional shift handover remain separate observations. The current vehicle key holder can be newer than the historical receipt being viewed. Search covers vehicle, passenger, receipt, key, worker and site-point terms. The planner's summary links the collection/return arrangement conceptually to the saved booking; it does not attest physical custody.

## Source ownership and reuse

- `ClientTransportBooking` owns the transport demand and its minimal permitted support needs. Assessment and booking decisions retain their existing source boundaries.
- `FleetVehicleBooking`, Fleet readiness and booking controllers own reservation identity, permission and availability. `planning.ts` only demonstrates a proposed adapter against the shared in-memory booking objects. It has no production transport or independent persistence.
- `ResidentTransportJourneyService` remains the passenger-journey owner. Medication source action, passenger completion and vehicle return are independent.
- Shared `PageHeader`, `EntityTable`, entity context/kebab menus, `WizardShell`, status primitives, searchable pickers, `DateTimeField` and Site calendar components are reused read-only. The builder uses the approved full-page planner exception to modal creation forms. Preview-owned CSS compacts the imported controls and arranges cards; no shared source was edited. The preview clamps context-menu starting coordinates before rendering the shared menu.
- `FleetKeyLog`/key and booking controllers own key custody. Existing string locations are not proof of a stable secure Site/room/storage-point relationship. Named storage points, delivery arrangements and separate physical storage confirmation remain proposed contracts.
- `VehicleTripHistoryController` and `VehicleTripHistoryService` own vehicle history, filtering, export and privacy. Booking attribution exists, but a stable passenger-journey-to-vehicle-trip link still requires an approved production mapping. Example links are explicit and do not infer tracker movements from nearby times.

## Verification

- **119 passing browser assertions**: 17 core builder checks, 30 recovery/permission/layout checks, 17 retained search/PDF/source checks 9 final date/discard/entry checks, and 46 calendar/navigation/heading checks. No runtime errors were observed in successful runs.
- **81 passing synthetic domain checks**: 22 planning validations, 34 lifecycle checks and 25 custody checks.
- **3 local server checks**: exact local origin required for PDF POST, malformed payload rejection, static preview availability.
- Zero preview or imported-source TypeScript errors. Served runtime hash is compared with the built file.
- Desktop widths 1024, 1280, 1440 and 1920 passed overflow and visible action-bar checks. Wide cards, time editing, date editing, step focus and context-menu entry were exercised in the browser. Actual browser zoom is unverified; mobile is outside this brief. The example Today control and Today rail use 28 September 2026; shared calendar date highlighting still follows the browser clock.
- Two real filtered PDF downloads were smoke-tested. The renderer and layout are unchanged from v4; a new PDF rendering/layout audit is not claimed here.
- **327 prior frozen files** are checked unchanged across v1, audit-v1, v2, v3 and v4. Source and reference hash evidence is included.

A source-state test exposed draft capture happening after the editor unmounted. Capture now happens before the source-state change. A Timeline test exposed horizontal page overflow from intrinsic grid sizing; the calendar now contains it. Calendar day changes also exposed a delayed page-focus reset; focus now remains with date controls. A request menu test initially clicked before navigation/scroll settled; the final test waits for the target row before right-clicking. Final logs contain successful runs. `review-*` images show iterations; `final-*` show verified layouts. One-off integration scripts document authoring and must not be rerun over the finished candidate.

## Remaining implementation work and limits

This is browser-memory design behaviour, not an application release. Real availability, atomic concurrency, approved driver/escort eligibility, equipment identity/quantity, buffers, pending-reservation hold policy, approval configuration, persistence, audit logging, notifications and source deep links require their canonical production contracts. No new defaults are authorised by the example fixtures. Real return and independent vehicle release must remain separate source decisions.

Kept drafts intentionally clear on reload or a role/access change. Durable drafts and conflict recovery against live changes need a separately approved implementation. The equipment examples do not establish a production inventory or item identity. Key storage locations, scoped staff custody authority and source movement links remain unapproved mappings described above.

One operating organisation, approved sites, roles, direct-object permission and source privacy remain the application boundary. No application/backend/schema/shared component, protected guide, Main file or sibling checkout was changed. No agents, new chats, sibling messages, production data, notifications, uploads, push, merge or deployment were used. Repository-wide CI and backend security were not exercised.

Same pinned Astra/xhigh task and isolated checkout, HEAD `4ea64c547ed85a5b7504e59599db351f6eba7deb`. Model/effort, Main references, canonical Revision10 and source hashes are in `context-manifest.json`. This candidate preserves the exact-design approval boundary in the PKG-05 Designer brief. Its runtime and artifact identities are in `verification.json`, `artifact-manifest.json` and `FREEZE.txt`. Further corrections should create a new version.
