# Site Calendar redesign — test guide (fresh context)

This branch (`claude/elegant-matsumoto-2459b7`) reimplements the **Site Calendar** as the
cross-module "one source of truth": a redesigned calendar that shows manually-created events
**plus** read-only obligations auto-derived from other Sites modules (inspections, compliance,
checklists, hazards, damages, **meal plans**, vendor & credential reminders), each deep-linking
back to its source record. Staff can push any entry to their own Google/Outlook/`.ics` and
subscribe to a personal feed. The old staffing/shifts calendar was relocated to `/scheduling`.

Use this doc to verify the work end-to-end from a clean session.

> **✅ Executed live on `oblivionfindings.com` — 2026-06-04** (build `8441a0de`, logged in as
> `admin@demo.test`). The §4 checklist below is marked with results. Full write-up, evidence,
> and the one significant defect found (a **+12h timezone shift on all calendar times**, P1)
> are in [`site-calendar-test-findings.md`](site-calendar-test-findings.md).

---

## 1. Setup

```bash
# from the worktree root
php artisan migrate            # adds users.calendar_feed_token
php artisan db:seed --class=Database\\Seeders\\CalendarDemoSeeder   # demo events + meals + shifts
npm run build                  # or: npm run dev
```

- **No new permissions** were added — it reuses the existing seeded `calendar.view`,
  `calendar.create`, `calendar.manage`, `calendar.approve` (RbacSeeder). So no
  `*PermissionsSeeder --force` is needed. `team_lead` has all four (good test role).
- Local UI verification: serve the app (Herd `oblivionfindings.test`, or `php artisan serve`)
  logged in as a user with the calendar permissions.

---

## 2. Routes / where to look

| URL | What | Notes |
|-----|------|-------|
| `/calendar` | **Global Site Calendar** (all accessible sites) | `SiteCalendarController@global` → `calendar/global.tsx` |
| `/sites/{site}/calendar` | **Per-site** calendar | `sites/calendar/index.tsx` |
| `/sites/{site}` → **Calendar tab** | Embedded calendar (profile context) | was a placeholder, now the live calendar |
| `/calendar/items?start=&end=` | JSON unified feed (global) | manual + obligations |
| `/sites/{site}/calendar/events?start=&end=` | JSON unified feed (per-site) | |
| `/calendar/feed/{token}.ics` | **Public** subscribe feed (token-auth, no login) | |
| `/scheduling` | **Relocated** staffing/shifts FullCalendar | was `/calendar` (shadowed) |

---

## 3. Automated tests (all passing on this branch)

Run non-parallel (per repo convention — `--parallel` breaks here):

```bash
php artisan test tests/Feature/Sites/Calendar tests/Feature/Sites/SiteCalendarGlobalScopeTest.php
php artisan test tests/Feature/Respite/RespiteReadinessTest.php   # confirms /scheduling relocation
```

Coverage:
- `SiteCalendarAggregatorTest` — unions manual + obligations; site-scoping; source-layer filter.
- `SiteCalendarGlobalScopeTest` — `/calendar` renders + `/calendar/items` feed is site-scoped.
- `SiteCalendarWorkflowTest` — approve a pending event; partial (drag) reschedule; single-occurrence override.
- `CalendarFeedTest` — `.ics` subscribe feed returns a VCALENDAR for a valid token; 404 for unknown.

Frontend: `npm run build` (clean) and `npx tsc --noEmit` (0 errors).

---

## 4. Manual test checklist — executed 2026-06-04 on oblivionfindings.com

Legend: ✅ verified · ⚠️ verified, with a finding · ⛔ not exercised (reason noted).
Evidence + details in [`site-calendar-test-findings.md`](site-calendar-test-findings.md).

### Views & display
- [x] ✅ `/calendar` loads the redesigned calendar (hero + toolbar + source legend; no 403).
- [x] ✅ Switch **Month / Week / Day / Agenda / Timeline** — each renders.
- [x] ✅ Toolbar **prev / Today / next** navigates; the period label updates (Next→"July 2026").
- [x] ✅ **Colour by**: source → status re-colours entries (owner not separately checked).
- [ ] ⛔ **Density** toggle — control present, but demo data too sparse (≤1 event/day) to see the cap change.
- [x] ✅ **Legend chips** toggle each source layer on/off (hid Meals → 4 meal events removed).
- [x] ✅ Global scope: the **house selector** filters to one site / "All sites" (Tōtara 9011 → IN VIEW 0).
- [x] ⚠️ Demo data: `CalendarDemoSeeder` was **not** run, so the seeded set (pending "Boiler service",
      recurring walkaround/alarm) wasn't present. Verified instead via **existing meal/checklist obligations**
      + a **self-created** event. ⚠️ **All times display +12h off — see findings P1.**

### Obligations / integration
- [x] ✅ Auto-derived obligations appear read-only and colour-coded by source (meals; checklists via the `.ics` feed).
- [x] ✅ Clicking an obligation → detail modal → **Open record** deep-links to its source
      (Meal → `/sites/9004?tab=meal-planner`).
- [x] ✅ Obligations have **no** Edit/Delete (modal shows only `.ics` + Close).

### Create / edit / delete (needs `calendar.create` / `calendar.manage`)
- [x] ✅ **New event** → dialog with a **tile** type picker (11 types, not a dropdown), site selector (global),
      required asterisks, Repeats preset, description.
- [x] ✅ Choosing Maintenance shows **"This type requires approval — it will be submitted as pending."**
- [x] ✅ Creating shows it on the calendar (`POST …/events` 200; `IN VIEW` 4→5).
- [x] ⚠️ Open a manual event → **Edit** → change title → saves (`PUT …/events/1` 200).
      ⚠️ Saving **shifts the time +12h** (timezone bug, P1).
- [x] ✅ **Delete** a manual event removes it (toast "deleted"). ⚠️ No confirmation prompt (P3).
- [ ] ⛔ **Conflict warning** — couldn't trigger: the create/edit dialog has **no Room/Vendor field** (P3).

### Approvals (needs `calendar.approve`)
- [x] ✅ Open a pending event → **Approve** / **Reject** buttons appear (admin has `calendar.approve`).
- [x] ⚠️ Approve → status becomes **Approved** (`POST …/approve` 200). Reject not separately exercised (symmetric endpoint, button present).

### Reschedule
- [ ] ⛔ Drag a non-recurring event — DnD not reliably automatable via CDP; the `PUT` reschedule endpoint
      is the one already verified via Edit. Recommend a manual drag check.
- [ ] ⛔ Recurring/obligation entries not drag-moved — not exercised.

### Per-user calendar
- [x] ✅ Detail modal → **Add to your calendar**: Google / Outlook compose links + **.ics** present.
- [x] ✅ Toolbar **Subscribe** → **Generate** → feed link + **Copy** / **Subscribe in calendar app** (webcal) /
      **Reset link**. `POST /calendar/feed/reset` 200 (⇒ migration ran). `GET /calendar/feed/{token}.ics`
      returns a valid VCALENDAR (7 VEVENTs); unknown token → 404.

### Profile tab
- [x] ✅ `/sites/9004?tab=calendar` renders the calendar inline (no more placeholder).
      ⚠️ Tab activated via URL; a single programmatic click didn't switch it (see findings — dialog cleanup).

### Scheduling relocation
- [x] ✅ `/scheduling` shows the staffing/shifts FullCalendar (25 shifts; month/week/day/list).
- [x] ✅ Sidebar shows **Site Calendar** (→ `/calendar`, under *Sites & Locations*) and **Scheduling**
      (→ `/scheduling`, under *Workforce*) — no duplicate.
- [ ] ⛔ "Team Calendar" links on My Calendar / client calendar → `/scheduling` — not visited this pass.

---

## 5. Source → module map (obligation providers)

`app/Services/Sites/Calendar/Providers/`:

| Source | Model · date column | Open-record link |
|--------|---------------------|------------------|
| inspection | `SiteInspectionRecord.due_date` | `/sites/{site}/inspections` |
| compliance | `SiteCertification.expiry_date` + `SiteComplianceCheck.scheduled_date` | `/sites/{site}?tab=compliance` |
| checklist | `SiteChecklistRun.scheduled_date` | `/checklists/runs/{run}` |
| hazard | `SiteHazard.due_date` / `review_date` / `control_review_date` | `/hazards/{hazard}` |
| damage | `SiteDamage.discovered_date` | `/sites/{site}/damages` |
| meal | `SiteMealPlanEntry.plan_date` + `meal_slot` | `/sites/{site}?tab=meal-planner` |
| vendor | `SiteVendor.insurance_expiry` (active) | `/sites/{site}/vendors` |
| credential | `SiteCredential.last_rotated_at` + cadence (`sites.calendar.credential_rotation_days`, default 90) | `/sites/{site}/credentials` |
| event (manual) | `SiteCalendarEvent` | editable in-place |

---

## 6. Known limitations / deferred

- **Admin OAuth resource-calendar sync (Google Workspace / MS 365)** is intentionally **out of scope**
  (Part D). It belongs in Settings → Integrations and should reconcile with the existing
  `/operations/calendar-sync` feature. The calendar page exposes only the per-user export/subscribe.
- **Emergency plan** is not surfaced (not date-based); drills are modelled as manual events.
- **Recurring drag-reschedule** is disabled in v1 — edit a repeating series from the detail panel
  (single-occurrence override is supported by the backend via `…/exception` + `overridden_fields`).
- Theme follows `--primary` (brand), not the Sites green, consistent with the Meal Planner.

---

## 7. Key files

**Backend**
- `app/Services/Sites/Calendar/` — `CalendarItem` (DTO), `Contracts/CalendarObligationProvider`,
  `SiteCalendarAggregator`, `CalendarSources`, `IcsFeedBuilder`, `Providers/*`.
- `app/Services/Sites/SiteCalendarService.php` — RRULE expansion + exception overrides.
- `app/Http/Controllers/Sites/SiteCalendarController.php` — feeds, approve/reject, reschedule, `.ics` feed.
- `routes/sites.php` (feeds, approve/reject, public `.ics`), `routes/portal.php` (shifts → `/scheduling`).
- `database/migrations/2026_06_03_120000_add_calendar_feed_token_to_users_table.php`.
- `database/seeders/CalendarDemoSeeder.php`.

**Frontend**
- `resources/js/lib/calendar/recur.ts` — RRULE / ICS / Google+Outlook links / conflict / colour.
- `resources/js/pages/sites/calendar/_parts.tsx` — helpers + the 5 views.
- `resources/js/pages/sites/calendar/SiteCalendar.tsx` — shared shell + dialogs.
- `resources/js/pages/calendar/global.tsx`, `resources/js/pages/sites/calendar/index.tsx` (wrappers).
- `resources/js/pages/sites/show.tsx` — Calendar tab embed.
- `resources/js/pages/calendar/index.tsx` — shifts page (now under `/scheduling`).
- `resources/css/app.css` — `--src-*` source-colour palette.
