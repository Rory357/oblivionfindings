# CAP-GOV-GOVERNANCE-MEETING-ATTENDANCE-RSVP: Meeting attendance and RSVP

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.meetings.view`, `permission:governance.meetings.manage`
- Owning module: Governance
- Legacy family: `GOV-GOVERNANCE-MEETING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/meetings` (`governance.meetings.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.meetings.view`, `permission:governance.meetings.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.meetings.view`, `permission:governance.meetings.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/meetings` (`governance.meetings.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST governance/meetings/{meeting}/attendance` (`governance.meetings.attendance.record`, action `recordAttendance`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:315-344`; `attendance`.
3. Invoke only the owning control for `POST governance/meetings/{meeting}/rsvp` (`governance.meetings.rsvp`, action `submitRsvp`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:390-415`; `dietary_requirements`, `notes`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `recordAttendance` / `ROUTE-0941` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:315`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitRsvp` / `ROUTE-0948` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:390`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0941` / `recordAttendance`: fields `attendance`; success app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:343 `return redirect()->back()->with('success', 'Attendance recorded.');`.
- `ROUTE-0948` / `submitRsvp`: fields `dietary_requirements`, `notes`; success app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:414 `return redirect()->back()->with('success', 'RSVP recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:327 `MeetingAttendance::updateOrCreate(`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:403 `\App\Domain\Governance\Models\MeetingRsvp::updateOrCreate(`; responses app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:343 `return redirect()->back()->with('success', 'Attendance recorded.');`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:400 `return redirect()->back()->with('error', 'You are not a board member.');`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:414 `return redirect()->back()->with('success', 'RSVP recorded.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST governance/meetings/{meeting}/attendance` — `governance.meetings.attendance.record` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@recordAttendance` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:315` — middleware `web, auth, permission:governance.meetings.view, permission:governance.meetings.manage`
- `POST governance/meetings/{meeting}/rsvp` — `governance.meetings.rsvp` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@submitRsvp` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:390` — middleware `web, auth, permission:governance.meetings.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
