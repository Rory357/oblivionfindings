# HR-CUSTOM-FIELD: Custom Field

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.settings.manage`
- Owning module: Human resources
- Legacy family: `HR-CUSTOM-FIELD`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/settings/custom-fields` (`hr.settings.custom-fields`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.settings.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.settings.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/settings/custom-fields` (`hr.settings.custom-fields`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/settings/custom-fields` (`hr.settings.custom-fields.store`, action `storeDefinition`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CustomFieldController.php:41-79`; `name`.
3. Invoke only the owning control for `DELETE hr/settings/custom-fields/{definition}` (`hr.settings.custom-fields.destroy`, action `destroyDefinition`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/CustomFieldController.php:109-119`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT hr/settings/custom-fields/{definition}` (`hr.settings.custom-fields.update`, action `updateDefinition`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/CustomFieldController.php:84-104`; `name`.

## Source-applicable states and transitions

- **information presented** is applicable only to `definitions` / `ROUTE-1741` at `app/Http/Controllers/Hr/CustomFieldController.php:22`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeDefinition` / `ROUTE-1742` at `app/Http/Controllers/Hr/CustomFieldController.php:41`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyDefinition` / `ROUTE-1743` at `app/Http/Controllers/Hr/CustomFieldController.php:109`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateDefinition` / `ROUTE-1744` at `app/Http/Controllers/Hr/CustomFieldController.php:84`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/settings/custom-fields.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1742` / `storeDefinition`: fields `name`; success app/Http/Controllers/Hr/CustomFieldController.php:78 `->with('success', 'Custom field created successfully.');`.
- `ROUTE-1743` / `destroyDefinition`: success app/Http/Controllers/Hr/CustomFieldController.php:118 `->with('success', 'Custom field deleted successfully.');`.
- `ROUTE-1744` / `updateDefinition`: fields `name`; success app/Http/Controllers/Hr/CustomFieldController.php:103 `->with('success', 'Custom field updated successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/CustomFieldController.php:65 `HrCustomFieldDefinition::create([`; app/Http/Controllers/Hr/CustomFieldController.php:115 `$definition->delete(); // cascadeOnDelete handles values`; app/Http/Controllers/Hr/CustomFieldController.php:100 `$definition->update($validated);`; responses app/Http/Controllers/Hr/CustomFieldController.php:32 `return Inertia::render('hr/settings/custom-fields', [`; app/Http/Controllers/Hr/CustomFieldController.php:77 `return redirect()->route('hr.settings.custom-fields')`; app/Http/Controllers/Hr/CustomFieldController.php:117 `return redirect()->route('hr.settings.custom-fields')`; app/Http/Controllers/Hr/CustomFieldController.php:102 `return redirect()->route('hr.settings.custom-fields')`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/settings/custom-fields` — `hr.settings.custom-fields` — `App\Http\Controllers\Hr\CustomFieldController@definitions` — `app/Http/Controllers/Hr/CustomFieldController.php:22` — middleware `web, auth, permission:hr.settings.manage`
- `POST hr/settings/custom-fields` — `hr.settings.custom-fields.store` — `App\Http\Controllers\Hr\CustomFieldController@storeDefinition` — `app/Http/Controllers/Hr/CustomFieldController.php:41` — middleware `web, auth, permission:hr.settings.manage`
- `DELETE hr/settings/custom-fields/{definition}` — `hr.settings.custom-fields.destroy` — `App\Http\Controllers\Hr\CustomFieldController@destroyDefinition` — `app/Http/Controllers/Hr/CustomFieldController.php:109` — middleware `web, auth, permission:hr.settings.manage`
- `PUT hr/settings/custom-fields/{definition}` — `hr.settings.custom-fields.update` — `App\Http\Controllers\Hr\CustomFieldController@updateDefinition` — `app/Http/Controllers/Hr/CustomFieldController.php:84` — middleware `web, auth, permission:hr.settings.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/CustomFieldController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/settings/custom-fields.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
