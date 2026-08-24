# FLEET-SERVICE-SCHEDULE: Service Schedule

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.maintenance.manage|fleet.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-SERVICE-SCHEDULE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/maintenance/schedules` (`fleet-assets.schedules.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.maintenance.manage|fleet.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.maintenance.manage|fleet.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/maintenance/schedules` (`fleet-assets.schedules.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST fleet-assets/maintenance/schedules` (`fleet-assets.schedules.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/ServiceScheduleController.php:202-223`; `asset_id`.
3. Invoke only the owning control for `PUT fleet-assets/maintenance/schedules/{schedule}` (`fleet-assets.schedules.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/FleetAssets/ServiceScheduleController.php:225-245`; `name`.
4. Invoke only the owning control for `POST fleet-assets/maintenance/schedules/{schedule}/mark-complete` (`fleet-assets.schedules.mark-complete`, action `markComplete`). Source category: **mutation outcome source gap (markComplete)**; controller `app/Http/Controllers/FleetAssets/ServiceScheduleController.php:182-200`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0777` at `app/Http/Controllers/FleetAssets/ServiceScheduleController.php:16`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0778` at `app/Http/Controllers/FleetAssets/ServiceScheduleController.php:202`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0779` at `app/Http/Controllers/FleetAssets/ServiceScheduleController.php:225`; it is not runtime-observed.
- **mutation outcome source gap (markComplete)** is applicable only to `markComplete` / `ROUTE-0780` at `app/Http/Controllers/FleetAssets/ServiceScheduleController.php:182`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/maintenance/schedules/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0778` / `store`: fields `asset_id`; success app/Http/Controllers/FleetAssets/ServiceScheduleController.php:222 `return back()->with('success', 'Service schedule created.');`.
- `ROUTE-0779` / `update`: fields `name`; success app/Http/Controllers/FleetAssets/ServiceScheduleController.php:244 `return back()->with('success', 'Service schedule updated.');`.
- `ROUTE-0780` / `markComplete`: success app/Http/Controllers/FleetAssets/ServiceScheduleController.php:199 `return back()->with('success', 'Schedule marked as completed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/ServiceScheduleController.php:215 `$schedule = FleetServiceSchedule::create($data);`; app/Http/Controllers/FleetAssets/ServiceScheduleController.php:238 `$schedule->update($data);`; app/Http/Controllers/FleetAssets/ServiceScheduleController.php:184 `$schedule->update([`; responses app/Http/Controllers/FleetAssets/ServiceScheduleController.php:54 `if (!$s['next_due_at'] || $s['is_overdue']) return false;`; app/Http/Controllers/FleetAssets/ServiceScheduleController.php:56 `return $dueDate->diffInDays($now, false) <= 14 && $dueDate->isFuture();`; app/Http/Controllers/FleetAssets/ServiceScheduleController.php:99 `return $completedAt->month === $month->month && $completedAt->year === $month->year;`; app/Http/Controllers/FleetAssets/ServiceScheduleController.php:101 `return ['label' => $month->format('M'), 'value' => $count];`; app/Http/Controllers/FleetAssets/ServiceScheduleController.php:126 `return [`; app/Http/Controllers/FleetAssets/ServiceScheduleController.php:168 `return Inertia::render('fleet-assets/maintenance/schedules/index', [`; app/Http/Controllers/FleetAssets/ServiceScheduleController.php:222 `return back()->with('success', 'Service schedule created.');`; app/Http/Controllers/FleetAssets/ServiceScheduleController.php:244 `return back()->with('success', 'Service schedule updated.');`; app/Http/Controllers/FleetAssets/ServiceScheduleController.php:199 `return back()->with('success', 'Schedule marked as completed.');`; audit calls app/Http/Controllers/FleetAssets/ServiceScheduleController.php:217 `AuditLogger::log('fleet.service_schedule.create', $schedule, [`; app/Http/Controllers/FleetAssets/ServiceScheduleController.php:240 `AuditLogger::log('fleet.service_schedule.update', $schedule, [`; app/Http/Controllers/FleetAssets/ServiceScheduleController.php:195 `AuditLogger::log('fleet.service_schedule.mark_complete', $schedule, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/maintenance/schedules` — `fleet-assets.schedules.index` — `App\Http\Controllers\FleetAssets\ServiceScheduleController@index` — `app/Http/Controllers/FleetAssets/ServiceScheduleController.php:16` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/maintenance/schedules` — `fleet-assets.schedules.store` — `App\Http\Controllers\FleetAssets\ServiceScheduleController@store` — `app/Http/Controllers/FleetAssets/ServiceScheduleController.php:202` — middleware `web, auth, permission:fleet.maintenance.manage|fleet.manage`
- `PUT fleet-assets/maintenance/schedules/{schedule}` — `fleet-assets.schedules.update` — `App\Http\Controllers\FleetAssets\ServiceScheduleController@update` — `app/Http/Controllers/FleetAssets/ServiceScheduleController.php:225` — middleware `web, auth, permission:fleet.maintenance.manage|fleet.manage`
- `POST fleet-assets/maintenance/schedules/{schedule}/mark-complete` — `fleet-assets.schedules.mark-complete` — `App\Http\Controllers\FleetAssets\ServiceScheduleController@markComplete` — `app/Http/Controllers/FleetAssets/ServiceScheduleController.php:182` — middleware `web, auth, permission:fleet.maintenance.manage|fleet.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/ServiceScheduleController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/maintenance/schedules/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
