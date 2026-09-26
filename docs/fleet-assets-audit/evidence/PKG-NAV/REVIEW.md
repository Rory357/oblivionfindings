# PKG-NAV exact-candidate review

Status: ready for Main's required pre-integration review; **not published**.

Task: `01a0dcfe-f6cd-7873-8eaf-36229ac9c59a`. Worktree: `C:/Users/steph/.codex/worktrees/475b/oblivionfindings`. Branch: `codex/fleet-seven-entry-navigation`.

Base: `fa7b5291988cebfb6beaa6e6e10c6c660fb2a959` (clean detached starting checkout; independently matched remote main before implementation). Exact implementation commit: `02381fad5e86727eba565696d9209686e2d76b3a`. The later evidence-only commit is the review candidate head supplied with this packet. Use `git diff fa7b5291988cebfb6beaa6e6e10c6c660fb2a959 02381fad5e86727eba565696d9209686e2d76b3a` for the complete application/test diff.

Actual session turn metadata verified `gpt-6-astra` / `xhigh` before writes. No workers or sibling task contacts. Authority is Main's current absolute-path `handoffs/PKG-NAV-IMPLEMENTATION.md`; current 00/03/05/09 headers and approved 12/14 were read from Main, not the stale worktree copy. Rory's DESIGN and shell/navigation/header guides were read and are unchanged.

## Result and file boundary

The existing expanded rail and collapsed flyout now render exactly seven primary destination links for a fully authorised viewer: Overview, Fleet, Assets, Maintenance, Maps & boundaries, Reports, Settings. The same renderer, shell colours, focus styles, collapse persistence and module gate remain.

Four runtime files and one meaningful UI test file changed:

- `resources/js/components/app-sidebar.tsx`: replaces the 33-link builder; adds Fleet-only workspace matching and preserves contextual destinations in command search. No other module builder changes.
- `resources/js/lib/fleet-navigation.ts`: one permission-filtered route map for primary landings, focused menus, search and nested-route selection.
- `resources/js/components/fleet-assets/fleet-workspace-navigation.tsx`: real Inertia links and short existing Radix menus (maximum three entries), with keyboard/Escape behaviour. Active register links retain the current query.
- `resources/js/layouts/app/app-sidebar-layout.tsx`: adds this Fleet-only context alongside the existing breadcrumb strip, including legacy Fleet pages with too few breadcrumbs. Unrelated pages keep their existing layout. This is navigation chrome, not a replacement page tab rail or header design.
- `resources/js/components/fleet-assets/fleet-workspace-navigation.test.tsx`: rendered primary/secondary navigation, nested URL ownership, denied-role filtering, safe landings, query retention and search regression checks.

No page body, Vehicle Profile file, backend, route, controller, service, schema, policy, permission grant, operational data or design-rule file changed. Single organisation across sites; existing role, approved-site, record and privacy checks remain server-owned.

## Reachability: all 33 original destinations

Paths below are unchanged and relative to `/fleet-assets` unless absolute. Every Fleet-owned page keeps the current workspace's contextual menus and its primary return link. Selecting its workspace landing returns to the register/queue; browser Back retains the previously filtered URL. External owner links keep that module's navigation, with browser Back or the Fleet sidebar providing return.

1. Dashboard `/fleet-assets` → Overview landing.
2. Live Map `/map` → Maps & boundaries → Map.
3. Daily Checks `/daily-check` → Maintenance → Checks & inspections → Daily checks. Existing My Day/Site/vehicle paths untouched.
4. Vehicles `/vehicles` → Fleet landing / Vehicles return link.
5. Trips `/trips` → Fleet → Operating records → Trips.
6. Fuel Logs `/fuel` → Fleet → Operating records → Fuel logs.
7. Compliance `/compliance` → Fleet → Operating records → Compliance.
8. All Assets `/assets` → Assets landing / Inventory.
9. HR Asset Register `/hr/assets` → Assets → HR asset register; HR retains ownership/navigation.
10. Alerts `/alerts` → Overview → Alerts & safety → Fleet alerts; alongside permission-scoped canonical Control Room.
11. Geofences `/geofences` → Maps & boundaries → Boundaries; existing Site editor unchanged.
12. Maintenance Overview `/maintenance/dashboard` → Maintenance → Work → Maintenance overview.
13. Work Orders `/maintenance/work-orders` → Maintenance landing / Work → Work queue.
14. Service Schedules `/maintenance/schedules` → Maintenance → Service schedules.
15. Checklists `/maintenance/checklists` → Maintenance → Checks & inspections → Checklists.
16. Inspections `/inspections` → Maintenance → Checks & inspections → Inspections.
17. Drivers `/drivers` → Fleet → People & custody → Drivers & eligibility; existing HR source unchanged.
18. Vehicle Bookings `/bookings` → Fleet → Bookings.
19. Key Management `/keys` → Fleet → People & custody → Keys.
20. Resident Tracking `/resident-tracking` → Maps & boundaries → Client location → Authorised client tracking; alongside the canonical Clients directory when permitted. Both operational access AND `assets.telemetryView` are required for the specialist link.
21. Transport Logs `/transports` → Fleet → Transport → Transport journeys.
22. Medication Transit `/transports/medications` → Fleet → Transport → Medication transit. Existing logistics/eMAR write gates unchanged.
23. Outings `/outings` → Fleet → Transport → Outings.
24. Shift Handovers `/handovers` → Fleet → People & custody → Shift handovers; My Day/Site paths untouched.
25. Tracking Devices `/devices` → Maps & boundaries → Devices → Tracking devices & pairing; alongside the permitted Security & Devices registry.
26. Fleet Incidents `/incidents` → Overview → Alerts & safety → Fleet incidents; existing H&S sidebar path retained.
27. Reports & Analytics `/reports` → Reports landing.
28. Usage by House `/reports/by-house` → Reports → Resource use → Usage by house.
29. Mileage Reimbursement `/reports/reimbursement` → Reports → Costs & mileage → Mileage reimbursement.
30. Mileage Claims `/mileage` → Reports → Costs & mileage → Mileage claims; current Finance/staff functionality retained.
31. Cost Allocation `/reports/cost-allocation` → Reports → Costs & mileage → Cost allocation.
32. Community Access `/reports/community-access` → Reports → Resource use → Community access.
33. Notifications `/settings/notifications` → Settings landing / Notifications.

Browser-derived [reachability JSON](browser-reachability.json) contains every actually opened workspace/menu. Compared with hrefs extracted from the base builder: **33 old links, 36 unique reachable links, zero missing**. The three additions are existing canonical owner routes, not new screens.

## Verification

- Focused Vitest: **4 files, 61 tests passed** (new navigation tests plus all three existing app-sidebar test files). Used the unchanged root test settings with a local cache and `process.cwd()` substituted for `__dirname`, because Vite's runner loader does not define `__dirname`. Default sandbox esbuild access failed; approved escalation ran the tests successfully. Reproduction files are in [verification](verification/).
- ESLint with `--max-warnings=0` on all five changed files: **pass**.
- TypeScript program rooted in all four changed runtime files, using repository compiler options and checking transitive dependencies: **0 diagnostics**. This is a scoped check, not an application-wide typecheck claim.
- `git diff --check`: **pass**. Only scoped implementation/test and PKG-NAV evidence are committed.
- Real Chrome browser on isolated Vite port **8876**, importing the actual AppSidebarLayout, AppHeader, AppSidebar, FleetWorkspaceNavigation, Inertia client and application CSS from worktree **475b**. No alias to a fake navigation component. The page body and auth props are explicit synthetic fixtures; the server accepts only fixture GETs, has no database and performs no operational writes. It does not alter another server or `public/hot`.
- Measured CSS viewports **1440×1000** and **1280×900**, DPR 1.25, no horizontal page overflow. Also checked the collapsed flyout at measured 1024×720. [Browser identity and console](browser-identity.json); no recorded warning/error entries at final verification.
- Clicked all seven primaries and opened every contextual menu. Medication transit click reached `/fleet-assets/transports/medications`; Fleet remained selected; Back returned to Vehicles.
- Nested vehicle `/vehicles/42?group=operations&view=calendar` selects only Fleet, not Overview. Unit cases also cover trips, journeys, handovers, asset details, checklist runs, inspections, tracking history, devices, boundaries, mileage, report details and incident modal query state.
- Fuel fixture started at `/fleet-assets/fuel?site_id=7&page=2#logs`; current Fuel link retained both query parameters; Trips then Back restored the complete original URL including fragment. Inertia's server `page.url` excludes the initial fragment, so the current-register link itself does not independently claim to retain an initial fragment. [Interaction evidence](interaction-results.json).
- Enter opens menus; Escape from a focused item closes and restores the trigger with `:focus-visible`. [Settled focus and role results](browser-roles.json) supersede the earlier immediate-focus sample in interaction-results.
- Assigned-asset fixture: Overview, Assets, Maintenance→daily checks, Maps; no denied work queue, Fleet register, Reports or Settings. Asset-manager fixture: Fleet→Bookings and Reports→Mileage claims, avoiding denied primary landings. No-telemetry fixture: no tracking link. Staff with no Fleet/assets permissions: no Fleet module. Server site/record denial was not re-exercised against operational users; unchanged backend checks retain that responsibility.

Screenshots: [seven links](01-seven-links.png), [transport menu](02-transport-menu.png), [nested vehicle](03-nested-fleet.png), [assigned assets](04-assigned-assets.png), [collapsed rail](05-collapsed-desktop.png), [1280 desktop](06-desktop-1280.png).

## Bounded limitation for Main's review

`HandleInertiaRequests` exposes `fleet.viewAny`, but does **not** expose the separate existing `fleet.reports.view` permission. Report GET routes require one of those two permissions; unrelated `reports.viewAny` and `assets.viewAny` do not suffice. This candidate therefore shows analytics links on the proven `fleet.viewAny` signal and uses Mileage claims as an asset manager's permitted Reports landing. The existing Reporting module's Fleet Reports entry and all backend URLs are untouched. A specialised report-only user cannot be fully represented by the new Fleet contextual analytics links without adding a read-only projection of that existing permission. That middleware adapter is outside the explicit frontend file boundary and has not been silently added. Main should decide this narrow residual scope point in the exact-candidate review; no grant or route weakening is proposed.

Live Laravel page bodies, actual database roles/site records, global production build and full application CI are not certified by the fixture. No production activation or prior package acceptance is implied.

## Integration gate

Main must approve this exact source candidate and disposition the limitation before publication. Then fetch latest remote main, preserve concurrent published work, reconcile only this reviewed scope, revalidate any affected resolution and publish without force. GitHub main was read as unprotected at the base; authenticated account access was verified through the escalated read-only CLI. No PR, merge or push has occurred at packet creation. Record the actual remote source-to-integrated mapping and check statuses after publication; do not equate review readiness with completion.

To reproduce the fixture, copy the five files in `verification/` to `.fleet/pkg-nav/` in this checkout, then run `node node_modules/vite/bin/vite.js --config .fleet/pkg-nav/vite.config.ts --configLoader runner`. The fixture intentionally identifies itself and has no backend connection. Test command: `npx vitest run --config .fleet/pkg-nav/vitest.config.ts --configLoader runner resources/js/components/fleet-assets/fleet-workspace-navigation.test.tsx resources/js/components/app-sidebar.test.ts resources/js/components/app-sidebar-inline-navigation.test.tsx resources/js/components/app-sidebar-role-filter.test.tsx`. Type command: `node .fleet/pkg-nav/check-types.mjs`.
