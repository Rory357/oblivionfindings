# RESP-RESPITE-EVIDENCE-PACK: Respite Evidence Pack

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.evidence.view`, `permission:respite.evidence.manage`, `permission:respite.evidence.seal`
- Owning module: Respite
- Legacy family: `RESP-RESPITE-EVIDENCE-PACK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `respite/evidence-packs` (`respite.evidence-packs.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:respite.evidence.view`, `permission:respite.evidence.manage`, `permission:respite.evidence.seal`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.evidence.view`, `permission:respite.evidence.manage`, `permission:respite.evidence.seal`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/evidence-packs` (`respite.evidence-packs.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD respite/evidence-packs/{evidencePack}` (`respite.evidence-packs.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteEvidencePackController.php:89-106`.
3. Use `GET|HEAD respite/evidence-packs/{evidencePack}/export` (`respite.evidence-packs.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Respite/RespiteEvidencePackController.php:286-314`.
4. Use `GET|HEAD respite/evidence-packs/create` (`respite.evidence-packs.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteEvidencePackController.php:38-50`.
5. Use `GET|HEAD respite/stays/{stay}/evidence-pack` (`respite.stays.evidence-pack`, action `forStay`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteEvidencePackController.php:274-284`.
6. Invoke only the owning control for `POST respite/evidence-packs` (`respite.evidence-packs.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteEvidencePackController.php:52-87`; `stay_id`, `summary`.
7. Invoke only the owning control for `PUT respite/evidence-packs/{evidencePack}` (`respite.evidence-packs.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Respite/RespiteEvidencePackController.php:108-135`; `summary`.
8. Invoke only the owning control for `POST respite/evidence-packs/{evidencePack}/add-item` (`respite.evidence-packs.add-item`, action `addItem`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteEvidencePackController.php:137-179`; `type`, `title`, `description`, `file_path`, `metadata`.
9. Invoke only the owning control for `POST respite/evidence-packs/{evidencePack}/remove-item` (`respite.evidence-packs.remove-item`, action `removeItem`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Respite/RespiteEvidencePackController.php:181-221`; `item_id`, `reason`.
10. Invoke only the owning control for `POST respite/evidence-packs/{evidencePack}/seal` (`respite.evidence-packs.seal`, action `seal`). Source category: **mutation outcome source gap (seal)**; controller `app/Http/Controllers/Respite/RespiteEvidencePackController.php:223-272`; `seal_reason`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2385` at `app/Http/Controllers/Respite/RespiteEvidencePackController.php:21`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2386` at `app/Http/Controllers/Respite/RespiteEvidencePackController.php:52`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2387` at `app/Http/Controllers/Respite/RespiteEvidencePackController.php:89`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2388` at `app/Http/Controllers/Respite/RespiteEvidencePackController.php:108`; it is not runtime-observed.
- **created/recorded** is applicable only to `addItem` / `ROUTE-2389` at `app/Http/Controllers/Respite/RespiteEvidencePackController.php:137`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-2390` at `app/Http/Controllers/Respite/RespiteEvidencePackController.php:286`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `removeItem` / `ROUTE-2391` at `app/Http/Controllers/Respite/RespiteEvidencePackController.php:181`; it is not runtime-observed.
- **mutation outcome source gap (seal)** is applicable only to `seal` / `ROUTE-2392` at `app/Http/Controllers/Respite/RespiteEvidencePackController.php:223`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2393` at `app/Http/Controllers/Respite/RespiteEvidencePackController.php:38`; it is not runtime-observed.
- **information presented** is applicable only to `forStay` / `ROUTE-2451` at `app/Http/Controllers/Respite/RespiteEvidencePackController.php:274`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/respite/evidence-packs/create.tsx`, `resources/js/pages/respite/evidence-packs/for-stay.tsx`, `resources/js/pages/respite/evidence-packs/index.tsx`, `resources/js/pages/respite/evidence-packs/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2386` / `store`: fields `stay_id`, `summary`; success app/Http/Controllers/Respite/RespiteEvidencePackController.php:86 `->with('success', 'Evidence pack created.');`.
- `ROUTE-2388` / `update`: fields `summary`; success app/Http/Controllers/Respite/RespiteEvidencePackController.php:134 `return back()->with('success', 'Evidence pack updated.');`.
- `ROUTE-2389` / `addItem`: fields `type`, `title`, `description`, `file_path`, `metadata`; success app/Http/Controllers/Respite/RespiteEvidencePackController.php:178 `return back()->with('success', 'Item added to evidence pack.');`.
- `ROUTE-2391` / `removeItem`: fields `item_id`, `reason`; success app/Http/Controllers/Respite/RespiteEvidencePackController.php:220 `return back()->with('success', 'Item removed from evidence pack.');`.
- `ROUTE-2392` / `seal`: fields `seal_reason`; success app/Http/Controllers/Respite/RespiteEvidencePackController.php:271 `return back()->with('success', 'Evidence pack sealed.');`; failure app/Http/Controllers/Respite/RespiteEvidencePackController.php:242 `throw ValidationException::withMessages([`.

## Failure and recovery paths

- `seal`: app/Http/Controllers/Respite/RespiteEvidencePackController.php:242 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Respite/RespiteEvidencePackController.php:67 `$pack = RespiteEvidencePack::create($validated);`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:122 `$evidencePack->update($validated);`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:163 `$evidencePack->update([`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:205 `$evidencePack->update([`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:237 `$evidencePack->update([`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:247 `$evidencePack->update([`; responses app/Http/Controllers/Respite/RespiteEvidencePackController.php:32 `return Inertia::render('respite/evidence-packs/index', [`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:84 `return redirect()`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:103 `return Inertia::render('respite/evidence-packs/show', [`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:111 `return back()->with('error', 'Cannot modify a sealed evidence pack.');`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:134 `return back()->with('success', 'Evidence pack updated.');`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:140 `return back()->with('error', 'Cannot add items to a sealed evidence pack.');`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:178 `return back()->with('success', 'Item added to evidence pack.');`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:309 `return response()->streamDownload(function () use ($payload) {`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:184 `return back()->with('error', 'Cannot remove items from a sealed evidence pack.');`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:199 `return false;`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:202 `return true;`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:220 `return back()->with('success', 'Item removed from evidence pack.');`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:226 `return back()->with('error', 'Evidence pack is already sealed.');`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:271 `return back()->with('success', 'Evidence pack sealed.');`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:46 `return Inertia::render('respite/evidence-packs/create', [`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:280 `return Inertia::render('respite/evidence-packs/for-stay', [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteEvidencePackController.php:79 `event(new RespiteEvent('respite.evidence_pack.created', [`; app/Http/Controllers/Respite/RespiteEvidencePackController.php:265 `event(new RespiteEvent('respite.evidence_pack.sealed', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD respite/evidence-packs` — `respite.evidence-packs.index` — `App\Http\Controllers\Respite\RespiteEvidencePackController@index` — `app/Http/Controllers/Respite/RespiteEvidencePackController.php:21` — middleware `web, auth, permission:respite.evidence.view`
- `POST respite/evidence-packs` — `respite.evidence-packs.store` — `App\Http\Controllers\Respite\RespiteEvidencePackController@store` — `app/Http/Controllers/Respite/RespiteEvidencePackController.php:52` — middleware `web, auth, permission:respite.evidence.manage`
- `GET|HEAD respite/evidence-packs/{evidencePack}` — `respite.evidence-packs.show` — `App\Http\Controllers\Respite\RespiteEvidencePackController@show` — `app/Http/Controllers/Respite/RespiteEvidencePackController.php:89` — middleware `web, auth, permission:respite.evidence.view`
- `PUT respite/evidence-packs/{evidencePack}` — `respite.evidence-packs.update` — `App\Http\Controllers\Respite\RespiteEvidencePackController@update` — `app/Http/Controllers/Respite/RespiteEvidencePackController.php:108` — middleware `web, auth, permission:respite.evidence.manage`
- `POST respite/evidence-packs/{evidencePack}/add-item` — `respite.evidence-packs.add-item` — `App\Http\Controllers\Respite\RespiteEvidencePackController@addItem` — `app/Http/Controllers/Respite/RespiteEvidencePackController.php:137` — middleware `web, auth, permission:respite.evidence.manage`
- `GET|HEAD respite/evidence-packs/{evidencePack}/export` — `respite.evidence-packs.export` — `App\Http\Controllers\Respite\RespiteEvidencePackController@export` — `app/Http/Controllers/Respite/RespiteEvidencePackController.php:286` — middleware `web, auth, permission:respite.evidence.view`
- `POST respite/evidence-packs/{evidencePack}/remove-item` — `respite.evidence-packs.remove-item` — `App\Http\Controllers\Respite\RespiteEvidencePackController@removeItem` — `app/Http/Controllers/Respite/RespiteEvidencePackController.php:181` — middleware `web, auth, permission:respite.evidence.manage`
- `POST respite/evidence-packs/{evidencePack}/seal` — `respite.evidence-packs.seal` — `App\Http\Controllers\Respite\RespiteEvidencePackController@seal` — `app/Http/Controllers/Respite/RespiteEvidencePackController.php:223` — middleware `web, auth, permission:respite.evidence.seal`
- `GET|HEAD respite/evidence-packs/create` — `respite.evidence-packs.create` — `App\Http\Controllers\Respite\RespiteEvidencePackController@create` — `app/Http/Controllers/Respite/RespiteEvidencePackController.php:38` — middleware `web, auth, permission:respite.evidence.view`
- `GET|HEAD respite/stays/{stay}/evidence-pack` — `respite.stays.evidence-pack` — `App\Http\Controllers\Respite\RespiteEvidencePackController@forStay` — `app/Http/Controllers/Respite/RespiteEvidencePackController.php:274` — middleware `web, auth, permission:respite.evidence.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteEvidencePackController.php`.
- Exact render/action page relationships: `resources/js/pages/respite/evidence-packs/create.tsx`, `resources/js/pages/respite/evidence-packs/for-stay.tsx`, `resources/js/pages/respite/evidence-packs/index.tsx`, `resources/js/pages/respite/evidence-packs/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
