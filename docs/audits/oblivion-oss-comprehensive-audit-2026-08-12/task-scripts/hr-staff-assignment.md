# HR-STAFF-ASSIGNMENT: Staff Assignment

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:staff.assignments.update`
- Owning module: Human resources
- Legacy family: `HR-STAFF-ASSIGNMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `staff/{user}/assignments` (`staff.assignments.edit`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:staff.assignments.update`.
- Exact middleware atoms: `web`, `auth`, `permission:staff.assignments.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD staff/{user}/assignments` (`staff.assignments.edit`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT staff/{user}/assignments` (`staff.assignments.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/StaffAssignmentController.php:30-56`; `client_ids`.

## Source-applicable states and transitions

- **information presented** is applicable only to `edit` / `ROUTE-2919` at `app/Http/Controllers/StaffAssignmentController.php:12`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2920` at `app/Http/Controllers/StaffAssignmentController.php:30`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/staff/assignments.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2920` / `update`: fields `client_ids`; success app/Http/Controllers/StaffAssignmentController.php:55 `return redirect()->back()->with('success', 'Assignments updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/StaffAssignmentController.php:43 `$user->assignedClients()->sync($data['client_ids'] ?? []);`; responses app/Http/Controllers/StaffAssignmentController.php:23 `return inertia('staff/assignments', [`; app/Http/Controllers/StaffAssignmentController.php:55 `return redirect()->back()->with('success', 'Assignments updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD staff/{user}/assignments` — `staff.assignments.edit` — `App\Http\Controllers\StaffAssignmentController@edit` — `app/Http/Controllers/StaffAssignmentController.php:12` — middleware `web, auth, permission:staff.assignments.update`
- `PUT staff/{user}/assignments` — `staff.assignments.update` — `App\Http\Controllers\StaffAssignmentController@update` — `app/Http/Controllers/StaffAssignmentController.php:30` — middleware `web, auth, permission:staff.assignments.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/StaffAssignmentController.php`.
- Exact render/action page relationships: `resources/js/pages/staff/assignments.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
