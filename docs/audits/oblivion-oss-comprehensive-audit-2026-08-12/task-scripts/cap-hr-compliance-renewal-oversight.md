# CAP-HR-COMPLIANCE-RENEWAL-OVERSIGHT: Compliance renewal oversight and reminders

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.compliance.view`, `permission:hr.compliance.manage`
- Owning module: Human resources
- Legacy family: `HR-COMPLIANCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/compliance` (`hr.compliance.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.compliance.view`, `permission:hr.compliance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.compliance.view`, `permission:hr.compliance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/compliance` (`hr.compliance.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/compliance/bulk-remind` (`hr.compliance.bulk.remind`, action `bulkRemind`). Source category: **mutation outcome source gap (bulkRemind)**; controller `app/Http/Controllers/Hr/ComplianceController.php:529-552`; `user_ids`.
3. Invoke only the owning control for `POST hr/compliance/renewals/remind` (`hr.compliance.renewals.remind`, action `renewalRemind`). Source category: **mutation outcome source gap (renewalRemind)**; controller `app/Http/Controllers/Hr/ComplianceController.php:679-696`; `type`.
4. Invoke only the owning control for `POST hr/compliance/renewals/snooze` (`hr.compliance.renewals.snooze`, action `renewalSnooze`). Source category: **mutation outcome source gap (renewalSnooze)**; controller `app/Http/Controllers/Hr/ComplianceController.php:698-719`; `type`.

## Source-applicable states and transitions

- **mutation outcome source gap (bulkRemind)** is applicable only to `bulkRemind` / `ROUTE-1356` at `app/Http/Controllers/Hr/ComplianceController.php:529`; it is not runtime-observed.
- **mutation outcome source gap (renewalRemind)** is applicable only to `renewalRemind` / `ROUTE-1367` at `app/Http/Controllers/Hr/ComplianceController.php:679`; it is not runtime-observed.
- **mutation outcome source gap (renewalSnooze)** is applicable only to `renewalSnooze` / `ROUTE-1368` at `app/Http/Controllers/Hr/ComplianceController.php:698`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1356` / `bulkRemind`: fields `user_ids`; success app/Http/Controllers/Hr/ComplianceController.php:551 `return redirect()->back()->with('success', "Reminders sent to {$count} staff.");`.
- `ROUTE-1367` / `renewalRemind`: fields `type`; success app/Http/Controllers/Hr/ComplianceController.php:695 `return redirect()->back()->with('success', "Reminder sent to {$recipient->name}.");`.
- `ROUTE-1368` / `renewalSnooze`: fields `type`; success app/Http/Controllers/Hr/ComplianceController.php:718 `return redirect()->back()->with('success', "Snoozed for {$days} days.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/ComplianceController.php:715 `$status->update(['next_check_at' => now()->addDays($days)]);`; responses app/Http/Controllers/Hr/ComplianceController.php:551 `return redirect()->back()->with('success', "Reminders sent to {$count} staff.");`; app/Http/Controllers/Hr/ComplianceController.php:695 `return redirect()->back()->with('success', "Reminder sent to {$recipient->name}.");`; app/Http/Controllers/Hr/ComplianceController.php:718 `return redirect()->back()->with('success', "Snoozed for {$days} days.");`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Hr/ComplianceController.php:542 `$recipient->notify(new \App\Notifications\ComplianceReminderNotification(`; app/Http/Controllers/Hr/ComplianceController.php:693 `$recipient->notify(new \App\Notifications\ComplianceReminderNotification($label, $date, $user->name));`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST hr/compliance/bulk-remind` — `hr.compliance.bulk.remind` — `App\Http\Controllers\Hr\ComplianceController@bulkRemind` — `app/Http/Controllers/Hr/ComplianceController.php:529` — middleware `web, auth, permission:hr.compliance.view, permission:hr.compliance.manage`
- `POST hr/compliance/renewals/remind` — `hr.compliance.renewals.remind` — `App\Http\Controllers\Hr\ComplianceController@renewalRemind` — `app/Http/Controllers/Hr/ComplianceController.php:679` — middleware `web, auth, permission:hr.compliance.view`
- `POST hr/compliance/renewals/snooze` — `hr.compliance.renewals.snooze` — `App\Http\Controllers\Hr\ComplianceController@renewalSnooze` — `app/Http/Controllers/Hr/ComplianceController.php:698` — middleware `web, auth, permission:hr.compliance.view, permission:hr.compliance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/ComplianceController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
