# Full audit evidence and verification limits

MAIN ASTRA. Revision 1. Recorded 2026-09-19 UTC.
Authority: Revision 10 + approved A1/A2. **Read-only audit, not implementation acceptance or production certification.**

## Baseline and access

- Workspace: C:/Users/steph/Herd/oblivionfindings; local main unchanged at `19354ecbc70046d12dfdf9c86f888e65fa1879d1`. Observed remote main: `e62b569ff42ab471300fb6713a68758b647b2c32`. No fetch/reset/merge/commit/push.
- Tracked/staged diffs empty. Programme Markdown is untracked under docs/fleet-assets-audit. Pre-existing public/.user.ini untouched, SHA `5B89B33882C30A9B7882C456BDA8503F7BD0341187CF7AC0873C5EC512F5C82A`. Git warned of inaccessible user-global ignore file; permissions unchanged.
- Main task/model/pin evidence: [register](../05-page-register.md), [Section 0 baseline](section-0-baseline.md). No worker/child/model comparison/correction run.
- Branch API: protection false, rulesets empty, merge options enabled. Webhook inspection returned 404 without admin:repo_hook scope; deployment consequences **unverified**, not absent. No access expansion.
- User reported login complete. Local Chrome tab 1051440571, https://oblivionfindings.test, Demo Admin; inspected visible synthetic QA/Playwright examples. No production inspection. Full environment provenance and restricted-role behaviour not independently certified.
- Desktop viewport 3440 × 1249; no override/device emulation/mobile tests.
- Served entry script `/build/assets/app-D-HqKYNj.js` matches local manifest app filename; no public/hot found. This supports asset-path consistency, **not exact source-commit identity**. No rebuild.

## Rendered scope

All 33 displayed Fleet sidebar destinations in [12](../12-navigation-page-inventory.md) were opened. Index views and selected profiles/tabs were inspected; every action/form state was not executed.

1. Hub: five vehicles offline but empty device-status chart; five alerts/two critical versus empty alert register; two tracked people count versus empty tracking workspace. Define source/scope/freshness before concluding which underlying records are incorrect.
2. Vehicles: five available/ready, all offline. Populated synthetic profile had Operations/Technology and Upcoming Bookings, no Calendar/direct booking; battery/speed/utilisation zero despite missing last seen; large map/header panels.
3. Bookings: list showed an old approved synthetic booking and zero current summaries. Wizard loaded Vehicle, optional Client, start/end fields and three steps, then closed without input/submission. Calendar click at 09:26 UTC briefly showed 14–20 September, then a blank Laravel-titled page at `/fleet-assets/bookings?page=1&view=calendar&week_start=2026-09-14`. Browser error: `TypeError: m is not iterable` in served `index-D0eIKDoB.js`; local bundle identifies m as vehicles. Controller sort/map preserves keys: possible serialisation cause, **not response-level diagnosis**. DOM empty; screenshot capture timed out. Navigation restored the list.
4. Assets: nine active; filters/New asset. HR register: one synthetic assigned laptop, inventory/assignment/maintenance/document navigation.
5. Maintenance: one historical completed work order; cost summaries zero without established actual cost. Schedules/checklists/inspections empty; visible create/run controls, none submitted.
6. Daily checks: five vehicles across synthetic Sites, zero checked, Current labels. Keys: five unknown custody, zero safe/checked out; preserve truthful unknown.
7. Compliance: five blank-date vehicles labelled OK and 100% compliant: direct FA-F03 evidence.
8. Geofences: two active circles, edit/disable/delete controls; no changes.
9. Tracking: zero residents/alerts yet All residents safe, Safety Score 0%, Updated just now. Devices: five active counted Online despite August last seen. No commands/consent/acknowledgement.
10. Map: no vehicle markers with absent location; Site marker. Alerts empty despite hub totals.
11. Transport, medication transit, outings, handovers: empty registers, no operations executed.
12. Incidents: initial transitional blank resolved on revisit; worklist/tabs/report/preview rendered. **No server-error finding** from initial transition; no preview/report/regulatory action.
13. Drivers empty. Trips: historic open journey with inconsistent count/distance summaries. Fuel empty with zero summaries. No personal-trip marking/export.
14. Reports: empty selected period with historic broader trend; Site usage/cost allocation lenses differ. Reimbursement IRD 0.95/km labelled 2024/25; Mileage 0.95; Community Access universal two-outing target. No generation/claim/approval/payment/export.
15. Notifications: controls and future-backend note rendered; source Save toggles temporary success only. Save not clicked.
16. Synthetic Site: overview geofence entry; shared Operations Calendar exposed Today/month/week/day/agenda/timeline and NZT. Fleet tab had no assigned vehicles despite Fleet site associations; source home_site_id versus site_id. No Site edit.
17. Synthetic Client: Relationships & governance → Consents exposes Manage/Record consent. Snapshot → Location settled on inactive consent/assignment privacy block and cached-data removal notice. Existing consent entry and that blocked case confirmed; no portal impersonation or consent change.
18. Browser finished on restored booking list, wizard closed. No persistent browser preferences changed.

Evidence used accessibility/visible DOM and an inline vehicle-profile screenshot. No saved screenshot package claimed; raw client identifiers/addresses/care content were not copied into the report. See FA-F01 for calendar failure and build limitations.

## Source and test limits

[Static inventory](source-inventory.md): route declarations, Fleet TSX paths, control/action anchors, related jobs/commands. Read effective route groups/controllers; this is not a runtime permissions dump. Traces covered Asset/lifecycle/assignments; booking access/transitions; inspection evidence; keys; maintenance/incidents; Site/calendar; Client requests and medication journeys; device/privacy/telemetry/portal; geofences/outbox; tasks; Finance events/observers; reporting/maps/settings.

[Findings](../10-full-audit.md) distinguish exact source/rendered defects, bounded not-found results, proposals and uncertainty. [Integration matrix](../13-integration-matrix.md) records owners/triggers/effects/recovery. Source existence is not successful downstream delivery.

Test sources indexed in [coverage](../11-coverage-register.md) were inspected/discovered, **not run**. No unit/feature/browser suite, build, migration, seed, queue or benchmark executed. Runtime create/approve/release/import, concurrency, restricted-site/direct-object, portal sharing/withdrawal, accounting rollback, telemetry replay and provider outage remain unverified pending isolated fixtures and exact-revision evidence. No live exploit attempted or production breach claimed.

## Primary reference checks and remaining applicability

Public sources checked 2026-09-19; no paid provider/configuration test:

- HDC Right 7 covers informed choice/consent and presumption of competence. Validate specific authority and exceptions before implementation; do not infer authority solely from relationship. [HDC Code](https://www.hdc.org.nz/your-rights/about-the-code/code-of-health-and-disability-services-consumers-rights/).
- OPC describes HIPC health/disability coverage and links the version in force 1 May 2026. Exact collection/disclosure/notice/retention/emergency applicability needs review; no legal certification. [OPC HIPC](https://www.privacy.org.nz/privacy-principles/codes-of-practice/hipc2020/).
- IRD's 2025–2026 rates vary by vehicle type/tier and period. Finance must approve applicable reimbursement methodology/effective history; do not replace universal 0.95 with another universal number or reprice past claims. [IRD rates](https://www.ird.govt.nz/income-tax/income-tax-for-businesses-and-organisations/types-of-business-expenses/claiming-vehicle-expenses/kilometre-rates-2025-2026).
- OSMF requires visible attribution and service-specific usage/caching compliance; public tiles are not unlimited capacity. [Tile policy](https://operations.osmfoundation.org/policies/tiles/).
- Google distinguishes application/API restrictions and client/server usage. Configuration, costs, terms and failures remain unverified. [Maps security guidance](https://developers.google.com/maps/api-security-best-practices).
- NZTA WoF/Road Code references were located, but full-page retrieval failed (including 403). No exact legal interval/class/applicability certified from snippets. Validate actual vehicle-class WoF/CoF, licensing and RUC before implementation. [WoF reference](https://www.nzta.govt.nz/vehicles/warrants-and-certificates/warrant-of-fitness), [car requirements](https://www.nzta.govt.nz/driving-skills/learn-to-drive/roadcode/general-road-code/about-your-vehicle/car-requirements).

## Exact amendment/source protection checks

Master SHA remains `1F22F349A174FEC068E7BC14829F5E4099E0D922AA55EE09CEE6FC0C8722F2B1`. In-memory removal of amendment block and reversal of five substitutions recreated original SHA `86DF7F586D2DD25816A3558053B42818083900AFCB7D5BC5ED48D59BA53298DA` exactly. **No other original requirement text changed.** Verification did not write the master.

DESIGN.md and all nine located approved guides exactly match pre-amendment hashes:

- DESIGN.md: `D2893FF462CAF35A7B9CBA2B3EE61E2A3BCD270D62CE822169BB71921D789879`
- APP_SHELL_STYLE_GUIDE.md: `4F433617E4D0DF8B51795F6CEB0DBB125913803949DE8FB421E7DD9624469201`
- BUTTON_STYLE_GUIDE.md: `86259475C987D6E30001604316379EB608AEA9F83E16941B6AA476A94BCA6957`
- CALENDAR_STYLE_GUIDE.md: `D3597517A0EBF0D6451442ABF1C2E1836F3C69E45E1C920B0F65DB55B1EF5ECB`
- DESIGN_TOKENS.md: `C3EE6C8ACAA22049084F819E3AD2A33F457E936E467492079F16C15E9A611D2B`
- LIST_STYLE_GUIDE.md: `56B59B464FD46917446B482B8E355D47D7341105915F6C016B6195E2A58138AC`
- LOADER_STYLE_GUIDE.md: `CC20485E6E42E0A848B8CACC8AC6124E5E5A01206D966DE6EBBBDEC39DBE2A91`
- NAVIGATION_STYLE_GUIDE.md: `9295CDB1386322B8A088AE03DE41BC3C75A0FBE3E8FA3331C92C704CCB15895D`
- PAGE_HEADER_STYLE_GUIDE.md: `33AEEB0E4434D3BB4D3023E3CE95FFEE12E95BA1FE62C02F36601F8219827FAA`
- POPUP_STYLE_GUIDE.md: `AEE723E21491E9BE908554A6F200D73204280A63D9028EA78DE6BB9049F4A477`

Only approved master amendments and derived programme/context Markdown written. Historical PLAN.md and prior source documents preserved. No application code, source design guide, dependency, schema or operational record edited. **Stop at Section 12 approval; no design or implementation started.**
