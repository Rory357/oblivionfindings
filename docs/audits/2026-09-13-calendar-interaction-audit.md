# Calendar interaction audit — 13 September 2026

Scope: `/my-calendar`, the shared Site Calendar at `/calendar`, their feeds and creation/editing paths, and Settings → Calendar Sync. This is an implementation audit and improvement backlog for the existing single-organisation application. It is not a claim that external provider credentials, live email delivery or every source module have been validated.

## Result of this change

My Calendar previously passed `canCreate={false}` and mapped every event to `editable: false`. The shared creation menu and drag handlers therefore had no usable personal workflow. The page now reuses the same Site Calendar with an owner-private planning feed and editor.

- Create a personal task, meeting, appointment or reminder from New entry, a blank month date, a day/week time slot, or the right-click menu. Selected dates and quarter hours carry into the editor.
- Open existing personal entries to edit their title, times, all-day range, location/link, notes and status. Completed and cancelled are explicit statuses. Duplicate starts a new unsaved entry.
- Move personal entries between month dates, or move/resize timed entries in day/week views. Deletion and rescheduling offer version-protected Undo.
- Staff can access only their own personal entries. An administrator has no owner bypass. Approved staff eligibility is required; portal accounts cannot create entries. Assigned shifts, medication rounds, leave and operational tasks keep their source workflows and permissions.
- Saves retain failed drafts, guard double submissions, use a persisted creation request identity, and reject stale versions. Deletion is soft deletion; restore requires ownership and the current version.
- Keyboard users can create from month dates and hourly slots. Arrow keys move through hourly slots with one tab stop per day. Touch users have ordinary taps and the full editor; scrolling an event does not start a drag.
- Multi-day and overnight entries are rendered on each overlapping grid day. Time blocks are clipped at midnight, and an exclusive end at midnight does not create another day. Agenda includes entries continuing from the previous month.
- Rory's calendar guide now documents these interaction requirements alongside the shared header, source palette and prominent day/month/year anchor.

Personal meetings are private planning blocks and do not invite attendees. Personal reminders are calendar entries and do not yet issue timed notifications. Organisation-managed provider sync remains the work-shift export described in `docs/work-calendar-sync.md`; these new private entries are not silently exported.

## Highest-value next improvements

### 1. Join personal tasks to the work queue — high priority

Evidence: the new `PersonalCalendarEntry` is intentionally owner-private. The existing task aggregator and shift-task workflow use source-specific/site or explicitly global authorization, not this owned-record boundary. A private planning task currently appears in My Calendar only.

Add an explicitly owner-scoped task provider and expose the same record in My Day / All Tasks. Preserve a single completion state, date and link; do not create a second task when scheduling one. Acceptance: rescheduling or completing from either surface updates the other, and direct requests from another worker or an administrator are denied unless a separately designed sharing rule applies.

### 2. Real meeting invitations and responses — high priority

Evidence: personal planning has no attendee, RSVP or delivery model. Site Calendar has attendee fields; these must not be mistaken for a tested, end-to-end personal meeting invitation workflow.

Design internal invitations with organizer/attendee permissions, accept/decline/tentative, cancellation notices and delivery status. Then add external invites and conferencing links using the organisation's configured provider. Acceptance: one meeting identity, no duplicate invitations on retries, reliable cancellation, and no invitation sent simply by saving a private planning block.

### 3. Scheduled reminder delivery — high priority

Evidence: personal entries store a start time and kind, but no notification lead time or delivery receipt.

Add an opt-in reminder time, quiet hours and in-app delivery first, with a retry-safe receipt and user-visible delivery failures. Acceptance: editing, completing, cancelling or deleting an entry cancels obsolete notifications; a retry cannot notify twice. Avoid promising email or push delivery until those paths have been tested.

### 4. Broaden provider sync with explicit choices — high priority

Evidence: `app/Services/WorkCalendar` exports assigned shifts. New personal planning records are outside that export. Local Google/Microsoft credentials were unconfigured during the preceding settings verification.

After an administrator configures and verifies the organisation connection, add an explicit setting for which extra entry types may be exported. Choose a clear direction and conflict policy before importing provider edits. Acceptance: one provider event per local record, private content stays private, deleted/cancelled entries reconcile, and the worker can see last success plus an actionable failure. Do not infer all calendar content may be shared from authorization to sync work shifts.

### 5. Validate feed scope and date ranges consistently — high priority

Evidence: MyCalendarController scopes existing records by assigned user. Source queries have different range conventions, and malformed input currently falls back to this week. Assigned-user filtering alone does not document the approved-site boundary for every source record.

Audit each source against its canonical record policy and approved-site rules, including revoked assignments and changed sites. Validate start/end ordering and a reasonable maximum window, with a visible error instead of silently returning another period. Acceptance: tests cover exact midnight boundaries, explicit timezone offsets, revoked site access, and another user's record ID. Keep the application single-organisation; no tenant selectors or new tenant columns are needed.

## Further improvements

### Recurrence and exceptions — medium priority

Site Calendar already has recurrence and occurrence-editing infrastructure. Personal entries do not. Reuse its concepts after checking the server contract: support “this occurrence”, “this and following” and “whole series”, with previewed dates and bounded expansion. Test daylight saving, month-end dates, deleted occurrences and external recurrence reconciliation.

### Conflict and availability feedback — medium priority

Show a helpful warning when a proposed personal meeting overlaps the worker's shift or another personal entry. Distinguish an informational overlap from a booking that must be blocked. Check room/site/resource availability only when the user is actually making such a reservation, using the relevant permission. Never expose another worker's private event details through a conflict message.

### One timezone convention across sources — medium priority

Personal saves now send explicit offsets and the editor identifies the browser timezone. The application also defines `app.worker_timezone`. Align the displayed calendar timezone, editor, source timestamps and exports with the chosen worker/site convention; allow an explicit override if required. Add New Zealand daylight-saving and travel/timezone coverage, especially all-day spans and midnight boundaries.

### Better recovery after changes elsewhere — medium priority

Personal entries reject stale versions, but the editor currently asks the worker to reopen the record. Offer “Load latest” alongside a retained copy of the draft, then field-level comparison where justified. Add a refresh signal after background changes and a visible last-refreshed timestamp. Apply equivalent conflict protection to existing Site Calendar mutations, whose adapter still uses its own server update contract.

### Audit history and deleted-entry recovery — medium priority

Personal entries have version numbers and soft deletion, but no user-facing change history or deleted-items browser. Add a proportionate history recording actor, operation and relevant changed fields, plus a private recycle bin. Define retention with the application's existing policy rather than inventing indefinite retention. The current toast Undo lasts ten seconds; later recovery should be discoverable.

### Mobile density and touch accessibility — medium priority

The header, filter pills and all five views share Rory's existing design. Dense month chips and timeline markers are smaller than the ideal 44 px touch target; avoid solving this by expanding seven columns beyond the screen. Provide a day summary/bottom sheet or an agenda-first mobile preference with 44 px actions. Test both narrow phones and landscape, zoom, long event titles and large text. Preserve visible creation controls without relying on hover.

### Make status and continuation easier to scan — medium priority

Add a clear Completed/Cancelled treatment to month chips, a “continues” label on overnight segments, and visible start/end dates for multi-day entries in Agenda. Keep labels in addition to colour. Ensure totals consistently explain whether they count records, occurrences or currently filtered entries.

### Partial-feed health and monitoring — medium priority

The working tree includes a per-source unavailable header and shared warning treatment. Complete fault-injection coverage for each provider and distinguish “no records” from “source unavailable”. Keep errors out of the calendar payload, log diagnostic context safely, and expose a retry action. Check the Today rail independently so its failure cannot look like an empty day.

### Preserve filters and useful deep links — lower priority

Remember view, selected sources and date using scoped preferences or URL state. Extend deep links to a specific day/view while retaining the private-entry owner check. Define what “Today” resets: date only, without unexpectedly discarding filters.

### Performance and larger calendars — medium priority

Local performance logs during browser verification recorded approximately 111–116 database queries per entry/feed request, including shared authentication and application middleware. This was a local environment with concurrent development work, not a production latency benchmark. Profile the middleware/shared-prop overhead before adding more feed sources.

Measure rendering with busy sites, long recurrence series, many enabled sources and overlapping meetings. Bound feed requests, index the actual query paths, cancel stale requests and virtualize long agendas where measurement warrants it. Cache only with the correct owner/permission boundary and invalidate after mutations.

### Export, subscriptions and print — lower priority

Verify ICS all-day end dates, timezone changes, escaping, cancellation and duplicates across supported consumers. Offer a clear print agenda. A downloadable ICS snapshot is different from ongoing sync; label it accordingly. Keep personal subscription tokens revocable if that feature is introduced.

### Consistent discovery across the application — lower priority

Settings → Calendar Sync is the canonical management page; the deleted Operations implementation has compatibility redirects for old bookmarks. Check help text, command-palette links and administrative documentation whenever routes change. Migrate other calendar surfaces to Rory's shared presentation as those modules are touched, preserving their own permissions and workflows.

## Verification record

Verified on the real Herd host at `https://oblivionfindings.test/my-calendar`, using the current workspace and built assets, with no `public/hot` override:

- Backend feature tests: **12 passed, 76 assertions**. Covers all four entry kinds, ownership including admin denial, portal/revoked-account denial, one feed item per saved entry, retry idempotency, date validation, stale versions, status changes, deletion and restore.
- Frontend tests: **9 passed**. Covers feed mapping/failure state, seeded dates and quarter hours, draft preservation, hourly keyboard navigation, overnight clipping, and preventing event-button Enter from creating a blank entry.
- Final Vite production build passed in **4m 33s**; the emitted calendar bundle includes the keyboard-event guard. Focused ESLint, PHP Pint and whitespace checks passed. Final repository-wide TypeScript checking reports errors in IT wizard/index/tests and `today-retirement.test.tsx`, with none in the changed calendar files.
- Chrome desktop: right-clicked 15 September, created a disposable personal task, saved Completed, reopened it, deleted/restored with Undo, dragged it to 16 September, moved 9–10am to 10–11am and resized to 11:30am. The noon slot opened a 12–1pm editor. The disposable entry was deleted after verification.
- Chrome phone viewport: **390 × 844**, no document overflow; the editor fit with a 362 px dialog and 44 px main inputs/selects/actions. Creation is available through the visible New entry action. This is responsive browser verification, not a physical-device gesture certification.
- The shared calendar retains Month, Week, Day, Agenda and Timeline. The header date followed the viewed period with full month/year. No browser JavaScript errors were observed during the workflow.
- `/calendar` also loaded successfully after the shared interaction changes, retaining its own sources, sites, creation action and prominent date anchor.
- Only the new personal-entry migration was applied to the local app. No calendar invitation, reminder message, provider grant or live external sync was sent during verification.

Relevant implementation and tests:

- `app/Http/Controllers/PersonalCalendarEntryController.php`
- `app/Models/PersonalCalendarEntry.php`
- `tests/Feature/PersonalCalendarEntryTest.php`
- `resources/js/lib/my-calendar-adapter.test.ts`
- `resources/js/pages/sites/calendar/personal-entry-dialog.test.tsx`
- `resources/js/pages/sites/calendar/SiteCalendar.tsx` and `_parts.tsx`

The audit identifies further work; it does not mark invitations, notification delivery, personal recurrence, universal task integration or live provider synchronization as completed.
