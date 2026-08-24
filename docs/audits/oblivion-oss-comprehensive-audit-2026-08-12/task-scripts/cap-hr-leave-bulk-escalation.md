# CAP-HR-LEAVE-BULK-ESCALATION: Bulk leave decisions and escalation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.leave.viewAny`, `permission:hr.leave.approve|hr.leave.manage`
- Owning module: Human resources
- Legacy family: `HR-LEAVE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/leave` (`hr.leave.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.leave.viewAny`, `permission:hr.leave.approve|hr.leave.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.leave.viewAny`, `permission:hr.leave.approve|hr.leave.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/leave` (`hr.leave.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/leave/bulk-approve` (`hr.leave.bulk-approve`, action `bulkApprove`). Source category: **mutation outcome source gap (bulkApprove)**; controller `app/Http/Controllers/Hr/LeaveController.php:689-729`; `request_ids`.
3. Invoke only the owning control for `POST hr/leave/bulk-decline` (`hr.leave.bulk-decline`, action `bulkDecline`). Source category: **mutation outcome source gap (bulkDecline)**; controller `app/Http/Controllers/Hr/LeaveController.php:731-771`; `request_ids`.
4. Invoke only the owning control for `POST hr/leave/escalate-now` (`hr.leave.escalate-now`, action `escalateNow`). Source category: **escalated/flagged**; controller `app/Http/Controllers/Hr/LeaveController.php:806-822`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (bulkApprove)** is applicable only to `bulkApprove` / `ROUTE-1497` at `app/Http/Controllers/Hr/LeaveController.php:689`; it is not runtime-observed.
- **mutation outcome source gap (bulkDecline)** is applicable only to `bulkDecline` / `ROUTE-1498` at `app/Http/Controllers/Hr/LeaveController.php:731`; it is not runtime-observed.
- **escalated/flagged** is applicable only to `escalateNow` / `ROUTE-1500` at `app/Http/Controllers/Hr/LeaveController.php:806`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1497` / `bulkApprove`: fields `request_ids`; success app/Http/Controllers/Hr/LeaveController.php:728 `return redirect()->back()->with('success', "{$approved} leave request(s) approved.");`; failure app/Http/Controllers/Hr/LeaveController.php:705 `return redirect()->back()->withErrors([`.
- `ROUTE-1498` / `bulkDecline`: fields `request_ids`; success app/Http/Controllers/Hr/LeaveController.php:770 `return redirect()->back()->with('success', "{$declined} leave request(s) declined.");`; failure app/Http/Controllers/Hr/LeaveController.php:747 `return redirect()->back()->withErrors([`.
- `ROUTE-1500` / `escalateNow`: success app/Http/Controllers/Hr/LeaveController.php:821 `return redirect()->back()->with('success', "{$escalated} request(s) escalated.");`.

## Failure and recovery paths

- `bulkApprove`: app/Http/Controllers/Hr/LeaveController.php:705 `return redirect()->back()->withErrors([`.
- `bulkDecline`: app/Http/Controllers/Hr/LeaveController.php:747 `return redirect()->back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/LeaveController.php:705 `return redirect()->back()->withErrors([`; app/Http/Controllers/Hr/LeaveController.php:728 `return redirect()->back()->with('success', "{$approved} leave request(s) approved.");`; app/Http/Controllers/Hr/LeaveController.php:747 `return redirect()->back()->withErrors([`; app/Http/Controllers/Hr/LeaveController.php:770 `return redirect()->back()->with('success', "{$declined} leave request(s) declined.");`; app/Http/Controllers/Hr/LeaveController.php:821 `return redirect()->back()->with('success', "{$escalated} request(s) escalated.");`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/leave/bulk-approve` — `hr.leave.bulk-approve` — `App\Http\Controllers\Hr\LeaveController@bulkApprove` — `app/Http/Controllers/Hr/LeaveController.php:689` — middleware `web, auth, permission:hr.leave.viewAny, permission:hr.leave.approve|hr.leave.manage`
- `POST hr/leave/bulk-decline` — `hr.leave.bulk-decline` — `App\Http\Controllers\Hr\LeaveController@bulkDecline` — `app/Http/Controllers/Hr/LeaveController.php:731` — middleware `web, auth, permission:hr.leave.viewAny, permission:hr.leave.approve|hr.leave.manage`
- `POST hr/leave/escalate-now` — `hr.leave.escalate-now` — `App\Http\Controllers\Hr\LeaveController@escalateNow` — `app/Http/Controllers/Hr/LeaveController.php:806` — middleware `web, auth, permission:hr.leave.viewAny, permission:hr.leave.approve|hr.leave.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/LeaveController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
