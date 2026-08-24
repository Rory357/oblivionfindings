# CLI-CLIENT-ASSESSMENT: Client Assessment

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.update`
- Owning module: Clients and supported people
- Legacy family: `CLI-CLIENT-ASSESSMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST clients/{client}/assessments` (`clients.assessments.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientAssessmentController.php:12-35`; `type`.
3. Invoke only the owning control for `DELETE clients/{client}/assessments/{assessment}` (`clients.assessments.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientAssessmentController.php:60-73`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT clients/{client}/assessments/{assessment}` (`clients.assessments.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientAssessmentController.php:37-58`; `type`.
5. Invoke only the owning control for `POST operations/clients/{client}/assessments` (`operations.clients.assessments.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientAssessmentController.php:12-35`; `type`.
6. Invoke only the owning control for `DELETE operations/clients/{client}/assessments/{assessment}` (`operations.clients.assessments.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientAssessmentController.php:60-73`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT operations/clients/{client}/assessments/{assessment}` (`operations.clients.assessments.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientAssessmentController.php:37-58`; `type`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-0127` at `app/Http/Controllers/ClientAssessmentController.php:12`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0128` at `app/Http/Controllers/ClientAssessmentController.php:60`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0129` at `app/Http/Controllers/ClientAssessmentController.php:37`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1939` at `app/Http/Controllers/ClientAssessmentController.php:12`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1940` at `app/Http/Controllers/ClientAssessmentController.php:60`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1941` at `app/Http/Controllers/ClientAssessmentController.php:37`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0127` / `store`: fields `type`; success app/Http/Controllers/ClientAssessmentController.php:34 `return back()->with('success', 'Assessment added.');`.
- `ROUTE-0128` / `destroy`: success app/Http/Controllers/ClientAssessmentController.php:72 `return back()->with('success', 'Assessment removed.');`.
- `ROUTE-0129` / `update`: fields `type`; success app/Http/Controllers/ClientAssessmentController.php:57 `return back()->with('success', 'Assessment updated.');`.
- `ROUTE-1939` / `store`: fields `type`; success app/Http/Controllers/ClientAssessmentController.php:34 `return back()->with('success', 'Assessment added.');`.
- `ROUTE-1940` / `destroy`: success app/Http/Controllers/ClientAssessmentController.php:72 `return back()->with('success', 'Assessment removed.');`.
- `ROUTE-1941` / `update`: fields `type`; success app/Http/Controllers/ClientAssessmentController.php:57 `return back()->with('success', 'Assessment updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientAssessmentController.php:24 `$assessment = ClientAssessment::create(array_merge($data, [`; app/Http/Controllers/ClientAssessmentController.php:65 `$assessment->delete();`; app/Http/Controllers/ClientAssessmentController.php:50 `$assessment->update($data);`; responses app/Http/Controllers/ClientAssessmentController.php:34 `return back()->with('success', 'Assessment added.');`; app/Http/Controllers/ClientAssessmentController.php:72 `return back()->with('success', 'Assessment removed.');`; app/Http/Controllers/ClientAssessmentController.php:57 `return back()->with('success', 'Assessment updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST clients/{client}/assessments` — `clients.assessments.store` — `App\Http\Controllers\ClientAssessmentController@store` — `app/Http/Controllers/ClientAssessmentController.php:12` — middleware `web, auth, permission:clients.update`
- `DELETE clients/{client}/assessments/{assessment}` — `clients.assessments.destroy` — `App\Http\Controllers\ClientAssessmentController@destroy` — `app/Http/Controllers/ClientAssessmentController.php:60` — middleware `web, auth, permission:clients.update`
- `PUT clients/{client}/assessments/{assessment}` — `clients.assessments.update` — `App\Http\Controllers\ClientAssessmentController@update` — `app/Http/Controllers/ClientAssessmentController.php:37` — middleware `web, auth, permission:clients.update`
- `POST operations/clients/{client}/assessments` — `operations.clients.assessments.store` — `App\Http\Controllers\ClientAssessmentController@store` — `app/Http/Controllers/ClientAssessmentController.php:12` — middleware `web, auth, permission:clients.update`
- `DELETE operations/clients/{client}/assessments/{assessment}` — `operations.clients.assessments.destroy` — `App\Http\Controllers\ClientAssessmentController@destroy` — `app/Http/Controllers/ClientAssessmentController.php:60` — middleware `web, auth, permission:clients.update`
- `PUT operations/clients/{client}/assessments/{assessment}` — `operations.clients.assessments.update` — `App\Http\Controllers\ClientAssessmentController@update` — `app/Http/Controllers/ClientAssessmentController.php:37` — middleware `web, auth, permission:clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientAssessmentController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
