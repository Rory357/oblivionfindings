# Site Calendar — gap analysis & redesign audit (2026-06-04)

Audit of the shipped **Site Calendar** (`resources/js/pages/sites/calendar/SiteCalendar.tsx`)
against its hi-fi design prototype (`Site Calanders Page.zip`) and the **Rostering** banner
(`resources/js/pages/operations/rostering/index.tsx`), produced via a multi-agent workflow
(design-spec / current-impl / branding / backend maps → chrome audit + feature gaps + plan).

Branding decision (confirmed with user): the hero uses **brand `--primary` (purple, `category="ops"`)**
to match the Rostering banner — *not* the Sites green in the prototype screenshot (which was only the
prototype's hue-rotated preview default). One-line swap to `category="sites"` if ever wanted.

> **Status update (2026-06-04):** both **P1** blockers are now **closed** (backend) — a targeted
> calendar-permissions backfill seeder + approve/reject gate reconciliation, and authoritative
> cross-range hero counts. `npx tsc --noEmit` → 0 errors, `npm run build` → exit 0, and **17 calendar
> feature tests pass** (3 new in `SiteCalendarHeroCountsTest`). Details inline under section B/P1; files
> in section C. Remaining work is **P2/P3** only.

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

### P2 — parity & higher-value features

3. ~~**Today rail**~~ — ✅ **implemented this session** (see section A #8). Remaining rail polish: a
   secondary today-anchored fetch already runs (−45/+30 d); a future enhancement is a "happening now"
   auto-refresh tick so the NOW marker advances without a manual reload.

4. **Create/Edit dialog** — ✅ visual polish done (design-parity tiles, header, locked-site card, split
   Date/Start/End) **and ✅ Owner / Attendees / Reminders / All-day now wired end-to-end**:
   - **Owner** dropdown + **Attendees** chip multi-select — controller passes a `people` list
     (`User::staff()`); `store()/update()` persist `owner_user_id` + `attendee_user_ids[]`. Attendee
     IDs round-trip via a new `CalendarItem.attendeeIds` so edit pre-selects them.
   - **Reminders** preset chips (At time / 10m / 30m / 1h / 1d) → `reminder_minutes[]` (drives the
     existing `.ics` VALARM path).
   - **All-day** toggle → new `all_day` column (migration `2026_06_04_120000`), hides the time inputs,
     and renders in the views' all-day row.
   - Still open: **Recurrence UNTIL/COUNT** (`recur.ts` supports them; the Repeats `<select>` can't reach
     them yet) and a **Room/location** input (`room` is read-only in detail today).

5. **Missing obligation providers (NZ supported-living value-adds):**
   - **Emergency / evacuation plan review dates** — `--src-emergency` palette exists but there is no
     `SiteEmergencyPlan` model/provider. A core supported-living obligation that never surfaces.
   - **Fleet / asset maintenance** — `SiteCalendarEvent` has `fleet_vehicle_id` / `asset_id` columns but no
     provider, so vehicle WOF/rego/service and asset calibration aren't auto-pulled.
   (8 of 9 declared sources are wired today.)

### P3 — polish

6. **Credential reminders never fire for never-rotated credentials** — `CredentialReminder` only fires when
   `last_rotated_at` is non-null; the most-likely-forgotten credentials get no reminder. Fall back to
   `created_at + cadence`.
7. **Vendor reminders cover only `insurance_expiry`** — no contract-renewal / next-visit columns.
8. **Profile embed Subscribe** opens in "generate" mode (no `feedUrl` passed from `show.tsx`); pass it through.
9. **`ViewOptionsMenu` / `NotificationsMenu`** — colour/density now live in the Filter popover; a dedicated
   notifications bell (7-day reminders + pending count) is still absent.
10. **Hero height** — the taller 4-stat hero + footer may push the `h-[calc(100vh-22rem)]` calendar surface;
    verify on dev and bump the offset if it double-scrolls.

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
