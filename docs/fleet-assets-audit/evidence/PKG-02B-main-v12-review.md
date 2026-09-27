# PKG-02B — Main v12 scope and design review

Owner: MAIN ASTRA. Revision 1. 22 September 2026. **Changes requested in the synthetic mockup. No implementation or integration release.**

## Authority and exact candidate

Main independently read the actual user messages in Designer task `01a0c2bb-fcff-7cb1-8bab-882d84477c6c`, rather than treating the Designer's relay as new authority. On 22 September at 03:05:35.513 UTC Stephan said **“ok let main know”**. That lifts the earlier notification hold and authorises this handoff/review; it does not approve v12 or implementation. The actual latest turn `01a0c713-714b-7790-b9aa-f63c110a0eb0` uses `gpt-6-astra` / `xhigh`. Verify that configuration again on the next continuation before substantive writes.

Candidate `df987809c76e76d586f2ed62033f1f7748bb50b6ddfab9a7e36d5237fbdad99a`; preview http://127.0.0.1:4347/PKG-02B/v12/#/fleet-assets/vehicles/14/compliance; worktree `C:/Users/steph/.codex/worktrees/5b0a/oblivionfindings`, branch `codex/pkg-02b-vehicle-profile-design`, base `5307692ec59be84f3503c06354419b7da95be805`.

Main independently verified all **67** v12 manifest entries: **52 preview files and 15 evidence files**, zero byte/hash mismatches. Candidate reconstruction matches using repository-relative paths in ordinal order, TAB, lowercase SHA256, LF. Earlier attempts using culture-insensitive PowerShell sorting / paths relative to the preview folder did not reproduce the identity; correcting those verification assumptions resolved the mismatch without any file change. Manifest SHA256 `D21682123259EF0E9A528A8AD0360F2612344A0BC18B074E036EB57BC340DD81`. All **109** v11 entries independently remain unchanged.

The browser loaded v12 and references `index-CPuTUEjI.js` / `index-BaqBSZ-_.css`, matching the verified frozen assets. The browser blocked the JSON `/__preview` navigation; Main did not bypass it. Source inspection shows the local server serves this version's dist only, with a bounded synthetic map-tile proxy. Browser identity evidence is the actual title/version/asset paths and inspected server, not a successful identity-endpoint response. No production application writes appear in the Designer's status: only two approved guide additions, one new guide, and PKG-02B previews/evidence.

## Verified user-requested scope

These direct requests expand the earlier compact profile mockup. They are recorded as design requirements and cross-package interfaces, not permission to implement every dependent module in one slice. UTC provenance comes from response-item user messages in the Designer rollout `C:/Users/steph/.codex/sessions/2026/09/21/rollout-2026-09-21T18-51-35-01a0c2bb-fcff-7cb1-8bab-882d84477c6c.jsonl`.

- **21 September 20:04:** complete calendar/context actions, booking approval-required versus approval-not-required with reason/evidence, dedicated trips, vehicle photo and service/check uploads.
- **20:52:** universal editable vehicle checklists; shared geofencing based on inspected Client Location; service-type choices with Add custom; calendar-month intervals; searchable modal choices. Stephan separately and explicitly requested adding the geofencing and choice rules to Rory's design files. This is a narrow exception to their read-only default.
- **21:16:** mileage history and reminder creation/calendar workflow.
- **21:25 and 21:26:** tracker feature research and confirmed **GV500CG** model; automatic distance with retained dashboard cross-check, power/ignition and accident/other alerts.
- **21:48 and 22:10:** faults and overspeed through Control Room triage, with Control Room deciding whether to create/link Maintenance; transparent vehicle/driver/per-trip insights; compact map menus and state icons. **22:25** authorises the proposed recommendations within the mockup, not production implementation.
- **22:46:** compact trip map/inspector and branded multi-day PDF/Excel reports with maps; speed-limit-source exploration. **23:32:** persistent searchable Add new catalogues, vehicle evidence uploads and Finance linkage. **23:40:** searchable driver and location-aware timeline.
- **22 September 00:08/00:11:** trip layout refinements; **00:21:** timeline List/Card/Table; **00:28/00:29:** additional calendar actions; **00:48:** readable List/Cards throughout appropriate collections; **01:28:** expiry entry alongside its action and a further workflow-gap audit.

Preserve the following contracts before any implementation release:

1. **Universal boundaries:** one canonical shared geometry/identity/version/owner, reused by sites, houses, clients, vehicles and future maps. Assignments, schedules, exceptions and monitoring authority are separate; selecting geometry does not activate monitoring. Reconcile existing AssetGeofence and Client Location assignment/history contracts. Do not revive retired GeofenceZone or add a second registry. Global rollout and privacy/monitoring activation require their own reviewed slice.
2. **Universal vehicle checklist library:** canonical Maintenance/Fleet checklist ownership, typed and ordered questions, required evidence, assignment/scope, published/retired versions and immutable submitted questions/answers. A failed check creates or links canonical Maintenance under the approved assessment rules; a pass or completed work cannot release a restriction. Operational template content is still owned/approved by the responsible operator.
3. **Reusable catalogues:** service types and calendar-month/distance intervals plus other configurable choices use searchable canonical identities, explicit Add new, duplicate prevention, creation permission and history. Canonical people/providers/Finance records use their owning workflows; unique references stay identifiers and lifecycle states remain controlled. The prototype's browser persistence is not central production storage.
4. **Calendar and bookings:** five views, date/time context menus and prefilled forms, source-owned updates, conflict/readiness/driver/custody/return checks. Approval-not-required needs authorised evidence/reason and never bypasses readiness. Reminders use shared Tasks/Calendar; closing one does not fulfil its obligation. A due date or estimate is not a reservation. This expands the design scope of PKG-02B into PKG-04 interfaces; no separate competing calendar is authorised.
5. **Tracker and distance:** GV500CG is the user-confirmed target; preserve the research's conservative power-only / capability-gated design, distinct supply voltage and tracker battery, calibrated distance versus retained dashboard observations, reconciliation, stale/gap/device-swap/reset handling and source-owned compliance dates. Main did not independently certify manufacturer/firmware/provider support in this review. Exact protocol/hardware and accepted mileage/RUC use remain technical/operational prerequisites.
6. **Control Room and scoring:** source/correlation/time/location/trip, receipts/acknowledgement/escalation/triage and human confirmation for suspected collisions. Control Room decides create/link Maintenance. Alert closure, work completion, authorised release and Finance approval remain separate. Versioned scoring must show coverage, minimums, verified-driver attribution and review/dispute/recalculation. Overspeed alert routing does not depend on score eligibility. Illustrated tolerances, deadlines and scoring weights are not adopted policy. Road-limit licensing, NZ coverage and matching are unverified production dependencies; unknown remains unknown and temporary manual values require evidence/effectivity/expiry.
7. **Documents, Finance and exports:** source-owned evidence with metadata/version history, grouped-file renewal identity, optional expiry and explicit pause/archive behavior; document/profile disagreements stay visible. Finance owns approvals/posting and linked records, with no duplicate cost from order plus invoice. Trip exports retain source/coverage, maps and selected date range; private durable storage, authorised delivery and report access/pagination require implementation review.

Single operating organisation: approved sites, roles, canonical ownership and privacy remain the boundary. No new tenancy, live policy, backend framework or operating configuration follows from these mockup requests. Main will bound implementation only after corrected exact design approval and shared-contract reconciliation. Earlier Maintenance and Client Location operating acceptance and unfinished scope remain open.

## Design-reference permissions

Main inspected the baseline diff: DESIGN.md adds five lines and POPUP_STYLE_GUIDE.md adds twenty lines; a new MAP_GEOFENCING_STYLE_GUIDE.md supplies the requested reference. This is additive scope expressly requested at 20:52 and extended by the 23:32 catalogue/document request. It does not grant general rewrite or migration permission. Main made no guide edits; these current Designer references are read-only for subsequent work unless separately authorised.

Current reference SHA256: DESIGN.md `A9A47765FF45916319DB0BF97A4C6B181955C4FA8E108363E4F2CB3B657F2E76`; POPUP_STYLE_GUIDE.md `853541BA9A59BCB2D91350D62563C7A7AF612D06429B043A6FE75A298948607B`; MAP_GEOFENCING_STYLE_GUIDE.md `810ED32D26452B54B96B6B24AC4557BD4ADAE96CEE8E7CDD6AA95C978A087FCE`. These additions are not yet published to main. Canonical Revision 10+A1–A6 remains unchanged at `C4837AB675F9DFFDB6A8597636F49D5761DA114E6C155DC08E6BB8A209D63FD0`.

## Main's independent rendered checks

Actual in-app browser, hidden temporary review tab, synthetic data only:

- Desktop compliance layout; changed Registration from 8 to 15 October with the visible synthetic PNG and readable review; saved source/date/file count propagated to Documents and calendar.
- Failed CHK-0182 retains original answers/version and opens WO-0264 with the original source. Its active restriction and separate release decision remain explicit.
- Calendar exposes Month/Week/Day/Agenda/Timeline and date context actions. An empty date retains the active-restriction warning. Date context prefilled 22 September 10:00–11:00. Booking approval route shows authority/evidence and readiness acknowledgement.
- Insurance upload prefilled POL-DEMO-14 / 31 December. Renewal suggested editable 1 December 09:00. Searched Equipment, selected Equipment Coordinator, retained backup, attached a synthetic PNG and saved one document with its visible renewal link. Registration evidence remained a separate source.
- Finance presents canonical linked order/invoice/fixed-asset concepts without adding their amounts together. Report-only Finance shows access required; denied vehicle scenario hides vehicle details/actions.
- Selected the existing shared pickup geofence; both linked boundaries stayed Inactive. Telemetry distinguishes virtual ignition, dashboard observation, estimated tracker distance, supply voltage and backup battery. Alert/scoring pages expose illustrative policy, unverified road limits, source/coverage and Control Room separation.
- Browser console inspection returned zero errors. This does not validate backend enforcement or every scenario/desktop/browser combination.

Designer evidence independently read: v12 AUDIT, v7 gap audit, v5/v6 research and v6 export review. Designer reports TypeScript/Vite and focused document regressions passing. Main did not rerun those suites or treat reported checks as its own execution. Native picker transfer, native Excel picture rendering, unchanged export-engine reruns and genuine browser zoom remain unverified here. The earlier PDF transliteration/font limitation must be corrected before production report acceptance. A narrow/mobile viewport is not genuine zoom evidence or a new mobile product requirement. Do not reuse PKG-02A's zoom confirmation for PKG-02B.

## Changes requested

### B01 — unresolved compliance can remain Ready and confirm a booking (P1)

**Independently reproduced in v12:** choose Ready scenario; Service & compliance → WoF Update dates & evidence; keep Applicable, reference and future date, change outcome to Needs assessment, review/save. WoF now visibly says Needs assessment while vehicle header still says Ready. Create a future Training booking for 23 September 09:00–10:00, Approval not required, synthetic authority reason and readiness acknowledgement. Saving produced **BOOK-DEMO-1 Confirmed** and a Check out action despite the unresolved WoF. Calendar's own summary says Review readiness, further exposing inconsistent projections.

Sources: preview `main.tsx:237`, `operations.tsx:1858`, `operations.tsx:2239`, `operations.tsx:1199` test Unknown applicability / missing reference / Failed / expired date but omit an applicable Needs assessment outcome. A retained old reference/date cannot resolve a new assessment. Correct consistent readiness and all confirmation/checkout/release paths; allow unresolved observations to be saved without fabricated evidence, but keep affected use decisions blocked. Preserve valid evidence-backed Not applicable. Add a meaningful synthetic regression for the exact transition and both booking approval routes, not only the badge.

### B02 — RUC-range boundary is omitted from some use decisions (P1, source-evidenced)

Header and automatic no-approval confirmation inspect `c.high > 0 && odo > c.high`. Explicit booking approval/checkout (`operations.tsx:2239`) and authorised release (`operations.tsx:1199`) do not. Thus the same recorded range has inconsistent effects depending on entry point. Main has not browser-reproduced those latter routes. Designer must reproduce a recorded applicable range ending below the observed odometer, then make all relevant decisions use the same configured coverage/readiness contract. Include initial request, subsequent approval, checkout and release, with retained manual/estimated provenance; do not invent a legal threshold or weaken Not applicable handling.

### B03 — past pickup error appears on the approval step (P2)

**Independently reproduced:** calendar's general Request booking opens 22 September 09:00 in a preview whose clock is 09:30. After filling purpose and approval fields, Continue reports Choose a future booking or unavailable period on **Approval & evidence**, not Vehicle & times. `operations.tsx` validationSection only routes end-before-start/conflicts to section 0; past-start falls through to section 2. Use a valid default (or require selection) and place this date error on its editable step with focus. Preserve deliberately historical record editing where supported.

## Disposition and next gate

Return B01–B03 to the **same** verified Astra/xhigh Designer for bounded mockup corrections and targeted verification, preserving v1–v12. Freeze a new version and manifest; Main reviews the corrected exact candidate before Stephan's exact mockup approval. No application/backend/schema/guide changes, worker launch, merge, push or live operation is released by this review. Keep the broader scope/technical prerequisites above in its handoff. This is not Accepted/Closed or Approved for integration.

Correction handoff was delivered successfully to the same Designer. Main independently verified continuation `01a0c728-7a05-7050-8e31-0ba7ef189671` at `2026-09-22T03:28:33.935Z` as gpt-6-astra/xhigh in both effective metadata locations; the compact task snapshot confirms active correction. No new task or worker was created. This Main checkpoint is local programme documentation, not part of the earlier published5307692 snapshot.
