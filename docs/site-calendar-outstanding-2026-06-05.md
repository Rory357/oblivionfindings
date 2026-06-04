# Site Calendar + Calendar Sync — outstanding work (2026-06-05)

Single consolidated backlog of everything still open across the Site Calendar
(`/calendar`) and the new admin **Calendar Sync** (Settings → Calendar Sync, "Part D").
Scope is **desktop web only** — mobile/tablet/touch concerns are intentionally out of scope.

Companion docs:
[`site-calendar-ux-gap-analysis-2026-06-04.md`](site-calendar-ux-gap-analysis-2026-06-04.md)
(full UX audit) and the prototype spec in `Site Calanders Page/CLAUDE.md`.

**Shipped already (for reference, not outstanding):** the whole calendar redesign (5 views,
drag-move + week/day resize, hover, right-click quick-add, approvals, Today rail, recurrence,
room, asset/emergency sources, per-user subscribe feed); the admin Calendar Sync feature
(connect M365/Google, per-house→resource-calendar mapping, push, per-house `.ics` feed,
cadence job, settings UI); and gaps **G-1** (hero counts), **G-2** (hero deep-link),
**G-23** (mini-month "jump to date" picker).

---

## Settled design decisions (don't re-litigate)

### Calendar Sync stays separate from SSO — *not* merged
**Decision (2026-06-05):** keep admin **Calendar Sync** as its own page under **Integrations**.
Do **not** move it under Identity & SSO, and do **not** merge it into the SSO config page.

**Why** — they share an identity provider but serve different purposes:
- **SSO = authentication:** per-user login (`Auth\GoogleController` / `Auth\MicrosoftController` →
  `Identity`); the `/settings/sso` page only manages **group → role mappings**, and `fetchGroups`
  borrows the current admin's *personal* `Identity` token.
- **Calendar Sync = data integration:** an **org-level** service connection
  (`CalendarSyncConnection`, keyed tenant+provider) with **resource-calendar scopes**
  (`Calendars.ReadWrite.Shared`, `Place.Read.All`, `calendar`) that writes *other* mailboxes'
  calendars.

Grouping by "uses Microsoft/Google" instead of by function worsens the IA, and merging would bloat
the SSO page (group-mappings + house-mappings + provider connect + cadence in one place). The
expensive part — the OAuth plumbing — is **already shared**: same `config/services.php` app
credentials, and one refresh implementation via the `CalendarOAuthToken` contract that both
`Identity` and `CalendarSyncConnection` implement. So consolidating the UI removes no real
duplication; only the connection *record* differs, correctly (SSO has no org connection to reuse).

**When to revisit:** if a *second* org-level consumer appears — e.g. SSO group sync via an
app/service token instead of a logged-in admin's identity, or Graph mail-send — introduce a single
**"Workspace / Org connections"** hub that both SSO and Calendar Sync draw an org token from, rather
than each provider sprouting its own "Connect" button. Until then a dedicated Calendar Sync page is
the simpler choice (YAGNI). To pre-position for it cheaply, rename `CalendarSyncConnection` → a
generic `WorkspaceConnection` (no behaviour change).

---

## A. Calendar Sync (Part D) — remaining

### A1. Live OAuth provider setup — **IT / ops, not code** (blocks live connect)
The connect buttons show **"Not configured"** until real OAuth apps exist. Required:
- **Google:** create an OAuth client in Google Cloud; set `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`;
  add redirect URI `https://<host>/settings/calendar-sync/callback/google`; scope
  `https://www.googleapis.com/auth/calendar`. Resource calendars require the connected admin to
  have access (or Workspace domain-wide delegation).
- **Microsoft 365:** register an Entra app; set `MICROSOFT_CLIENT_ID` / `MICROSOFT_CLIENT_SECRET` /
  `MICROSOFT_TENANT_ID`; add redirect URI `.../settings/calendar-sync/callback/microsoft`; grant +
  admin-consent `Calendars.ReadWrite`, `Calendars.ReadWrite.Shared`, `Place.Read.All`, `offline_access`.
- Code, token encryption, push, feed, and cadence are all in place and tested (mocked HTTP); only the
  credentials/registration are missing.

### A2. Two-way sync: surface external busy as conflicts — **code, effort L**
Today, two-way mappings **pull** external events (`CalendarSyncService::pullBusy`) and the
`conflict_policy` setting is stored, but external busy is **not surfaced** in the calendar's conflict
warning. To finish two-way:
- Persist pulled busy blocks (new `calendar_sync_busy_blocks` table, or cache) keyed by site+range.
- Expose them via `SiteCalendarAggregator` as a read-only `external` source (+ `--src-external`
  palette token + frontend `DEFAULT_SOURCES` entry + legend).
- Feed them into the create-dialog conflict check (`findConflicts`) when `conflict_policy =
  external_busy_counts`.
Until then "Two-way" only differs from "One-way" by performing the pull (logged, not shown).

### A3. Native recurring push (optional polish) — **code, effort M**
Recurring manual events currently reach resource calendars only via the per-house `.ics` feed
(by design — no fragile RRULE translation). Optional upgrade: push `RRULE` natively to Google
(trivial), and translate to Graph `patternedRecurrence` for Microsoft (harder). Low priority.

### A4. Post-deploy verification on dev — **ops**
After the deploy lands on oblivionfindings.com, confirm: Settings → Calendar Sync renders (sidebar
item under Integrations); the hero overflow "Calendar sync settings" deep-link shows for admins; the
mapping table lists houses; the 4 migrations ran (incl. `encrypt_existing_identity_tokens`). Hard-reload
(Ctrl+Shift+R) for stale chunks. No seeders needed (permission reused).

### A5. Pre-existing unrelated bug (found during discovery) — **code, effort S**
`App\Http\Controllers\Operations\CalendarSyncController::triggerSync` writes a `sync_status` column
that the `calendar_syncs` migration never creates → it would error if invoked. This is the **per-user**
legacy calendar-sync (separate from Part D); fix the column or drop the write.

---

## B. Calendar UI/UX gaps (desktop) — remaining

Priorities: **P1** = correctness / accessibility-critical · **P2** = important · **P3** = polish.
Effort: **S** small · **M** medium · **L** large.

### P1
| # | Effort | Gap | Location |
|---|---|---|---|
| G-3 | L | Calendar grids have **no ARIA grid semantics & no keyboard navigation** (MonthView is `<div onClick>` cells; no `role=grid/row/gridcell`, no arrow-key roving tabindex; empty days unreachable). Add roles + `aria-current` + arrow nav + `Enter`/`n` to create. | `_parts.tsx:499-560` |
| G-4 | M | `TimeBlock` events (Week/Day) **not keyboard accessible** (`<div onPointerDown>`, no tabIndex/role/onKeyDown). Make it a focusable `button` with `onKeyDown→onSelect`; expose time editing via the detail dialog. | `_parts.tsx:325-413` |

### P2
| # | Effort | Gap | Location |
|---|---|---|---|
| G-5 | S | Network/403 fetch errors **silently swallowed** (events → `[]`), so a failure looks like "no events". Add `fetchError` state + alert banner + Retry; distinguish 403 vs network. | `SiteCalendar.tsx:341-351,368-376` |
| G-6 | M | Loading shows a tiny line **below** the grid; grid flashes empty on every nav/view change. Overlay `opacity-50`/skeleton on the view body while loading. | `SiteCalendar.tsx` (loading render + `fetchEvents`) |
| G-7 | M | Detail dialog **doesn't show attendees or reminders** (data exists: `attendeeIds`/`reminders`). Add Attendees (avatar chips via `people`) + Reminders (`REMINDER_PRESETS`) rows. | `SiteCalendar.tsx` (EventDetailDialog `<dl>`) |
| G-8 | S | **Delete has no confirmation** — fires `form.delete` immediately. Add an AlertDialog confirm (reuse recur-scope dialog for series) per `POPUP_STYLE_GUIDE`. | `SiteCalendar.tsx` (`remove()` + Delete button) |
| G-9 | S | `CreateEventDialog` stays mounted when closed → closing overlay can intercept clicks (~150ms). Render conditionally like `EventDetailDialog`. | `SiteCalendar.tsx` (CreateEventDialog mount) |
| G-10 | M | Global create with **0 accessible sites** still shows "New entry"; dialog opens with no options, submit silently disabled. Hide/disable with tooltip when `sites.length===0`. | `SiteCalendar.tsx` |
| G-11 | M | `+N more` opens the *(N+1)th* event instead of expanding the day. Switch to Day view for that date (or a day popover listing all). | `_parts.tsx:547-553` |
| G-12 | S | QuickAdd type tiles show a plain colour **dot**, not the icon-in-tinted-square (prototype + create dialog do). Render `<TypeIcon>` in a tinted square. | `SiteCalendar.tsx` (QuickAddMenu tile) |
| G-13 | S | **Drag-reschedule on a recurring occurrence silently no-ops.** Either toast "Open the entry to reschedule a series", or show the recur-scope dialog (this/following/all). *(Offered to do this next.)* | `SiteCalendar.tsx` (`reschedule()`) |
| G-14 | S | View-switch (hero + toolbar) has **no `tablist`/`tab`/`aria-selected`** roles. | `SiteCalendar.tsx` (both view switches) |
| G-15 | S | Hero view buttons use `title=` not `aria-label=`; icon-only below `lg` → no reliable accessible name. | `SiteCalendar.tsx` (hero view buttons) |
| G-16 | S | Timeline event dots / all-day buttons have **no accessible label** + no focus ring. Add `aria-label` (title+date+source) + `focus-visible:ring`. | `_parts.tsx` (TimelineView dots, AllDayRow) |
| G-17 | M | `QuickAddMenu` (`role=menu`) has **no focus management or arrow-key nav**. Focus first item on open; ArrowUp/Down cycling; Tab closes + restores focus. | `SiteCalendar.tsx` (QuickAddMenu) |

### P3
| # | Effort | Gap | Location |
|---|---|---|---|
| G-18 | M | Hero "Live · synced just now" badge is a **hardcoded string** (stays green while loading / after a failed fetch). Drive it from real fetch state. | `SiteCalendar.tsx` (hero title badge) |
| G-19 | S | "This month" hero stat is period-agnostic — shows all-of-month while in Week/Day view. Relabel per active view or scope the count. | `SiteCalendar.tsx` (hero stats) |
| G-20 | S | Detail dialog missing the relative-date pill ("in N days / N days ago") + priority pill + source-origin card. | `SiteCalendar.tsx` (EventDetailDialog header) |
| G-21 | S | Subscribe dialog missing the 3-provider instruction grid + "treat URL like a password" warning. | `SiteCalendar.tsx` (SubscribeDialog) |
| G-22 | S | Agenda empty-state copy is ambiguous when all sources are filtered off vs genuinely empty. | `_parts.tsx` (AgendaView empty state) |
| G-24 | S | No timezone indicator (NZT) anywhere; `parseDT` trusts `new Date(s)` for naive strings (ensure the backend always emits UTC `Z`). | `SiteCalendar.tsx`, `recur.ts:84` |
| G-25 | S | `--src-vendor` / `--src-asset` (~52% L, low chroma) are borderline <4.5:1 on white at 11-12px. Boost chroma. | `app.css:234-235` |
| G-26 | S | Decorative colour dots/bars not `aria-hidden`; source on/off conveyed by opacity only. | `_parts.tsx`, `SiteCalendar.tsx` (legend) |
| G-27 | S | TodayRail "Now" marker mis-places after an all-day→timed transition when `now` is already past the timed item. | `_parts.tsx:1002-1003` |

---

## Suggested order for a fresh context
1. **Accessibility pack** (G-3, G-4, G-14–G-17, G-26) — one focused PR: grid roles + roving tabindex,
   focusable TimeBlock, tablist semantics, labels, `aria-hidden` on decoration.
2. **Dialog completeness + safety** (G-5–G-13, G-20) — error banner, loading overlay, attendees/
   reminders, delete confirm, conditional mount, empty-sites guard, recurring-drag prompt.
3. **Calendar Sync two-way** (A2) — busy-block store → aggregator source → conflict check.
4. **Polish** (G-18, G-19, G-21, G-22, G-24, G-25, G-27) + native recurring push (A3) + the per-user
   `triggerSync` bug (A5).

> Live OAuth (A1) and post-deploy verification (A4) are ops/IT, not engineering — do them whenever the
> org is ready to connect real Google/Microsoft accounts.
