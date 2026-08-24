# CLIN-CLIENT-SLEEP-CHART: Client Sleep Chart

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.administer.record|clients.update`
- Owning module: Health and clinical
- Legacy family: `CLIN-CLIENT-SLEEP-CHART`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/clients/{client}/health/sleep` (`operations.clients.health.sleep.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.administer.record|clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.view`, `permission:medications.administer.record|clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/clients/{client}/health/sleep` (`operations.clients.health.sleep.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/clients/{client}/health/sleep` (`operations.clients.health.sleep.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Clinical/ClientSleepChartController.php:26-41`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE operations/clients/{client}/health/sleep/{entry}` (`operations.clients.health.sleep.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Clinical/ClientSleepChartController.php:54-63`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT operations/clients/{client}/health/sleep/{entry}` (`operations.clients.health.sleep.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Clinical/ClientSleepChartController.php:43-52`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1991` at `app/Http/Controllers/Clinical/ClientSleepChartController.php:13`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1992` at `app/Http/Controllers/Clinical/ClientSleepChartController.php:26`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1993` at `app/Http/Controllers/Clinical/ClientSleepChartController.php:54`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1994` at `app/Http/Controllers/Clinical/ClientSleepChartController.php:43`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1992` / `store`: success app/Http/Controllers/Clinical/ClientSleepChartController.php:40 `return back()->with('success', 'Sleep chart entry added.');`.
- `ROUTE-1993` / `destroy`: success app/Http/Controllers/Clinical/ClientSleepChartController.php:62 `return back()->with('success', 'Sleep chart entry removed.');`.
- `ROUTE-1994` / `update`: success app/Http/Controllers/Clinical/ClientSleepChartController.php:51 `return back()->with('success', 'Sleep chart entry updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Clinical/ClientSleepChartController.php:33 `ClientSleepEntry::query()->create([`; app/Http/Controllers/Clinical/ClientSleepChartController.php:60 `$entry->delete();`; app/Http/Controllers/Clinical/ClientSleepChartController.php:49 `$entry->update($this->validatedPayload($request, creating: false));`; responses app/Http/Controllers/Clinical/ClientSleepChartController.php:18 `return ClientSleepEntry::query()`; app/Http/Controllers/Clinical/ClientSleepChartController.php:40 `return back()->with('success', 'Sleep chart entry added.');`; app/Http/Controllers/Clinical/ClientSleepChartController.php:62 `return back()->with('success', 'Sleep chart entry removed.');`; app/Http/Controllers/Clinical/ClientSleepChartController.php:51 `return back()->with('success', 'Sleep chart entry updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/clients/{client}/health/sleep` — `operations.clients.health.sleep.index` — `App\Http\Controllers\Clinical\ClientSleepChartController@index` — `app/Http/Controllers/Clinical/ClientSleepChartController.php:13` — middleware `web, auth, permission:medications.view`
- `POST operations/clients/{client}/health/sleep` — `operations.clients.health.sleep.store` — `App\Http\Controllers\Clinical\ClientSleepChartController@store` — `app/Http/Controllers/Clinical/ClientSleepChartController.php:26` — middleware `web, auth, permission:medications.administer.record|clients.update`
- `DELETE operations/clients/{client}/health/sleep/{entry}` — `operations.clients.health.sleep.destroy` — `App\Http\Controllers\Clinical\ClientSleepChartController@destroy` — `app/Http/Controllers/Clinical/ClientSleepChartController.php:54` — middleware `web, auth, permission:medications.administer.record|clients.update`
- `PUT operations/clients/{client}/health/sleep/{entry}` — `operations.clients.health.sleep.update` — `App\Http\Controllers\Clinical\ClientSleepChartController@update` — `app/Http/Controllers/Clinical/ClientSleepChartController.php:43` — middleware `web, auth, permission:medications.administer.record|clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Clinical/ClientSleepChartController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
