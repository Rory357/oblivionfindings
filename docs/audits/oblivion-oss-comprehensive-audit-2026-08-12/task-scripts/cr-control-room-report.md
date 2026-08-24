# CR-CONTROL-ROOM-REPORT: Control Room Report

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:controlRoom.reports.view|controlRoom.viewAny`
- Owning module: Control Room
- Legacy family: `CR-CONTROL-ROOM-REPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `control-room/reports` (`control-room.reports.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:controlRoom.reports.view|controlRoom.viewAny`.
- Exact middleware atoms: `web`, `auth`, `permission:controlRoom.reports.view|controlRoom.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD control-room/reports` (`control-room.reports.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD control-room/reports/alerts` (`control-room.reports.alerts`, action `alerts`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:88-97`.
3. Use `GET|HEAD control-room/reports/export` (`control-room.reports.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:144-193`.
4. Use `GET|HEAD control-room/reports/sla` (`control-room.reports.sla`, action `sla`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:74-83`.
5. Use `GET|HEAD control-room/reports/summary` (`control-room.reports.summary`, action `summary`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:116-139`.
6. Use `GET|HEAD control-room/reports/workload` (`control-room.reports.workload`, action `workload`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:102-111`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0284` at `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:23`; it is not runtime-observed.
- **information presented** is applicable only to `alerts` / `ROUTE-0285` at `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:88`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-0286` at `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:144`; it is not runtime-observed.
- **information presented** is applicable only to `sla` / `ROUTE-0287` at `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:74`; it is not runtime-observed.
- **information presented** is applicable only to `summary` / `ROUTE-0288` at `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:116`; it is not runtime-observed.
- **information presented** is applicable only to `workload` / `ROUTE-0289` at `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:102`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/control-room/reports.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD control-room/reports` — `control-room.reports.index` — `App\Http\Controllers\ControlRoom\ControlRoomReportController@index` — `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:23` — middleware `web, auth, permission:controlRoom.reports.view|controlRoom.viewAny`
- `GET|HEAD control-room/reports/alerts` — `control-room.reports.alerts` — `App\Http\Controllers\ControlRoom\ControlRoomReportController@alerts` — `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:88` — middleware `web, auth, permission:controlRoom.reports.view|controlRoom.viewAny`
- `GET|HEAD control-room/reports/export` — `control-room.reports.export` — `App\Http\Controllers\ControlRoom\ControlRoomReportController@export` — `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:144` — middleware `web, auth, permission:controlRoom.reports.view|controlRoom.viewAny`
- `GET|HEAD control-room/reports/sla` — `control-room.reports.sla` — `App\Http\Controllers\ControlRoom\ControlRoomReportController@sla` — `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:74` — middleware `web, auth, permission:controlRoom.reports.view|controlRoom.viewAny`
- `GET|HEAD control-room/reports/summary` — `control-room.reports.summary` — `App\Http\Controllers\ControlRoom\ControlRoomReportController@summary` — `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:116` — middleware `web, auth, permission:controlRoom.reports.view|controlRoom.viewAny`
- `GET|HEAD control-room/reports/workload` — `control-room.reports.workload` — `App\Http\Controllers\ControlRoom\ControlRoomReportController@workload` — `app/Http/Controllers/ControlRoom/ControlRoomReportController.php:102` — middleware `web, auth, permission:controlRoom.reports.view|controlRoom.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/ControlRoomReportController.php`.
- Exact render/action page relationships: `resources/js/pages/control-room/reports.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
