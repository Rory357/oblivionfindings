# Site Calendar — gap analysis & redesign audit (2026-06-04)

Audit of the shipped **Site Calendar** (`resources/js/pages/sites/calendar/SiteCalendar.tsx`)
against its hi-fi design prototype (`Site Calanders Page.zip`) and the **Rostering** banner
(`resources/js/pages/operations/rostering/index.tsx`), produced via a multi-agent workflow
(design-spec / current-impl / branding / backend maps → chrome audit + feature gaps + plan).

Branding decision (confirmed with user): the hero uses **brand `--primary` (purple, `category="ops"`)**
to match the Rostering banner — *not* the Sites green in the prototype screenshot (which was only the
prototype's hue-rotated preview default). One-line swap to `category="sites"` if ever wanted.

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

`npx tsc --noEmit` → **0 errors**; `npm run build` → **exit 0**.

---

## B. Remaining functional gaps (not yet built)

Priorities: **P1** = correctness/parity blockers · **P2** = important parity · **P3** = polish / value-add.

### P1 — should land before calling this "done"

1. **`calendar.*` permissions are seeded-not-migrated → live server 403s.**
   The six `calendar.*` permissions live in `RbacSeeder`, not a migration, and deploys skip seeders.
   Admin / super-admin roles carry only `calendar.viewAny` — **not** `view/create/manage/approve`
   (no super-admin bypass in `canDo`). On oblivionfindings.com the global page, items feed and
   feed-reset gate on `calendar.view` and **403 for admins**. Also `approve()/reject()` double-gate on
   the permission **and** the site update policy, but `canApprove` checks only the permission.
   → Add `calendar.view/create/manage/approve` to admin/super-admin in `RbacSeeder` and run a targeted
   `*PermissionsSeeder --force` post-deploy. Reconcile the approve/reject policy-vs-permission gate.
   *(This is the most likely reason the page looks empty / blocked on the live demo.)*

2. **Hero stats are in-view only; "To approve" and "Mine" can under-count.**
   Counts are derived client-side from the fetched window. `Mine` cannot see attendee-only events
   (the `CalendarItem` DTO serialises `owner` but not `attendee_user_ids`), and items overdue from a
   prior month (outside the view) are invisible.
   → Add `pendingApprovalCount` / `mineCount` / cross-range `overdueCount` props to
   `SiteCalendarController::index()/global()`, scoped to accessible sites; optionally add
   `attendees:[{id,name}]` to `CalendarItem`. Frontend prefers the prop, falls back to the derivation.

### P2 — parity & higher-value features

3. **Today rail** (xl-only aside): today focus / now·next, a **Needs-attention** card (overdue + awaiting
   approval), and today's schedule + up-next. The single biggest "support worker opens a house at shift
   start" surface; today only a `NowLine` exists in Week/Day. Today-anchored fetch (−45/+30 d).

4. **Create/Edit dialog is missing fields the system already stores/exports**: room, all-day toggle,
   attendees, **reminders** (VALARM is wired in `.ics` but unreachable), priority, and recurrence
   **UNTIL/COUNT** (`recur.ts` supports them; the UI can't reach them). Mostly additive frontend +
   `store()/update()` validation for `room`, `reminder_minutes[]`, `all_day`, `priority`, `attendee_user_ids`.

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

## C. Files touched this session
- `resources/js/pages/sites/calendar/SiteCalendar.tsx` — hero rebuild, footer band, QuickAddMenu,
  EventHoverCard, ApprovalsPanel, Filter popover, seed-threaded create dialog, hero stat derivations.
  (No changes needed to `_parts.tsx` — its `onContext/onPreview` plumbing was already complete;
  only the parent handlers were missing. No changes to `page-hero.tsx` — its `footer`/`meta`/`stats`
  props already supported the design.)
