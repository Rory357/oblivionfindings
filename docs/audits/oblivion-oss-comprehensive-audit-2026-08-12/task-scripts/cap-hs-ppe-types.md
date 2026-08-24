# CAP-HS-PPE-TYPES: PPE type administration

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-PPE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/ppe` (`health-safety.ppe.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/ppe` (`health-safety.ppe.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST health-safety/ppe/types` (`health-safety.ppe.types.store`, action `storeType`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/PpeController.php:558-563`; FormRequest `app/Http/Requests/HealthSafety/StorePpeTypeRequest.php:19`; `name`, `category`, `description`, `hazards_addressed`, `standards_reference`, `inspection_frequency`, `typical_lifespan_months`.
3. Invoke only the owning control for `PUT health-safety/ppe/types/{type}` (`health-safety.ppe.types.update`, action `updateType`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/PpeController.php:565-570`; FormRequest `app/Http/Requests/HealthSafety/StorePpeTypeRequest.php:19`; `name`, `category`, `description`, `hazards_addressed`, `standards_reference`, `inspection_frequency`, `typical_lifespan_months`.
4. Invoke only the owning control for `PATCH health-safety/ppe/types/{type}/activate` (`health-safety.ppe.types.activate`, action `activateType`). Source category: **mutation outcome source gap (activateType)**; controller `app/Http/Controllers/HealthSafety/PpeController.php:572-577`; no exact validation fields extracted.
5. Invoke only the owning control for `PATCH health-safety/ppe/types/{type}/deactivate` (`health-safety.ppe.types.deactivate`, action `deactivateType`). Source category: **mutation outcome source gap (deactivateType)**; controller `app/Http/Controllers/HealthSafety/PpeController.php:579-584`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeType` / `ROUTE-1173` at `app/Http/Controllers/HealthSafety/PpeController.php:558`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateType` / `ROUTE-1174` at `app/Http/Controllers/HealthSafety/PpeController.php:565`; it is not runtime-observed.
- **mutation outcome source gap (activateType)** is applicable only to `activateType` / `ROUTE-1175` at `app/Http/Controllers/HealthSafety/PpeController.php:572`; it is not runtime-observed.
- **mutation outcome source gap (deactivateType)** is applicable only to `deactivateType` / `ROUTE-1176` at `app/Http/Controllers/HealthSafety/PpeController.php:579`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1173` / `storeType`: FormRequest `app/Http/Requests/HealthSafety/StorePpeTypeRequest.php:19`; fields `name`, `category`, `description`, `hazards_addressed`, `standards_reference`, `inspection_frequency`, `typical_lifespan_months`; success app/Http/Controllers/HealthSafety/PpeController.php:562 `return redirect()->back()->with('success', 'PPE type created.');`.
- `ROUTE-1174` / `updateType`: FormRequest `app/Http/Requests/HealthSafety/StorePpeTypeRequest.php:19`; fields `name`, `category`, `description`, `hazards_addressed`, `standards_reference`, `inspection_frequency`, `typical_lifespan_months`; success app/Http/Controllers/HealthSafety/PpeController.php:569 `return redirect()->back()->with('success', 'PPE type updated.');`.
- `ROUTE-1175` / `activateType`: success app/Http/Controllers/HealthSafety/PpeController.php:576 `return redirect()->back()->with('success', 'PPE type reactivated.');`.
- `ROUTE-1176` / `deactivateType`: success app/Http/Controllers/HealthSafety/PpeController.php:583 `return redirect()->back()->with('success', 'PPE type retired.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/PpeController.php:560 `PpeType::create($request->validated());`; app/Http/Controllers/HealthSafety/PpeController.php:567 `$type->update($request->validated());`; app/Http/Controllers/HealthSafety/PpeController.php:574 `$type->update(['is_active' => true]);`; app/Http/Controllers/HealthSafety/PpeController.php:581 `$type->update(['is_active' => false]);`; responses app/Http/Controllers/HealthSafety/PpeController.php:562 `return redirect()->back()->with('success', 'PPE type created.');`; app/Http/Controllers/HealthSafety/PpeController.php:569 `return redirect()->back()->with('success', 'PPE type updated.');`; app/Http/Controllers/HealthSafety/PpeController.php:576 `return redirect()->back()->with('success', 'PPE type reactivated.');`; app/Http/Controllers/HealthSafety/PpeController.php:583 `return redirect()->back()->with('success', 'PPE type retired.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/ppe/types` — `health-safety.ppe.types.store` — `App\Http\Controllers\HealthSafety\PpeController@storeType` — `app/Http/Controllers/HealthSafety/PpeController.php:558` — middleware `web, auth, permission:hazards.manage`
- `PUT health-safety/ppe/types/{type}` — `health-safety.ppe.types.update` — `App\Http\Controllers\HealthSafety\PpeController@updateType` — `app/Http/Controllers/HealthSafety/PpeController.php:565` — middleware `web, auth, permission:hazards.manage`
- `PATCH health-safety/ppe/types/{type}/activate` — `health-safety.ppe.types.activate` — `App\Http\Controllers\HealthSafety\PpeController@activateType` — `app/Http/Controllers/HealthSafety/PpeController.php:572` — middleware `web, auth, permission:hazards.manage`
- `PATCH health-safety/ppe/types/{type}/deactivate` — `health-safety.ppe.types.deactivate` — `App\Http\Controllers\HealthSafety\PpeController@deactivateType` — `app/Http/Controllers/HealthSafety/PpeController.php:579` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/PpeController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
