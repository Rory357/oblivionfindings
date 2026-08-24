# SITE-DIETARY-TAG: Dietary Tag

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:catering.tags.view`, `permission:catering.tags.manage`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-DIETARY-TAG`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `catering/tags` (`catering.tags.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:catering.tags.view`, `permission:catering.tags.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:catering.tags.view`, `permission:catering.tags.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD catering/tags` (`catering.tags.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST catering/tags` (`catering.tags.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Catering/DietaryTagController.php:28-59`; `key`, `label`, `kind`, `severity`, `color`, `description`.
3. Invoke only the owning control for `DELETE catering/tags/{tag}` (`catering.tags.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Catering/DietaryTagController.php:82-92`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT catering/tags/{tag}` (`catering.tags.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Catering/DietaryTagController.php:61-80`; `label`, `kind`, `severity`, `color`, `description`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0110` at `app/Http/Controllers/Catering/DietaryTagController.php:12`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0111` at `app/Http/Controllers/Catering/DietaryTagController.php:28`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0112` at `app/Http/Controllers/Catering/DietaryTagController.php:82`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0113` at `app/Http/Controllers/Catering/DietaryTagController.php:61`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0111` / `store`: fields `key`, `label`, `kind`, `severity`, `color`, `description`.
- `ROUTE-0113` / `update`: fields `label`, `kind`, `severity`, `color`, `description`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Catering/DietaryTagController.php:44 `$tag = MealDietaryTag::create([`; app/Http/Controllers/Catering/DietaryTagController.php:85 `$tag->delete();`; app/Http/Controllers/Catering/DietaryTagController.php:73 `$tag->update($data);`; responses app/Http/Controllers/Catering/DietaryTagController.php:16 `return redirect()->route('catering.meal-planner');`; app/Http/Controllers/Catering/DietaryTagController.php:25 `return response()->json(['tags' => $tags]);`; app/Http/Controllers/Catering/DietaryTagController.php:55 `return response()->json($tag);`; app/Http/Controllers/Catering/DietaryTagController.php:58 `return back()->with('status', 'Tag created');`; app/Http/Controllers/Catering/DietaryTagController.php:88 `return response()->json(['deleted' => true]);`; app/Http/Controllers/Catering/DietaryTagController.php:91 `return back()->with('status', 'Tag deleted');`; app/Http/Controllers/Catering/DietaryTagController.php:76 `return response()->json($tag->fresh());`; app/Http/Controllers/Catering/DietaryTagController.php:79 `return back()->with('status', 'Tag updated');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD catering/tags` — `catering.tags.index` — `App\Http\Controllers\Catering\DietaryTagController@index` — `app/Http/Controllers/Catering/DietaryTagController.php:12` — middleware `web, auth, verified, permission:catering.tags.view`
- `POST catering/tags` — `catering.tags.store` — `App\Http\Controllers\Catering\DietaryTagController@store` — `app/Http/Controllers/Catering/DietaryTagController.php:28` — middleware `web, auth, verified, permission:catering.tags.view, permission:catering.tags.manage`
- `DELETE catering/tags/{tag}` — `catering.tags.destroy` — `App\Http\Controllers\Catering\DietaryTagController@destroy` — `app/Http/Controllers/Catering/DietaryTagController.php:82` — middleware `web, auth, verified, permission:catering.tags.view, permission:catering.tags.manage`
- `PUT catering/tags/{tag}` — `catering.tags.update` — `App\Http\Controllers\Catering\DietaryTagController@update` — `app/Http/Controllers/Catering/DietaryTagController.php:61` — middleware `web, auth, verified, permission:catering.tags.view, permission:catering.tags.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Catering/DietaryTagController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
