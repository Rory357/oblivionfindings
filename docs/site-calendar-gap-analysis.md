# Site Calendar — gap analysis & redesign audit (2026-06-04)

Audit of the shipped **Site Calendar** (`resources/js/pages/sites/calendar/SiteCalendar.tsx`)
against its hi-fi design prototype (`Site Calanders Page.zip`) and the **Rostering** banner
(`resources/js/pages/operations/rostering/index.tsx`), produced via a multi-agent workflow
(design-spec / current-impl / branding / backend maps → chrome audit + feature gaps + plan).

Branding decision (confirmed with user): the hero uses **brand `--primary` (purple, `category="ops"`)**
to match the Rostering banner — *not* the Sites green in the prototype screenshot (which was only the
prototype's hue-rotated preview default). One-line swap to `category="sites"` if ever wanted.

> **Status update (2026-06-04):** **all P1, P2 and P3 gaps are now implemented** — only the
> by-design Deferred admin-OAuth sync remains. Beyond the P1 permissions-seeder + hero-count work,
> this adds recurrence **UNTIL/COUNT** (now honoured by the server-side expander, not just `.ics`) +
> a **Room/location** field, a live-ticking rail **NOW** marker, **two new obligation providers**
> (Fleet/asset + Emergency plan → the source palette is now fully wired), never-rotated **credential**
> reminders, **vendor** contract-renewal / next-visit reminders, a shared **Subscribe** URL for the
> profile embed, and a hero **notifications bell**. `npx tsc --noEmit` → 0 errors, `npm run build` →
> exit 0, and **24 calendar feature tests pass**. Per-gap detail inline; files in section C.

---

## A. What this session implemented (chrome parity — done, build-verified)

| # | Gap (user-named first) | Before | After |
|---|---|---|---|
| 1 | **Hero banner** | Plain `PageHero`: title + 2 stats + 1 grey button, controls in a separate card below | Full rostering-style dark hero via the shared `PageHero`: live "synced just now" pulse badge, dynamic summary, **meta row** (period·view / sources shown / done·approved), **4 toned stats** (This month / Overdue·critical / To approve·warning / Mine), onDark primary **New entry** + outline **overflow** menu, and a **footer band** holding the period stepper + 5-view switch + Filter |
| 2 | **Right-click quick-add** | `onContext` plumbed in all 5 views but never supplied → dead | `QuickAddMenu` (portal + viewport clamping) seeded with the clicked date+hour, event-type tiles, approval badges, "Open full form…" fallback; picks pre-fill the create dialog |
| 3 | **Rostering branding** | `variant="secondary"` grey button on the gradient | `bg-primary-foreground text-primary` primary + `border-primary-foreground/30` outline, identical to Rostering; `category="ops"` gradient |
| 4 | Hover preview | `onPreview/onPreviewEnd` plumbed but dead | `EventHoverCard` (portal, 320 ms delay, viewport-flip), suppressed under modals |
| 5 | Approvals queue | approve/reject only inline per-event | `ApprovalsPanel` modal (bulk approve/reject) reachable from the hero overflow + "To approve" stat |
| 6 | Colour-by / density / sources / house | bare toolbar selects | consolidated into the hero footer **Filter** popover (matches the screenshot's single Filter control); source legend chips kept below for quick toggling |
| 7 | Profile-tab embed | toolbar lived in the page body | retains its own light toolbar (it has no hero footer); QuickAdd + hover work there too |
| 8 | **Today rail** | absent (only a `NowLine` in Week/Day) | right-hand `xl:` aside: today focus (happening-now / up-next), a **Needs attention** card (overdue + awaiting approval → opens the approvals panel), and Today's schedule with a live **NOW** marker + an **Up next** 1–14 day list; driven by a today-anchored fetch (~45d back) independent of the browsed period |
| 9 | **Create dialog** | plain dot+label tiles, single datetime inputs | design-parity tiles (icon-in-tinted-square + label + hint + approval lock), header icon, richer "From site" locked card, split **Date / Start / End** fields, and a "Create entry / Submit for approval" button |
| 10 | **Hero house selector** | static title, house filter buried in a popover | clickable searchable **house/site selector** as the hero title (`All sites` + every accessible house → navigates); **Today · {date}** pill restored; **Week/Day grids now span the full 24h** with auto-scroll to ~6am |

`npx tsc --noEmit` → **0 errors**; `npm run build` → **exit 0**.

> **Note on live demo data:** the dev server (`oblivionfindings.com`) has sparse seed data
> (meal obligations only; the `CalendarDemoSeeder` was never run, so no overdue/pending items).
> The rail therefore renders its today/up-next schedule but the "Needs attention" card stays
> hidden until there are overdue or awaiting-approval entries. The design screenshot's rich
> "13 needs attention" reflects the prototype's mock dataset, not a code gap.

---

## B. Functional gaps (P1 closed this session · P2/P3 remaining)

Priorities: **P1** = correctness/parity blockers · **P2** = important parity · **P3** = polish / value-add.

### P1 — ✅ closed this session (backend; `tsc` 0, `build` 0, 17 calendar tests green)

1. ~~**`calendar.*` permissions are seeded-not-migrated → live server 403s.**~~ — ✅ **fixed with a
   targeted backfill seeder + approve/reject gate reconciliation.**
   *Correction to the original note:* the `admin` role is **not** limited to `calendar.viewAny` in code —
   `RbacSeeder` syncs **every** permission to `admin`
   (`$admin->permissions()->sync(Permission::pluck('id'))`), so admins gain
   `calendar.view/create/manage/approve` **once the seeder runs**. The live-server 403s are therefore
   purely operational: deploys skip seeders, so on oblivionfindings.com the seeder never re-ran after the
   newer `calendar.view/create/manage/approve/manage_recurring` rows were added to `RbacSeeder` — those
   permission rows + admin's grants never reached the live DB (the standard seeded-not-migrated deploy gap).
   → Added **`database/seeders/SeedCalendarPermissionsSeeder`** — idempotent `firstOrCreate` + additive
   `attach` of the diff onto `admin` (mirrors `SeedHrPermissionsSeeder`, never `sync`-wipes other grants)
   — and wired it into `DatabaseSeeder` after `OperationsPermissionsSeeder`. **Post-deploy fix:**
   `php artisan db:seed --class=SeedCalendarPermissionsSeeder --force`. (The complete multi-role fix —
   `team_lead`, `maintenance_coordinator`, `health_safety_officer` — remains a re-run of `RbacSeeder`;
   the demo signs in as `admin`, which this covers.)
   **Approve/reject gate reconciled:** `approve()/reject()` enforce the route `permission:calendar.approve`
   **and** the controller `authorize('update', $site)` policy, but the page's `canApprove` prop checked
   *only* the permission — so the button showed then 403'd on click. `index()` now computes
   `canApprove = canDo('calendar.approve') && can('update', $site)` (mirroring `canCreate/canManage`).
   `global()` stays a coarse `canDo('calendar.approve')` **by design** — there's no single site at page
   level, and per-event approval still authorises the owning site server-side.

2. ~~**Hero stats are in-view only; "To approve" and "Mine" can under-count.**~~ — ✅ **authoritative
   cross-range backend counts added.**
   New `SiteCalendarController::heroCounts(siteIds, userId)` returns, scoped to the page's accessible
   sites: **`pendingApprovalCount`** (manual events `approval_status='pending'`), **`mineCount`** (manual
   events I **own or attend** — the attendee leg via `whereJsonContains('attendee_user_ids', …)`, which the
   in-view derivation missed), and **`overdueCount`** (a cross-range aggregator scan over a bounded
   **12-month look-back**, limited to the six overdue-capable sources —
   inspection/compliance/checklist/hazard/vendor/credential; manual events, meals and damages never carry
   an `overdue` status, so they're skipped to keep it cheap). Passed by `index()` (this house) and
   `global()` (all resolved sites). Attendees already round-trip as `CalendarItem.attendeeIds`, so **no DTO
   change was needed**.
   Frontend (`SiteCalendar.tsx`) **prefers the props** but **falls back to the in-view derivation** for the
   profile embed (no props) **and whenever the user narrows by house/source**, so the stat keeps tracking
   what's on screen; the derived `mineCount` now also counts `attendeeIds`. Covered by
   `tests/Feature/Sites/Calendar/SiteCalendarHeroCountsTest.php` (pending/mine/overdue values, accessible-site
   isolation, and the `canApprove` view-vs-update gate).

### P2 — ✅ complete this session

3. ~~**Today rail**~~ — ✅ implemented earlier (see section A #8); **✅ the "happening now"
   auto-refresh tick is now done too** — a `useNow()` hook (`_parts.tsx`) re-renders every 60 s, so the
   Week/Day `NowLine` and the rail's NOW / "happening now / up next" advance live without a reload.

4. ~~**Create/Edit dialog**~~ — ✅ tiles/header/locked-site/split-date + Owner/Attendees/Reminders/All-day
   were done earlier; **✅ the two remaining items are now done**:
   - **Recurrence UNTIL/COUNT** — the dialog has an **Ends** control (Never / On date / After N) that
     layers `until`/`count` onto the frequency preset. Crucially the **server-side expander now honours
     UNTIL and COUNT** (`SiteCalendarService::calculateOccurrences` — previously they only reached the
     `.ics`), so the grid stops at the bound. (Covered by a workflow test asserting no post-UNTIL
     occurrences.)
   - **Room/location** — new nullable `room` column (migration `2026_06_04_130000`) + fillable +
     `store()/update()` validation; threaded through `formatOccurrence` → `CalendarItem.room` so it
     renders in detail/cards, feeds same-room conflict detection and the `.ics` LOCATION. A "Room /
     location" input was added to the dialog. (Round-trip test added.)

5. ~~**Missing obligation providers**~~ — ✅ **both new providers shipped; all declared sources now
   wired** (the source palette went from 9 → 11):
   - **Emergency / evacuation plan review dates** — new `SiteEmergencyPlan` model + migration
     (`2026_06_04_130200`) + `EmergencyPlanObligationProvider` (`emergency` source, the pre-provisioned
     `--src-emergency` palette token). Surfaces each active plan's `next_review_at` (explicit, else
     `last_reviewed_at + review_interval_months`). `SiteEmergencyPlanSeeder` seeds demo plans.
   - **Fleet / asset maintenance** — new `AssetMaintenanceObligationProvider` (`asset` source + new
     `--src-asset` token) reading the Asset register's WOF / registration / CoF / inspection /
     maintenance / warranty dates (skips disposed/retired). Both registered in
     `SiteCalendarAggregator` + `CalendarSources` + the frontend `DEFAULT_SOURCES`, and added to the
     controller's `OVERDUE_SOURCES`. (Provider tests added.)

### P3 — ✅ complete this session

6. ~~**Credential reminders never fire for never-rotated credentials**~~ — ✅ `CredentialReminderProvider`
   now falls back to **`created_at + cadence`** when `last_rotated_at` is null (titled "first rotation
   due"), so the most-likely-forgotten credentials surface. (Provider test added.)
7. ~~**Vendor reminders cover only `insurance_expiry`**~~ — ✅ new nullable `contract_renewal_date` +
   `next_visit_date` columns (migration `2026_06_04_130100`); `VendorReminderProvider` now emits
   insurance / contract-renewal (overdue-able) + next-visit (a forward booking, never "overdue").
8. ~~**Profile embed Subscribe**~~ — ✅ a shared `calendarFeedUrl` Inertia prop (`HandleInertiaRequests`)
   is now emitted for every request; `SiteCalendar` falls back to it when the `feedUrl` page-prop is
   absent, so the profile-tab Subscribe shows the real link instead of "generate" mode.
9. ~~**`NotificationsMenu`**~~ — ✅ a hero **notifications bell** (with a count badge) now lists upcoming
   entries in the next 7 days (click → detail) plus, for approvers, the pending-approval count
   (click → approvals panel). Colour/density remain in the Filter popover.
10. ~~**Hero height**~~ — ✅ assessed: no change needed. The 4-stat hero + footer was already shipped and
    live-verified on dev without double-scroll (see `docs/site-calendar-testing.md`); this session adds
    no hero height (the bell sits in the existing actions row, and the recurrence/Room inputs are in the
    dialog).

### Deferred (by design)
- **Admin OAuth resource-calendar sync** (Settings → Integrations → Calendar sync, Part D): connect
  Google Workspace / Microsoft 365, per-house resource-calendar mapping, sync cadence. Staff-facing
  calendar keeps only per-user add-to-calendar + subscribe. Spec in the prototype's `CLAUDE.md`.

---

## C. Files touched

**Chrome-parity session (frontend):**
- `resources/js/pages/sites/calendar/SiteCalendar.tsx` — hero rebuild, footer band, QuickAddMenu,
  EventHoverCard, ApprovalsPanel, Filter popover, seed-threaded create dialog, hero stat derivations.
  (No changes needed to `_parts.tsx` — its `onContext/onPreview` plumbing was already complete;
  only the parent handlers were missing. No changes to `page-hero.tsx` — its `footer`/`meta`/`stats`
  props already supported the design.)

**P1 closeout (this session, backend + frontend):**
- `app/Http/Controllers/Sites/SiteCalendarController.php` — `canApprove` now double-gates on
  `can('update', $site)` in `index()`; new `heroCounts()` helper + `OVERDUE_SOURCES` const; `index()`
  and `global()` pass `pendingApprovalCount` / `mineCount` / `overdueCount`.
- `database/seeders/SeedCalendarPermissionsSeeder.php` — **new**; idempotent backfill of the six
  `calendar.*` permissions onto `admin` (post-deploy live-server fix).
- `database/seeders/DatabaseSeeder.php` — calls `SeedCalendarPermissionsSeeder` after
  `OperationsPermissionsSeeder`.
- `resources/js/pages/sites/calendar/SiteCalendar.tsx` — prefer the authoritative count props (fall back
  to in-view derivation for the profile embed or when narrowed by house/source); derived `mineCount` now
  counts `attendeeIds` too.
- `tests/Feature/Sites/Calendar/SiteCalendarHeroCountsTest.php` — **new**; pending/mine/overdue counts,
  accessible-site isolation, and the `canApprove` view-vs-update gate.
  *(No `CalendarItem` DTO change — attendees already serialise as `attendeeIds`.)*

**P2/P3 completion (this session):**
- **New obligation providers** — `app/Services/Sites/Calendar/Providers/AssetMaintenanceObligationProvider.php`,
  `…/EmergencyPlanObligationProvider.php`; both registered in `SiteCalendarAggregator`.
- **Reminder coverage** — `…/Providers/CredentialReminderProvider.php` (never-rotated → `created_at`
  fallback), `…/Providers/VendorReminderProvider.php` (contract-renewal + next-visit).
- **Models** — `app/Models/SiteEmergencyPlan.php` (**new**); `SiteCalendarEvent` (+`room` fillable);
  `SiteVendor` (+`contract_renewal_date`/`next_visit_date`).
- **Migrations** — `2026_06_04_130000_add_room_to_site_calendar_events_table`,
  `2026_06_04_130100_add_reminder_dates_to_site_vendors_table`,
  `2026_06_04_130200_create_site_emergency_plans_table`.
- **Recurrence/Room/feed** — `app/Services/Sites/SiteCalendarService.php` (expander honours UNTIL/COUNT;
  `room` in `formatOccurrence`); `SiteCalendarController` (`room` validation + `OVERDUE_SOURCES` += asset,
  emergency); `app/Services/Sites/Calendar/CalendarSources.php` (+asset, +emergency).
- **Subscribe** — `app/Http/Middleware/HandleInertiaRequests.php` (shared `calendarFeedUrl`);
  `resources/js/types/index.d.ts` (`SharedData.calendarFeedUrl`).
- **Frontend** — `resources/js/pages/sites/calendar/SiteCalendar.tsx` (Ends control + Room input,
  notifications bell, `useNow` tick, Subscribe fallback, new source defaults); `…/_parts.tsx`
  (`useNow` hook, `Truck` icon); `resources/js/lib/calendar/recur.ts` (`asset` source key);
  `resources/css/app.css` (`--src-asset` token).
- **Seeders** — `database/seeders/SiteEmergencyPlanSeeder.php` (**new**, wired into `DatabaseSeeder`).
- **Tests** — `tests/Feature/Sites/Calendar/SiteCalendarObligationProvidersTest.php` (**new**; asset,
  emergency, credential-fallback, vendor); `SiteCalendarWorkflowTest.php` (+room round-trip, +UNTIL bound).
