# Vehicle mockup readability audit — v11

The requested screenshots identified inconsistent record layouts, tall empty surfaces and cramped metadata. v11 adds one shared collection adapter using the established EntityCard, EntityTable and EntityMenu components. No production component is modified.

## Changes

- List (default) and Cards: compliance, schedules, recent checks, templates, required documents and uploaded files, mileage, reminders, open Maintenance, history and Finance records/requests.
- Remembered preferences are independent per section; service and Maintenance history share the same history preference. Local storage failure falls back to in-memory operation.
- Both layouts retain sources, dates, ownership, status, evidence and permitted actions. Kebab and right-click actions share the same definition. List rows open their record; buttons inside rows retain their own action.
- The next-check requirement now uses a full-width strip with the correct DOM/focus order, instead of a tall sidebar. Single check records stay compact in List view.
- Document empty state is shorter and has a direct upload action. Missing-file requirements remain distinct from actual uploaded files.
- Mileage fields wrap naturally; its side chart moves below the collection on narrower desktop widths. Selected details and correction actions survive a view switch.
- On narrow screens, record fields stack with labels and touch targets. Summary grids, filters, header navigation and the speed-limit date/time fields no longer force horizontal scrolling in the verified routes.
- History distinguishes completed work awaiting release with a warning. An empty open-work result now uses the filtered collection, even when only closed work exists.

## Review coverage and deliberate exceptions

Overview readiness, vehicle details, documents and Finance; all five service/compliance sections; recent checks and templates; open Maintenance and its shared history; work overview/appointment/evidence/costs; location/geofences, telemetry, driving analytics/reviews/speed limits and alert queue; trip history; all five calendar modes and the booking/custody section were inspected. Route observations are in browser-audit.json, with snapshots and representative screenshots beside it.

Readiness stays a summary. Maps keep spatial context; calendar modes keep time relationships; telemetry metrics/charts keep signal and trend context. The Control Room queue retains its response-oriented list, and booking/custody retains its stage-oriented workflow. Giving every small metric or action panel another view switch would add clutter. Cards take more vertical room than lists; that is why lists are the default. Trip timeline already supports List/Card/Table and is unchanged.

## Browser workflow checks

- Checklist search survives Cards to List; Customise opens the selected Return condition template and preserves controlled-version workflow.
- Mileage search survives List to Cards; selected reading details remain present; Correct reading opens the original ODO-DEMO-03 with source and reason workflow intact.
- Compliance right-click opens the same permitted source/upload/appointment actions. Saved synthetic evidence appears in the document library in both views; Open source returns to compliance.
- Open Maintenance card action opens WO-0268 with the source schedule, restriction and all four work sections intact.
- Calendar right-click offers booking, service/inspection, reminder, unavailable period and availability actions. Creating a reminder for 26 September 2026 at 09:00 preserves that date, shows the same reminder in List and Cards, and returns to 26 September from View in calendar.
- View-only menus omit mutation actions while showing source/history actions; disabled primary buttons remain disabled. First-use schedules show an honest empty state.
- Native file chooser automation timed out before a file was selected. The existing synthetic sample upload was used instead; no user file was uploaded. This is not evidence of a production upload failure.

## Boundaries

This is an isolated, synthetic design candidate. Layout audit is not production acceptance or an exhaustive backend/workflow test. No live tracker, speed-limit service, Finance posting or external notification was introduced. GV500CG capability qualifications and original records remain intact. Pending universal geofencing/checklist handoff requirements are preserved from earlier revisions. Main has not been notified.
