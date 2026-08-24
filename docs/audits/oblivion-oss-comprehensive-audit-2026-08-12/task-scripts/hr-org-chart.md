# HR-ORG-CHART: Org Chart

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.employees.viewAny`, `permission:hr.employees.manage`
- Owning module: Human resources
- Legacy family: `HR-ORG-CHART`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/orgchart` (`hr.orgchart.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.employees.viewAny`, `permission:hr.employees.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.employees.viewAny`, `permission:hr.employees.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/orgchart` (`hr.orgchart.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT hr/orgchart/{profile}` (`hr.orgchart.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/OrgChartController.php:30-47`; `manager_user_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1583` at `app/Http/Controllers/Hr/OrgChartController.php:20`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1584` at `app/Http/Controllers/Hr/OrgChartController.php:30`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1584` / `update`: fields `manager_user_id`; success app/Http/Controllers/Hr/OrgChartController.php:46 `return redirect()->back()->with('success', 'Reporting structure updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/OrgChartController.php:27 `return redirect()->route('hr.people.index', ['tab' => 'orgchart']);`; app/Http/Controllers/Hr/OrgChartController.php:41 `return redirect()->back()->with('error', 'That change would create a reporting loop.');`; app/Http/Controllers/Hr/OrgChartController.php:46 `return redirect()->back()->with('success', 'Reporting structure updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/orgchart` — `hr.orgchart.index` — `App\Http\Controllers\Hr\OrgChartController@index` — `app/Http/Controllers/Hr/OrgChartController.php:20` — middleware `web, auth, permission:hr.employees.viewAny`
- `PUT hr/orgchart/{profile}` — `hr.orgchart.update` — `App\Http\Controllers\Hr\OrgChartController@update` — `app/Http/Controllers/Hr/OrgChartController.php:30` — middleware `web, auth, permission:hr.employees.viewAny, permission:hr.employees.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/OrgChartController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
