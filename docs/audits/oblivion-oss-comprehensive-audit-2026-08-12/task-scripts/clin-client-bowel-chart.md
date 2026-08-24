# CLIN-CLIENT-BOWEL-CHART: Client Bowel Chart

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.administer.record|clients.update`
- Owning module: Health and clinical
- Legacy family: `CLIN-CLIENT-BOWEL-CHART`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/clients/{client}/health/bowel` (`operations.clients.health.bowel.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.administer.record|clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.view`, `permission:medications.administer.record|clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/clients/{client}/health/bowel` (`operations.clients.health.bowel.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/clients/{client}/health/bowel` (`operations.clients.health.bowel.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Clinical/ClientBowelChartController.php:26-42`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE operations/clients/{client}/health/bowel/{entry}` (`operations.clients.health.bowel.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Clinical/ClientBowelChartController.php:60-69`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT operations/clients/{client}/health/bowel/{entry}` (`operations.clients.health.bowel.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Clinical/ClientBowelChartController.php:44-58`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1979` at `app/Http/Controllers/Clinical/ClientBowelChartController.php:13`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1980` at `app/Http/Controllers/Clinical/ClientBowelChartController.php:26`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1981` at `app/Http/Controllers/Clinical/ClientBowelChartController.php:60`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1982` at `app/Http/Controllers/Clinical/ClientBowelChartController.php:44`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1980` / `store`: success app/Http/Controllers/Clinical/ClientBowelChartController.php:41 `return back()->with('success', 'Bowel chart entry added.');`.
- `ROUTE-1981` / `destroy`: success app/Http/Controllers/Clinical/ClientBowelChartController.php:68 `return back()->with('success', 'Bowel chart entry removed.');`.
- `ROUTE-1982` / `update`: success app/Http/Controllers/Clinical/ClientBowelChartController.php:57 `return back()->with('success', 'Bowel chart entry updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Clinical/ClientBowelChartController.php:33 `ClientBowelEntry::query()->create([`; app/Http/Controllers/Clinical/ClientBowelChartController.php:66 `$entry->delete();`; app/Http/Controllers/Clinical/ClientBowelChartController.php:55 `$entry->update($data);`; responses app/Http/Controllers/Clinical/ClientBowelChartController.php:18 `return ClientBowelEntry::query()`; app/Http/Controllers/Clinical/ClientBowelChartController.php:41 `return back()->with('success', 'Bowel chart entry added.');`; app/Http/Controllers/Clinical/ClientBowelChartController.php:68 `return back()->with('success', 'Bowel chart entry removed.');`; app/Http/Controllers/Clinical/ClientBowelChartController.php:57 `return back()->with('success', 'Bowel chart entry updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/clients/{client}/health/bowel` — `operations.clients.health.bowel.index` — `App\Http\Controllers\Clinical\ClientBowelChartController@index` — `app/Http/Controllers/Clinical/ClientBowelChartController.php:13` — middleware `web, auth, permission:medications.view`
- `POST operations/clients/{client}/health/bowel` — `operations.clients.health.bowel.store` — `App\Http\Controllers\Clinical\ClientBowelChartController@store` — `app/Http/Controllers/Clinical/ClientBowelChartController.php:26` — middleware `web, auth, permission:medications.administer.record|clients.update`
- `DELETE operations/clients/{client}/health/bowel/{entry}` — `operations.clients.health.bowel.destroy` — `App\Http\Controllers\Clinical\ClientBowelChartController@destroy` — `app/Http/Controllers/Clinical/ClientBowelChartController.php:60` — middleware `web, auth, permission:medications.administer.record|clients.update`
- `PUT operations/clients/{client}/health/bowel/{entry}` — `operations.clients.health.bowel.update` — `App\Http\Controllers\Clinical\ClientBowelChartController@update` — `app/Http/Controllers/Clinical/ClientBowelChartController.php:44` — middleware `web, auth, permission:medications.administer.record|clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Clinical/ClientBowelChartController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
