# Site Calendar redesign — live test findings

**Environment:** `https://oblivionfindings.com` (remote demo/dev server)
**Date:** 2026-06-04
**Tester:** Claude (Chrome, logged in as `admin@demo.test` / Demo Admin)
**Build under test:** `feat/site-calendar-redesign` merged to `main` —
feature commit `b07d3a28`, merge `8441a0de` (pushed; auto-deployed by the webhook).
**Method:** Manual exercise of the live UI in Chrome + direct inspection of the JSON
feed (`/calendar/items`) and the public `.ics` endpoint.

---

## 1. Executive summary

The redesigned Site Calendar **deployed cleanly and works**. All five views, the
obligation integration, manual-event CRUD, the approvals workflow, the per-user
`.ics`/subscribe feed, the `/scheduling` relocation, the per-site calendar, and the
profile Calendar tab were all verified end-to-end against live data.

**One significant defect was found:** a **systemic +12-hour timezone shift** — every
calendar time (manual events *and* auto-derived obligations) displays 12 hours later
than intended for NZ users, and the error **compounds on every edit-save**. This is the
headline issue and should be fixed before the feature is considered done. A handful of
minor UX issues were also noted.

| Area | Result |
|------|--------|
| Deploy + migration | ✅ Deployed; `calendar_feed_token` migration auto-ran |
| Views (Month/Week/Day/Agenda/Timeline) | ✅ All render |
| Toolbar (nav, colour-by, legend toggle, house filter) | ✅ Work |
| Obligations (meals, checklists…) + deep-links | ✅ Read-only, deep-link correct |
| Create / Edit / Approve / Delete | ✅ All endpoints 200 |
| Subscribe + `.ics` feed | ✅ Valid VCALENDAR, token-auth, bad token → 404 |
| `/scheduling` relocation + sidebar nav | ✅ No duplicate |
| Per-site calendar + profile Calendar tab | ✅ Render inline |
| **Event/obligation times** | 🐛 **+12h shift (P1)** |
| Misc UX (delete confirm, dialog cleanup, counter) | ⚠️ Minor (P3) |

---

## 2. Deployment notes (how this got onto the server)

The feature was **entirely uncommitted** in the `elegant-matsumoto-2459b7` worktree at the
start; `main` (what the deploy webhook builds) had none of it, so nothing was testable on
`.com` until it was shipped. Steps taken (with the user's approval):

1. Local gate before pushing to shared infra: `npx tsc --noEmit` → **0 errors**;
   `npm run build` → **clean (built in 2m48s)**.
2. Committed the feature (`b07d3a28`, 37 files), branched `feat/site-calendar-redesign`,
   merged `--no-ff` into `main` (`8441a0de`), pushed.
3. The deploy webhook auto-pulled + auto-rebuilt (**faster than the ~5–8 min estimate**;
   verified via the login page's Vite asset hash changing `app-Br0TLJsc` → `app-Bd5_blQt`
   and `/scheduling` flipping 404 → 302).
4. The **`calendar_feed_token` migration ran automatically** on deploy (proven by
   `POST /calendar/feed/reset` → 200; see §4).

**The `CalendarDemoSeeder` was *not* run** (event id of my first created event was `1`).
Rather than depend on a server-side SSH step, the manual-event tests below were performed
with **data I created through the UI**. Existing data on the server (meal-plan entries and
checklist runs) provided the obligation coverage.

---

## 3. What passed (verified live)

### Page + views
- `GET /calendar` renders the redesigned global calendar: brand-purple hero
  ("Site Calendar — All sites · obligations & events", **not** Sites green), `IN VIEW`/
  `OVERDUE` stats, toolbar, and the 9-source legend (Event, Inspection, Compliance,
  Credential, Checklist, Hazard, Vendor, Meal, Damage). **No 403** — `admin@demo.test`
  has `calendar.view`.
- **Month** ✅ · **Week** ✅ (period label "31 May – 6 Jun 2026") · **Day** ✅
  ("Thu, 4 June 2026") · **Agenda** ✅ (date-grouped rows with source · room · ref ·
  status) · **Timeline** ✅ (per-source lanes across a day axis).
- Toolbar **Next** → "July 2026", **Today** → "June 2026" (period label updates).
- **Colour-by** re-colours: meals went from `oklch(.56 .15 8)` (red, *source*) to
  `oklch(.45 .15 150)` (green, *Approved status*).
- **Legend toggle**: turning off "Meal" sets `aria-pressed=false` and **removes all 4
  meal events** from the grid.
- **House selector**: switching to Tōtara House (9011, empty) → `IN VIEW 0`, 0 events;
  back to All sites → events return.

### Obligations / integration
- Meal obligations render read-only (modal has only `.ics` + `Close`, **no Edit/Delete**).
- **"Open record" deep-link** on a meal → `/sites/9004?tab=meal-planner` (exactly per the
  source map).
- The `.ics` feed also surfaces **checklist** obligations ("New Home Walkthrough", "Quality
  Home Checklist"), confirming providers beyond meals are active.

### Manual event CRUD + approvals (all HTTP 200)
- **Create** — `POST /sites/9004/calendar/events` → 200. Dialog has a **tile** type picker
  (11 types, not a dropdown), a **site selector** (global scope), required asterisks,
  **Repeats** preset (none/Daily/Weekly/Fortnightly/Monthly/Quarterly), description.
  Selecting *Maintenance* shows **"This type requires approval — it will be submitted as
  pending."** Created event appears on the grid and `IN VIEW` ticked 4 → 5.
- **Approve** — `POST …/events/1/approve` → 200; status badge flips Pending → **Approved**.
- **Edit** — `PUT …/events/1` → 200; title update reflected on the grid; "Save changes"
  button.
- **Delete** — toast "deleted", event removed from the grid.

### Per-user calendar (subscribe / `.ics`)
- **`POST /calendar/feed/reset` → 200** ⇒ the `calendar_feed_token` column exists ⇒ **the
  migration ran**. Dialog then shows the private feed URL, **Copy**, **Subscribe in calendar
  app** (`webcal://…`), and **Reset link** (with "invalidates the previous link" warning).
- **`GET /calendar/feed/{token}.ics` → 200**, `Content-Type: text/calendar; charset=utf-8`,
  well-formed `VCALENDAR` (VERSION/PRODID/METHOD/X-WR-CALNAME), **7 VEVENTs**.
  **Unknown token → 404** (token auth enforced).
- "Add to your calendar" — Google / Outlook / `.ics` present on both obligation and manual
  event modals.

### Relocation + navigation
- **`GET /scheduling` → 200** renders the staffing/shifts FullCalendar ("Click + drag to
  create a shift", 25 shifts / 176 hrs, month/week/day/list toggles).
- Sidebar exposes **Site Calendar → `/calendar`** (under *Sites & Locations*) and
  **Scheduling → `/scheduling`** (under *Workforce*); the personal "Calendar" → `/my-calendar`
  and the Respite `/respite/calendar` are separate. **Exactly one Site Calendar link — no
  duplicate.**

### Embedded contexts
- **Per-site:** `GET /sites/9004/calendar` → "Site Calendar — Harbour Respite", a **Back**
  link to the profile, the same toolbar/views/legend, scoped to that site's entries.
- **Profile tab:** `/sites/9004?tab=calendar` renders the **live calendar inline** (active
  tab "Calendar", view switcher + month grid + meals) — **the old placeholder is gone**.

---

## 4. Findings (issues)

### 🐛 P1 — Calendar times display +12h off (systemic timezone bug)

**Every calendar time is shown 12 hours later than intended for NZ users**, and the error
**compounds each time a manual event is saved**.

**Reproduction & evidence**
1. Created an event with Start **10:00 / End 12:00** (intending 10am–noon).
   - UI displayed it as **"10pm – 12am"**.
   - The feed returned `start: "2026-06-05T10:00:00+00:00"` — i.e. the entered wall-clock
     was stored as **10:00 UTC** (no NZ→UTC conversion), then rendered in the browser's NZ
     timezone (+12) → **22:00**.
2. Opened **Edit** — the start field pre-filled as **`2026-06-05T22:00`** (the read path
   *does* convert 10:00 UTC → 22:00 NZ). Changed **only the title** and saved.
   - The stored start jumped **`10:00 UTC` → `22:00 UTC`** (another +12h), so the event
     **moved from "Fri 5 Jun 10pm" to "Sat 6 Jun 10am"** on the grid.
3. The same shift hits **obligations**: a meal stored as `08:00:00+00:00` (breakfast)
   displays as **"8pm"**.

**Root cause**
- Backend runs in **UTC** (`app.timezone`): `MealPlanObligationProvider` sets slot times in
  local terms (`'breakfast' => '08:00'`, `'lunch' => '12:30'`, …) and emits them via
  `toIso8601String()` as `+00:00`; the manual-event create stores the `datetime-local`
  wall-clock string verbatim (parsed as UTC).
- Frontend formats those instants in the **browser's local timezone** (NZ, UTC+12 in June),
  adding +12h on display. The write path never converts NZ→UTC, so the round-trip
  (correct read +12, no write −12) **double-counts** on edit.

**Impact:** breakfast shows at 8pm; a 10am booking shows at 10pm; editing an event silently
shifts it forward another day-part. For a NZ-only app this makes the calendar times wrong
everywhere. (In NZDT/summer the offset becomes +13h.)

**Recommendation:** pick one source of truth and apply it consistently — e.g. set the app
to the business timezone (`Pacific/Auckland`) and/or format on the frontend in a fixed
business timezone rather than browser-local, and convert the create/edit `datetime-local`
input from NZ → UTC before submit. Add a regression test asserting that "create at 10:00 →
read back 10:00 → edit/save → still 10:00".

---

### ⚠️ P3 — Delete has no confirmation step
Clicking **Delete** on an event removes it immediately (toast "deleted") with **no
"are you sure?" prompt**. Easy to delete an approved event by accident. Recommend a confirm
dialog (consistent with destructive actions elsewhere).

### ⚠️ P3 (possibly P2) — Closed dialog node lingers and intercepts clicks
After creating/closing a calendar dialog, a Radix dialog element remained in the DOM with
`data-state="closed"` **but `visibility:visible`** (content reduced to just a "Close"
button). While it was present, **clicking another event did not open its detail modal** and
the profile Calendar **tab didn't switch on a single click** — both recovered only after a
full page reload. This surfaced repeatedly during automated testing; it should be
**reproduced manually** to confirm whether real users hit it (rapid open/close of dialogs).
Likely an unmounting/cleanup issue in the shared dialog shell.

### ⚠️ P3 — "IN VIEW" counter semantics inconsistent across views
The hero `IN VIEW` stat read **4** in Month, **2** in Day, and **2** in Agenda even though
the Agenda listed **4** rows for the month. The count appears to mean different things per
view (e.g. today-and-future vs. whole range). Worth aligning the definition so the number
matches what's on screen.

### ⚠️ P3 — Create/Edit dialog exposes no Room/Vendor field
The new-event dialog has Type/Site/Title/Start/End/Repeats/Description but **no Room or
Vendor field**. The conflict-warning feature is documented as detecting same-room/same-vendor
clashes, but a user can't *set* a room/vendor on a manual event here — so the clash warning
can't be triggered for manually-created events through this dialog (see §5).

---

## 5. Not exercised in this pass (with reasons)

- **Reject → Cancelled** — Approve was fully verified (200, status → Approved); Reject is the
  symmetric endpoint and its button is present in the modal, but it was not separately
  exercised (would require creating another pending event).
- **Conflict / clash warning** — could not be triggered: the create/edit dialog has no
  room/vendor input (see P3 above), so no same-room/vendor overlap could be authored.
- **Drag-to-reschedule** — calendar drag-and-drop is unreliable to drive through CDP
  automation; the underlying `PUT` reschedule endpoint was already proven via Edit. Worth a
  manual check (drag a non-recurring event in Month/Week).
- **Recurring single-occurrence override** (`…/exception`) — backend-only per the design doc;
  not surfaced as a distinct UI action in this pass.
- **Density toggle (comfortable/compact cap)** — the control is present, but the demo data is
  too sparse (≤1 event/day) to observe the month-cell "+N more" cap change.
- **"Team Calendar" links → `/scheduling`** (from My Calendar / client calendar) — not
  visited in this pass.

---

## 6. Test-data / environment notes

- All manual-event testing used a single self-created event (id `1`, "TEST — Boiler service…",
  Harbour Respite / 9004), which was **approved, edited, then deleted** — so **no leftover
  test event remains** on the server.
- Generating the subscribe link created a `calendar_feed_token` for the **Demo Admin** user
  (its personal feed) — harmless, can be reset from the Subscribe dialog.
- Toolbar state (colour-by, house filter, legend, view, density) is client-side only and
  resets on reload; nothing to clean up server-side.
- The `CalendarDemoSeeder` was deliberately **not** run; if you want the richer demo set
  (pending "Boiler service", recurring fire-alarm/walkaround, more meals) for manual review,
  run it once on the server:
  `php artisan db:seed --class='Database\Seeders\CalendarDemoSeeder' --force`.
