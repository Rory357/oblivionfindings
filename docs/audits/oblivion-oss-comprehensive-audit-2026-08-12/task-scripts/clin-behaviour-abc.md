# CLIN-BEHAVIOUR-ABC: Behaviour Abc

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Health and clinical
- Legacy family: `CLIN-BEHAVIOUR-ABC`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `clients/{client}/behaviour/abc` (`clients.behaviour.abc.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD clients/{client}/behaviour/abc` (`clients.behaviour.abc.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD clients/{client}/behaviour/abc/{abc}` (`clients.behaviour.abc.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Clinical/BehaviourAbcController.php:70-79`.
3. Invoke only the owning control for `POST clients/{client}/behaviour/abc` (`clients.behaviour.abc.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Clinical/BehaviourAbcController.php:46-65`; no exact validation fields extracted.
4. Invoke only the owning control for `DELETE clients/{client}/behaviour/abc/{abc}` (`clients.behaviour.abc.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Clinical/BehaviourAbcController.php:99-113`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT clients/{client}/behaviour/abc/{abc}` (`clients.behaviour.abc.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Clinical/BehaviourAbcController.php:81-97`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0132` at `app/Http/Controllers/Clinical/BehaviourAbcController.php:31`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0133` at `app/Http/Controllers/Clinical/BehaviourAbcController.php:46`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0134` at `app/Http/Controllers/Clinical/BehaviourAbcController.php:99`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0135` at `app/Http/Controllers/Clinical/BehaviourAbcController.php:70`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0136` at `app/Http/Controllers/Clinical/BehaviourAbcController.php:81`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0133` / `store`: success app/Http/Controllers/Clinical/BehaviourAbcController.php:64 `return back()->with('success', 'ABC entry saved.');`.
- `ROUTE-0134` / `destroy`: success app/Http/Controllers/Clinical/BehaviourAbcController.php:112 `return back()->with('success', 'ABC entry removed.');`.
- `ROUTE-0136` / `update`: success app/Http/Controllers/Clinical/BehaviourAbcController.php:96 `return back()->with('success', 'ABC entry updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Clinical/BehaviourAbcController.php:106 `$abc->delete();`; app/Http/Controllers/Clinical/BehaviourAbcController.php:90 `$entry = $this->service->update($abc, $request->user(), $validated);`; responses app/Http/Controllers/Clinical/BehaviourAbcController.php:43 `return response()->json($entries);`; app/Http/Controllers/Clinical/BehaviourAbcController.php:61 `return response()->json($this->transform($entry->fresh('recorder'), detail: true), 201);`; app/Http/Controllers/Clinical/BehaviourAbcController.php:64 `return back()->with('success', 'ABC entry saved.');`; app/Http/Controllers/Clinical/BehaviourAbcController.php:109 `return response()->json(['deleted' => true]);`; app/Http/Controllers/Clinical/BehaviourAbcController.php:112 `return back()->with('success', 'ABC entry removed.');`; app/Http/Controllers/Clinical/BehaviourAbcController.php:78 `return response()->json($this->transform($abc, detail: true));`; app/Http/Controllers/Clinical/BehaviourAbcController.php:93 `return response()->json($this->transform($entry->fresh('recorder'), detail: true));`; app/Http/Controllers/Clinical/BehaviourAbcController.php:96 `return back()->with('success', 'ABC entry updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD clients/{client}/behaviour/abc` — `clients.behaviour.abc.index` — `App\Http\Controllers\Clinical\BehaviourAbcController@index` — `app/Http/Controllers/Clinical/BehaviourAbcController.php:31` — middleware `web, auth`
- `POST clients/{client}/behaviour/abc` — `clients.behaviour.abc.store` — `App\Http\Controllers\Clinical\BehaviourAbcController@store` — `app/Http/Controllers/Clinical/BehaviourAbcController.php:46` — middleware `web, auth`
- `DELETE clients/{client}/behaviour/abc/{abc}` — `clients.behaviour.abc.destroy` — `App\Http\Controllers\Clinical\BehaviourAbcController@destroy` — `app/Http/Controllers/Clinical/BehaviourAbcController.php:99` — middleware `web, auth`
- `GET|HEAD clients/{client}/behaviour/abc/{abc}` — `clients.behaviour.abc.show` — `App\Http\Controllers\Clinical\BehaviourAbcController@show` — `app/Http/Controllers/Clinical/BehaviourAbcController.php:70` — middleware `web, auth`
- `PUT clients/{client}/behaviour/abc/{abc}` — `clients.behaviour.abc.update` — `App\Http\Controllers\Clinical\BehaviourAbcController@update` — `app/Http/Controllers/Clinical/BehaviourAbcController.php:81` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Clinical/BehaviourAbcController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
