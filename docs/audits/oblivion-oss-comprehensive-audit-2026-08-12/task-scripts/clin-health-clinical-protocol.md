# CLIN-HEALTH-CLINICAL-PROTOCOL: Health Clinical Protocol

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clinical.protocols.viewAny|clinical.protocols.manage`, `permission:clinical.protocols.manage`
- Owning module: Health and clinical
- Legacy family: `CLIN-HEALTH-CLINICAL-PROTOCOL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-clinical/protocols` (`health-clinical.protocols.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clinical.protocols.viewAny|clinical.protocols.manage`, `permission:clinical.protocols.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:clinical.protocols.viewAny|clinical.protocols.manage`, `permission:clinical.protocols.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-clinical/protocols` (`health-clinical.protocols.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD health-clinical/protocols/{protocol}/edit` (`health-clinical.protocols.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:79-100`.
3. Use `GET|HEAD health-clinical/protocols/create` (`health-clinical.protocols.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:50-57`.
4. Invoke only the owning control for `POST health-clinical/protocols` (`health-clinical.protocols.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:59-77`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT health-clinical/protocols/{protocol}` (`health-clinical.protocols.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:102-112`; no exact validation fields extracted.
6. Invoke only the owning control for `PATCH health-clinical/protocols/{protocol}/toggle-active` (`health-clinical.protocols.toggle-active`, action `toggleActive`). Source category: **updated/revised**; controller `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:114-129`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1071` at `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:22`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1072` at `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:59`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1073` at `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:102`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-1074` at `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:79`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggleActive` / `ROUTE-1075` at `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:114`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1076` at `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:50`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/health-clinical/protocols/Create.tsx`, `resources/js/pages/health-clinical/protocols/Edit.tsx`, `resources/js/pages/health-clinical/Protocols.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1071` / `index`: fields `client_id`.
- `ROUTE-1072` / `store`: success app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:76 `->with('success', "Protocol {$protocol->name} created.");`.
- `ROUTE-1073` / `update`: success app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:111 `->with('success', "Protocol {$protocol->name} updated.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:69 `$protocol = ClinicalProtocol::create([`; app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:107 `$protocol->update($this->validatedProtocolData($request, $protocol));`; app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:119 `$protocol->update([`; responses app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:39 `return inertia('health-clinical/Protocols', [`; app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:74 `return redirect()`; app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:109 `return redirect()`; app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:95 `return inertia('health-clinical/protocols/Edit', [`; app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:123 `return back()->with(`; app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:54 `return inertia('health-clinical/protocols/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD health-clinical/protocols` — `health-clinical.protocols.index` — `App\Http\Controllers\Clinical\HealthClinicalProtocolController@index` — `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:22` — middleware `web, auth, permission:clinical.protocols.viewAny|clinical.protocols.manage`
- `POST health-clinical/protocols` — `health-clinical.protocols.store` — `App\Http\Controllers\Clinical\HealthClinicalProtocolController@store` — `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:59` — middleware `web, auth, permission:clinical.protocols.manage`
- `PUT health-clinical/protocols/{protocol}` — `health-clinical.protocols.update` — `App\Http\Controllers\Clinical\HealthClinicalProtocolController@update` — `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:102` — middleware `web, auth, permission:clinical.protocols.manage`
- `GET|HEAD health-clinical/protocols/{protocol}/edit` — `health-clinical.protocols.edit` — `App\Http\Controllers\Clinical\HealthClinicalProtocolController@edit` — `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:79` — middleware `web, auth, permission:clinical.protocols.manage`
- `PATCH health-clinical/protocols/{protocol}/toggle-active` — `health-clinical.protocols.toggle-active` — `App\Http\Controllers\Clinical\HealthClinicalProtocolController@toggleActive` — `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:114` — middleware `web, auth, permission:clinical.protocols.manage`
- `GET|HEAD health-clinical/protocols/create` — `health-clinical.protocols.create` — `App\Http\Controllers\Clinical\HealthClinicalProtocolController@create` — `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:50` — middleware `web, auth, permission:clinical.protocols.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php`.
- Exact render/action page relationships: `resources/js/pages/health-clinical/protocols/Create.tsx`, `resources/js/pages/health-clinical/protocols/Edit.tsx`, `resources/js/pages/health-clinical/Protocols.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
