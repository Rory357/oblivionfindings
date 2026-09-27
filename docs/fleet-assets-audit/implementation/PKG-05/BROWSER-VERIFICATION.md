# PKG-05 real-app verification

Verified 27 September 2026 from `C:/Users/steph/.codex/worktrees/b9b9/oblivionfindings` on branch `codex/pkg-05-transport-workspace`. The app is served at `http://127.0.0.1:8765` by Herd PHP 8.4 with the production frontend build from this checkout. No Vite hot file is active. The separately frozen v6 preview on port 4400 is unchanged.

## Environment and scope

The server checks its exact checkout and task-specific disposable MySQL database before application providers run. It uses the real login, policies, controllers and services. Fixtures are synthetic and dated 1 October 2026. Production credentials and application environment files were not copied. The database preparation process remains alive so the opened review app can be used after this turn.

## Executed workflows

- Assessed Aroha's request through Route & time, Passenger needs and Review; confirmed it appeared in Planner as Ready to plan.
- Built Charlie's plan through vehicle/time, people/keys and review; selected an eligible Site driver, collection and return room, and key arrangement. Saving created Fleet booking BK-2026-0007. The full request reopened with the same booking, assigned driver, planned key rooms and allocation event. Unresolved readiness stayed pending and was explained.
- Received Harper's required first-aid kit, then separately recorded the keys at the reception cabinet using the two-step observation modals. The current record showed both events and Return work complete while the passenger journey still needed completion. Harper disappeared from Keys to store; Isaac remained. Completed the passenger journey through its separate action and verified Journey completed.
- Opened request and return row context menus and the Journey hero context menu. Current-record quick views and full records exposed permission-dependent actions and source links.
- Exercised return search, an empty result, history search and a new operational note. The note persisted with its actor in the full record; searching its text excluded the unrelated allocation event.
- Opened Month, Day, Agenda, Timeline and Week calendar modes. The saved booking was visible and opened its linked transport record with the assigned driver. Timeline uses the shared calendar's compact entry controls. A focused backend regression verifies the direct-link period for all five modes, including the Sunday-first week and six-week month grid.
- Drilled the Overview's Booking decision count into exactly Charlie and Diana's matching request rows, preserving Site and date scope.
- Downloaded the full record and Overview PDFs through the application. Rendered them with Poppler and inspected all pages. The Overview's stage totals matched the nine source requests, and planned-hour totals matched the displayed schedule. The summary now stays separate from the detailed record pages.

## Visual and runtime evidence

The final responsive sweep is saved in `output/playwright/pkg05-layout-check-output.txt`. All 24 combinations passed: Overview, Requests & approvals, Planner, Calendar, Journeys and Returns & handovers at 1280, 1366, 1440 and 1920 pixels. Every heading follows the hero with the canonical `gap-5` spacing, and every second-row navigation follows its heading. No document overflow, browser errors or console warnings were reported. The final built `transport-HC5KKNJu.css` includes the adaptive date/time grid and wrapping controls; the screenshots were captured after that build was served.

Local evidence is under the ignored `output/playwright` directory:

- `pkg05-*-verified-1440.png`: the six workspace views.
- `pkg05-planner-verified-1440.png`: the final visual builder, adaptive date/time fields and vehicle choices.
- `pkg05-return-quick-view.png`: independent return and passenger observations.
- `pkg05-overview.pdf`, `pkg05-record.pdf`, and rendered page PNGs.

Earlier exploratory screenshots/logs are retained as intermediate evidence and are not final acceptance results. Browser exploration exposed and corrected the All-sites validation error, calendar range mismatch, chart startup sizing, modal spacing and narrow date/time controls. Some early locator waits used the wrong element role or read before Inertia navigation settled; the successful workflow observations above use the settled UI.

## Verification limits

This is desktop/laptop implementation verification, not a mobile/PWA redesign or a full-system certification. The automated backend run excludes two concurrent subprocess cases. Existing private evidence upload/scanner delivery and downstream external systems were not exercised in this local browser session. Existing source services and permissions remain authoritative. The normal exact-code technical review, release migration and deployment gates still apply.
