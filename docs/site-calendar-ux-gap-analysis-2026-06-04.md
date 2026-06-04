# Site Calendar (`/calendar`) — UI/UX gap analysis (2026-06-04)

Fresh audit of the **live** Site Calendar (`resources/js/pages/sites/calendar/SiteCalendar.tsx`
+ `_parts.tsx`, served at `https://oblivionfindings.com/calendar`) produced via a multi-agent
`/workflows` audit, cross-checked against the hi-fi prototype (`Site Calanders Page`) and the
already-closed gaps in [`site-calendar-gap-analysis.md`](site-calendar-gap-analysis.md).

> **Scope: desktop web only.** This app is used on the desktop web only — it is **not** a
> tablet/mobile product. All responsive-breakpoint and touch-input findings (small-viewport
> layout, long-press, touch-scroll vs drag, touch target sizing, touch hover) are therefore
> **out of scope and omitted** from this list. The accessibility items below are kept because
> they are desktop concerns (keyboard navigation, screen readers, colour contrast).

> The earlier P1/P2/P3 chrome/feature gaps are closed and verified; the calendar already has
> strong prototype parity (all 5 views, drag/resize, hover preview, right-click quick-add,
> create/edit dialog, approvals, Today rail, notifications bell, subscribe feed, recurrence
> Ends, room, asset/emergency sources). What remains is mostly **keyboard/screen-reader
> accessibility**, a few **data-correctness bugs**, and **detail-dialog completeness**.

**Already fixed (Calendar-sync session, 2026-06-04):** G-1 (hero-count regression) and G-2
(hero deep-link to Settings → Calendar sync). Everything else below is for a fresh
implementation context.

---

## ✅ Fixed this session

### G-1 (S) Hero stats (Overdue / To approve / Mine) silently under-counted on both `/calendar` routes
The controller emits `pendingApprovalCount` / `mineCount` / `overdueCount`, but the page wrappers
[`calendar/global.tsx`](../resources/js/pages/calendar/global.tsx) and
[`sites/calendar/index.tsx`](../resources/js/pages/sites/calendar/index.tsx) weren't forwarding
them, so `SiteCalendar` fell back to **in-view** derivation (only the browsed window). **Fixed:**
the three props are now declared and passed through both wrappers; the profile embed still uses
the fallback.

### G-2 (S) Hero overflow missing the "Calendar sync settings" deep-link
HANDOFF §5 mandates a deep-link from the calendar hero overflow to Settings → Calendar sync.
**Fixed:** a `DropdownMenuItem` → `/settings/calendar-sync` was added under the hero overflow's
"Admin" group, gated on `canManageIntegrations` (`auth.can.integrations.manageTenantSecrets`).

---

## P1 — correctness / accessibility-critical

### G-3 (L) Calendar grids have no ARIA grid semantics and no keyboard navigation
`MonthView` (`_parts.tsx:499-560`) is a `<div>` grid of `<div onClick>` cells — no `role="grid"`,
`role="row"`, `role="gridcell"`, no arrow-key roving-tabindex, and empty days are unreachable.
`WeekView`/`DayView` columns are divs with `onContextMenu` only. **Fix:** add grid roles +
`aria-current="date"` + Arrow-key navigation with roving tabindex + `Enter`/`n` to create on the
focused cell.

### G-4 (M) `TimeBlock` events (Week/Day) are not keyboard accessible
`TimeBlock` (`_parts.tsx:325-413`) is a `<div onPointerDown>` with no `tabIndex`/`role`/`onKeyDown`
— cannot be focused or activated by keyboard. **Fix:** make it a `<button role="button" tabIndex=0>`
with `onKeyDown`→`onSelect`; expose time editing via the detail dialog (the drag-resize handle can
stay mouse-only and `aria-hidden`).

---

## P2 — important UX / parity

| # | Effort | Gap | Location | Fix |
|---|---|---|---|---|
| G-5 | S | Network/403 fetch errors are **silently swallowed** (events set to `[]`), so a permission/connection failure looks like "no events" | `SiteCalendar.tsx:341-351,368-376` | add `fetchError` state + an alert banner with Retry; distinguish 403 vs network |
| G-6 | M | Loading shows a tiny line **below** the grid; the grid flashes empty on every nav/view change | `SiteCalendar.tsx:682,332` | overlay `opacity-50 pointer-events-none` (or skeleton) on the view body while loading |
| G-7 | M | Detail dialog **doesn't show attendees or reminders** (data exists: `attendeeIds`/`reminders`) | `SiteCalendar.tsx:1226-1253` | add Attendees (avatar chips via `people`) + Reminders (`REMINDER_PRESETS`) rows |
| G-8 | S | **Delete has no confirmation** — `remove()` fires `form.delete` immediately | `SiteCalendar.tsx:1196-1198,1301` | AlertDialog confirm (reuse recur-scope dialog for series) per `POPUP_STYLE_GUIDE` |
| G-9 | S | `CreateEventDialog` stays mounted when closed (`<Dialog>` always in tree) → closing overlay can intercept clicks (~150 ms) | `SiteCalendar.tsx:1473-1475` | render conditionally (`createOpen && <CreateEventDialog/>`) like `EventDetailDialog` |
| G-10 | M | Global create with **0 accessible sites** still shows "New entry"; dialog opens with no options, submit silently disabled | `SiteCalendar.tsx:484,1444,691` | hide/disable "New entry" with tooltip when `sites.length===0` (per `feedback_hide_unbuilt_actions`) |
| G-11 | M | `+N more` opens the *(N+1)th* event instead of expanding the day | `_parts.tsx:547-553` | switch to Day view for that date (or a day popover listing all) |
| G-12 | S | QuickAdd type tiles show a plain colour **dot**, not the icon-in-tinted-square (prototype + create dialog do) | `SiteCalendar.tsx:1975` | render `<TypeIcon>` in a tinted square |
| G-13 | S | Drag-reschedule on a recurring occurrence **silently no-ops** | `SiteCalendar.tsx:488-504` | toast "Open the entry to reschedule a series" or show recur-scope dialog |
| G-14 | S | View-switch (hero + toolbar) has **no `tablist`/`tab`/`aria-selected`** roles | `SiteCalendar.tsx:591,904` | add `role=tablist`/`tab` + `aria-selected` + `aria-controls` |
| G-15 | S | Hero view buttons use `title=` not `aria-label=`; icon-only below `lg` → no reliable accessible name | `SiteCalendar.tsx:906-914` | swap `title` → `aria-label` |
| G-16 | S | Timeline event dots / all-day buttons have **no accessible label** + no focus ring | `_parts.tsx:773-780,427-438` | add `aria-label` (title+date+source) + `focus-visible:ring` |
| G-17 | M | `QuickAddMenu` (role=menu) has no focus management or arrow-key nav | `SiteCalendar.tsx:1921-2003` | focus first item on open; ArrowUp/Down cycling; Tab closes + restores focus |

---

## P3 — polish

| # | Effort | Gap | Location |
|---|---|---|---|
| G-18 | M | Hero "Live · synced just now" badge is a **hardcoded string**, not data-driven (stays green while loading/after failure) | `SiteCalendar.tsx:937-941` |
| G-19 | S | "This month" hero stat is period-agnostic — shows all-of-June while in Week/Day view | `SiteCalendar.tsx:428-434,1062` |
| G-20 | S | Detail dialog missing the relative-date pill ("in N days / N days ago") + priority pill + source-origin card | `SiteCalendar.tsx:1220-1281` |
| G-21 | S | Subscribe dialog missing the 3-provider instruction grid + "treat URL like a password" warning | `SiteCalendar.tsx:1855-1901` |
| G-22 | S | Agenda empty-state copy is ambiguous when all sources are filtered off vs genuinely empty | `_parts.tsx:682-685` |
| ~~G-23~~ ✅ | S | ~~Hero footer period label is a `<span>`, not a clickable mini-month jump-to-date~~ **Done 2026-06-05:** `JumpToDate` popover + `MiniMonth` grid on both the hero band and the profile toolbar; clicking a day sets `navDate`. | `SiteCalendar.tsx`, `_parts.tsx` |
| G-24 | S | No timezone indicator (NZT) anywhere; `parseDT` (`recur.ts:84`) trusts `new Date(s)` for naive strings | `SiteCalendar.tsx`, `recur.ts:84` |
| G-25 | S | `--src-vendor` / `--src-asset` (~52% L, low chroma) are borderline <4.5:1 on white at 11-12px (contrast) | `app.css:234-235` |
| G-26 | S | Decorative colour dots/bars not `aria-hidden`; source on/off conveyed by opacity only | `_parts.tsx:194-196`, `SiteCalendar.tsx:638-651` |
| G-27 | S | TodayRail "Now" marker mis-places after an all-day→timed transition when `now` is already past the timed item | `_parts.tsx:1002-1003` |

---

## Recommended sequencing for follow-up
1. **P1 data-correctness** (G-1 ✅) and the **deep-link** (G-2 ✅) — done.
2. **Accessibility pack** (G-3, G-4, G-14–G-17, G-26): one focused PR — grid roles + roving tabindex,
   focusable TimeBlock, tablist semantics, labels, `aria-hidden` on decoration.
3. **Detail/dialog completeness + safety** (G-5–G-13, G-20): error banner, loading overlay,
   attendees/reminders, delete confirm, conditional mount, empty-sites guard.
4. **Polish** (G-18, G-19, G-21–G-25, G-27).
