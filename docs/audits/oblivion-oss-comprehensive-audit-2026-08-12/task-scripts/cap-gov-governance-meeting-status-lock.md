# CAP-GOV-GOVERNANCE-MEETING-STATUS-LOCK: Meeting status advancement and lock

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
2. Invoke only the owning control for `POST governance/meetings/{meeting}/advance-status` (`governance.meetings.advance-status`, action `advanceStatus`). Source category: **mutation outcome source gap (advanceStatus)**; controller `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:377-388`; no exact validation fields extracted.
3. Invoke only the owning control for `POST governance/meetings/{meeting}/lock` (`governance.meetings.lock`, action `lockMeeting`). Source category: **mutation outcome source gap (lockMeeting)**; controller `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:346-357`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (advanceStatus)** is applicable only to `advanceStatus` / `ROUTE-0937` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:377`; it is not runtime-observed.
- **mutation outcome source gap (lockMeeting)** is applicable only to `lockMeeting` / `ROUTE-0943` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:346`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0937` / `advanceStatus`: success app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:387 `return redirect()->back()->with('success', 'Meeting status advanced to: ' . str_replace('_', ' ', $meeting->fresh()->status));`.
- `ROUTE-0943` / `lockMeeting`: success app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:356 `return redirect()->back()->with('success', 'Meeting locked. No further edits allowed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:384 `return redirect()->back()->with('error', 'Cannot advance meeting status. Check prerequisites.');`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:387 `return redirect()->back()->with('success', 'Meeting status advanced to: ' . str_replace('_', ' ', $meeting->fresh()->status));`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:351 `return redirect()->back()->with('error', 'Meeting is already locked.');`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:356 `return redirect()->back()->with('success', 'Meeting locked. No further edits allowed.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST governance/meetings/{meeting}/advance-status` — `governance.meetings.advance-status` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@advanceStatus` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:377` — middleware `web, auth, permission:governance.meetings.view, permission:governance.meetings.manage`
- `POST governance/meetings/{meeting}/lock` — `governance.meetings.lock` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@lockMeeting` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:346` — middleware `web, auth, permission:governance.meetings.view, permission:governance.meetings.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
