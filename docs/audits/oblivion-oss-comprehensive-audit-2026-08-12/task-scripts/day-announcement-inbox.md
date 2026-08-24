# DAY-ANNOUNCEMENT-INBOX: Announcement Inbox

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Frontline and My Day
- Legacy family: `DAY-ANNOUNCEMENT-INBOX`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST inbox/announcements/{announcement}/read` (`inbox.announcements.read`, action `markRead`). Source category: **mutation outcome source gap (markRead)**; controller `app/Http/Controllers/AnnouncementInboxController.php:10-24`; no exact validation fields extracted.
3. Invoke only the owning control for `POST inbox/announcements/read-all` (`inbox.announcements.readAll`, action `markAllRead`). Source category: **mutation outcome source gap (markAllRead)**; controller `app/Http/Controllers/AnnouncementInboxController.php:26-37`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (markRead)** is applicable only to `markRead` / `ROUTE-1833` at `app/Http/Controllers/AnnouncementInboxController.php:10`; it is not runtime-observed.
- **mutation outcome source gap (markAllRead)** is applicable only to `markAllRead` / `ROUTE-1834` at `app/Http/Controllers/AnnouncementInboxController.php:26`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1833` / `markRead`: success app/Http/Controllers/AnnouncementInboxController.php:23 `return back()->with('success', 'Announcement marked as read.');`.
- `ROUTE-1834` / `markAllRead`: success app/Http/Controllers/AnnouncementInboxController.php:36 `return back()->with('success', 'All announcements marked as read.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/AnnouncementInboxController.php:23 `return back()->with('success', 'Announcement marked as read.');`; app/Http/Controllers/AnnouncementInboxController.php:36 `return back()->with('success', 'All announcements marked as read.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST inbox/announcements/{announcement}/read` — `inbox.announcements.read` — `App\Http\Controllers\AnnouncementInboxController@markRead` — `app/Http/Controllers/AnnouncementInboxController.php:10` — middleware `web, auth`
- `POST inbox/announcements/read-all` — `inbox.announcements.readAll` — `App\Http\Controllers\AnnouncementInboxController@markAllRead` — `app/Http/Controllers/AnnouncementInboxController.php:26` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/AnnouncementInboxController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
