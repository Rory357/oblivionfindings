# Site Calendar redesign — test guide (fresh context)

This branch (`claude/elegant-matsumoto-2459b7`) reimplements the **Site Calendar** as the
cross-module "one source of truth": a redesigned calendar that shows manually-created events
**plus** read-only obligations auto-derived from other Sites modules (inspections, compliance,
checklists, hazards, damages, **meal plans**, vendor & credential reminders), each deep-linking
back to its source record. Staff can push any entry to their own Google/Outlook/`.ics` and
subscribe to a personal feed. The old staffing/shifts calendar was relocated to `/scheduling`.

Use this doc to verify the work end-to-end from a clean session.

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

## 4. Manual test checklist

### Views & display
- [ ] `/calendar` loads the redesigned calendar (hero + toolbar + source legend).
- [ ] Switch **Month / Week / Day / Agenda / Timeline** — each renders.
- [ ] Toolbar **prev / Today / next** navigates; the period label updates per view.
- [ ] **Colour by**: source / status / owner re-colours entries.
- [ ] **Density** toggle (comfortable/compact) changes month-cell event caps.
- [ ] **Legend chips** toggle each source layer on/off (e.g. hide Meals).
- [ ] Global scope: the **house selector** filters to one site / "All sites".
- [ ] Demo data shows: manual events (house meeting, boiler service, fire alarm test…),
      a **pending** "Boiler service", recurring entries (weekly walkaround, monthly alarm test),
      and **Meal** entries (lunch/dinner) coloured as the meal source.

### Obligations / integration
- [ ] Auto-derived obligations appear read-only and colour-coded by source.
- [ ] Clicking an obligation → detail modal → **Open record** deep-links to its source
      (e.g. a Meal → site Meal Planner tab; an inspection → `/sites/{site}/inspections`).
- [ ] Obligations have **no** Edit/Delete (read-only); only manual events do.

### Create / edit / delete (needs `calendar.create` / `calendar.manage`)
- [ ] **New event** → dialog with a **tile** type picker (not a dropdown), locked-site card
      (per-site) or site selector (global), required asterisks, Repeats preset, description.
- [ ] Choosing an approval-required type (Maintenance/Inspection) shows the "submitted as pending" note.
- [ ] Creating shows it on the calendar.
- [ ] Open a manual event → **Edit** → change time/title → saves.
- [ ] **Delete** a manual event removes it.
- [ ] **Conflict warning**: create/edit an event overlapping a same-room/vendor entry → red clash note.

### Approvals (needs `calendar.approve`)
- [ ] Open the pending "Boiler service" → **Approve** / **Reject** buttons appear.
- [ ] Approve → status becomes Approved; Reject → Cancelled.

### Reschedule
- [ ] Drag a **non-recurring** manual event to another day (month) or time (week/day) → it moves (PUT).
- [ ] Recurring/obligation entries are not drag-moved (edit recurring from the detail panel).

### Per-user calendar
- [ ] Detail modal → **Add to your calendar**: Google / Outlook open compose links; **.ics** downloads.
- [ ] Toolbar **Subscribe** → generate a private feed link → **Copy** / **Subscribe in calendar app** (webcal)
      / **Reset link**. Opening `/calendar/feed/{token}.ics` returns a valid `.ics`.

### Profile tab
- [ ] `/sites/{site}` → **Calendar** tab renders the same calendar inline (no more placeholder).

### Scheduling relocation
- [ ] `/scheduling` shows the staffing/shifts FullCalendar (create/edit shifts still works).
- [ ] Sidebar shows **Site Calendar** (→ `/calendar`) and **Scheduling** (→ `/scheduling`) — no duplicate.
- [ ] "Team Calendar" links on My Calendar / client calendar go to `/scheduling`.

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
