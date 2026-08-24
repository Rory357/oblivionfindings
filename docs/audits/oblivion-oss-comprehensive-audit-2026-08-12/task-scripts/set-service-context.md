# SET-SERVICE-CONTEXT: Service Context

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:settings.service_contexts.manage`
- Owning module: Settings and system access
- Legacy family: `SET-SERVICE-CONTEXT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/service-contexts` (`settings.service_contexts`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:settings.service_contexts.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:settings.service_contexts.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/service-contexts` (`settings.service_contexts`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST settings/service-contexts` (`settings.service_contexts.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Settings/ServiceContextController.php:52-68`; `type`.
3. Invoke only the owning control for `PUT settings/service-contexts/{serviceContext}` (`settings.service_contexts.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/ServiceContextController.php:97-113`; `type`.
4. Invoke only the owning control for `POST settings/service-contexts/default` (`settings.service_contexts.default`, action `setDefault`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/ServiceContextController.php:71-94`; `default_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2683` at `app/Http/Controllers/Settings/ServiceContextController.php:14`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2684` at `app/Http/Controllers/Settings/ServiceContextController.php:52`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2685` at `app/Http/Controllers/Settings/ServiceContextController.php:97`; it is not runtime-observed.
- **updated/revised** is applicable only to `setDefault` / `ROUTE-2686` at `app/Http/Controllers/Settings/ServiceContextController.php:71`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/service-contexts.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2684` / `store`: fields `type`; success app/Http/Controllers/Settings/ServiceContextController.php:67 `return back()->with('success', 'Service context created.');`.
- `ROUTE-2685` / `update`: fields `type`; success app/Http/Controllers/Settings/ServiceContextController.php:112 `return back()->with('success', 'Service context updated.');`.
- `ROUTE-2686` / `setDefault`: fields `default_id`; success app/Http/Controllers/Settings/ServiceContextController.php:93 `return back()->with('success', 'Default service context updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/ServiceContextController.php:65 `ServiceContext::create($data);`; app/Http/Controllers/Settings/ServiceContextController.php:110 `$serviceContext->update($data);`; app/Http/Controllers/Settings/ServiceContextController.php:88 `AppSetting::updateOrCreate(['key' => 'service_context.default_id'], ['value' => (int) $id]);`; app/Http/Controllers/Settings/ServiceContextController.php:90 `AppSetting::query()->where('key', 'service_context.default_id')->delete();`; responses app/Http/Controllers/Settings/ServiceContextController.php:35 `return inertia('settings/service-contexts', [`; app/Http/Controllers/Settings/ServiceContextController.php:67 `return back()->with('success', 'Service context created.');`; app/Http/Controllers/Settings/ServiceContextController.php:112 `return back()->with('success', 'Service context updated.');`; app/Http/Controllers/Settings/ServiceContextController.php:86 `return back()->with('error', 'Default service context must be active.');`; app/Http/Controllers/Settings/ServiceContextController.php:93 `return back()->with('success', 'Default service context updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/service-contexts` — `settings.service_contexts` — `App\Http\Controllers\Settings\ServiceContextController@index` — `app/Http/Controllers/Settings/ServiceContextController.php:14` — middleware `web, auth, permission:settings.service_contexts.manage`
- `POST settings/service-contexts` — `settings.service_contexts.store` — `App\Http\Controllers\Settings\ServiceContextController@store` — `app/Http/Controllers/Settings/ServiceContextController.php:52` — middleware `web, auth, permission:settings.service_contexts.manage`
- `PUT settings/service-contexts/{serviceContext}` — `settings.service_contexts.update` — `App\Http\Controllers\Settings\ServiceContextController@update` — `app/Http/Controllers/Settings/ServiceContextController.php:97` — middleware `web, auth, permission:settings.service_contexts.manage`
- `POST settings/service-contexts/default` — `settings.service_contexts.default` — `App\Http\Controllers\Settings\ServiceContextController@setDefault` — `app/Http/Controllers/Settings/ServiceContextController.php:71` — middleware `web, auth, permission:settings.service_contexts.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/ServiceContextController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/service-contexts.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
