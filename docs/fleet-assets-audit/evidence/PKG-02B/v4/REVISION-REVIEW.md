# PKG-02B v4 review candidate

Designer: GPT-6 Astra / Extra High. Task `01a0c2bb-fcff-7cb1-8bab-882d84477c6c`; worktree `5b0a`; branch `codex/pkg-02b-vehicle-profile-design`; baseline `5307692ec59be84f3503c06354419b7da95be805`.

Preview: http://127.0.0.1:4339/PKG-02B/v4/#/fleet-assets/vehicles/14/overview

V1–V3 preserved. No Main message, worker assignment, application/shared-component edit, commit or push. Exact version/scope approval remains pending.

## Coverage

Inspected all profile destinations: Readiness, Vehicle details, compliance evidence, schedules, reminders, service history, mileage, recent checks, templates, open work, historical work, Map, dedicated Trip history and Calendar. Inspected work-record Overview/Appointment/Evidence/Costs and all five canonical calendar views.

Overview at 1440×1000 had no nested scrolling and 1043px total page height including shell, canonical header and footer. The 1280×900 desktop map was checked in dark theme without horizontal overflow. Temporary viewport override reset before delivery.

`browser-evidence.json` contains 42 recorded checks across v4 iterations. Screenshots 01–22 cover screens and workflows; some intentionally contain temporary synthetic test changes.

## Verified interactions

- Photo selected/saved and shown in header/details; final dirty-photo discard guard checked.
- Valid synthetic PDF and PNG attached to SCH-DEMO-07 and retained with the schedule.
- Return template DEMO-2 submitted with PDF evidence. Original version/answers retained; a pass did not release the restriction.
- Actual 453px Leaflet map with loaded OSM tiles, markers, trail and geofence. Motion popover, observation selection, no-tracker and imagery-failure states checked.
- Existing shared geofence selected; new synthetic 100m boundary drawn, reviewed, created and linked. Trip date filter changed selected route/source.
- Right-click month event, empty date and week timed event opened contextual menus. Booking creation preserved the selected date/time.
- No-approval request without justification blocked. Validation returns to Approval & evidence; final completeness includes conditional requirements.
- Ready coordinator exception confirmed using file-only evidence earlier in v4. After explicit readiness/driver attestation was added, the reason-backed path was rechecked successfully. Restricted and report-only exceptions stayed pending; staff approval action disabled.
- Full booking flow: confirmation → checkout/keys → return/condition → mileage updated 82,460 to 82,475 km.
- Final service-completion regression advanced routine schedule to 24 Mar 2027 / 92,460 km while preserving Completed · awaiting release and the vehicle restriction.
- View-only photo/geofence changes disabled; empty trips, denied access and missing tracker states checked.

## Technical result and limits

Targeted TypeScript and Vite build pass. Final bundle `index-C8x0I40p.js`, CSS `index-BWw8b4yC.css`. `/__preview` confirms PKG-02B-v4, expected baseline and worktree. Git status contains only preview/evidence artifacts.

Browser QA found and fixed collapsed percentage-height map wrappers. It also found a Leaflet zoom callback after rapid unmount; preview-only animation defaults now disable those transitions. Final observation/recenter/trip/theme/unmount checks captured no final-bundle errors. Shared-component teardown merits later implementation QA; no production source was patched. Build warnings concern bundle size and mixed Leaflet imports.

All changes are local in-memory demonstrations. No attachment persistence, live tracker dispatch, notifications, alert activation or real approvals occur. Existing operational policy, privacy, release and implementation gates remain. See `MAIN-HANDOFF-PENDING.md` for universal geofencing and booking decisions retained for the later handoff.
