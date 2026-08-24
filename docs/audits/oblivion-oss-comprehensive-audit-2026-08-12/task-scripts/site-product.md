# SITE-PRODUCT: Product

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:catering.products.view`, `permission:catering.products.manage`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-PRODUCT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `catering/products` (`catering.products.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:catering.products.view`, `permission:catering.products.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:catering.products.view`, `permission:catering.products.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD catering/products` (`catering.products.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST catering/products` (`catering.products.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Catering/ProductController.php:28-47`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE catering/products/{product}` (`catering.products.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Catering/ProductController.php:69-79`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT catering/products/{product}` (`catering.products.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Catering/ProductController.php:49-67`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0099` at `app/Http/Controllers/Catering/ProductController.php:12`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0100` at `app/Http/Controllers/Catering/ProductController.php:28`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0101` at `app/Http/Controllers/Catering/ProductController.php:69`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0102` at `app/Http/Controllers/Catering/ProductController.php:49`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Catering/ProductController.php:37 `$product = MealProduct::create($data);`; app/Http/Controllers/Catering/ProductController.php:39 `$product->tags()->sync($tagIds);`; app/Http/Controllers/Catering/ProductController.php:72 `$product->delete();`; app/Http/Controllers/Catering/ProductController.php:57 `$product->update($data);`; app/Http/Controllers/Catering/ProductController.php:59 `$product->tags()->sync($tagIds);`; responses app/Http/Controllers/Catering/ProductController.php:17 `return response()->json([`; app/Http/Controllers/Catering/ProductController.php:25 `return redirect()->route('catering.meal-planner');`; app/Http/Controllers/Catering/ProductController.php:43 `return response()->json($product->load('tags'));`; app/Http/Controllers/Catering/ProductController.php:46 `return back()->with('status', 'Product created');`; app/Http/Controllers/Catering/ProductController.php:75 `return response()->json(['deleted' => true]);`; app/Http/Controllers/Catering/ProductController.php:78 `return back()->with('status', 'Product archived');`; app/Http/Controllers/Catering/ProductController.php:63 `return response()->json($product->fresh('tags'));`; app/Http/Controllers/Catering/ProductController.php:66 `return back()->with('status', 'Product updated');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD catering/products` — `catering.products.index` — `App\Http\Controllers\Catering\ProductController@index` — `app/Http/Controllers/Catering/ProductController.php:12` — middleware `web, auth, verified, permission:catering.products.view`
- `POST catering/products` — `catering.products.store` — `App\Http\Controllers\Catering\ProductController@store` — `app/Http/Controllers/Catering/ProductController.php:28` — middleware `web, auth, verified, permission:catering.products.view, permission:catering.products.manage`
- `DELETE catering/products/{product}` — `catering.products.destroy` — `App\Http\Controllers\Catering\ProductController@destroy` — `app/Http/Controllers/Catering/ProductController.php:69` — middleware `web, auth, verified, permission:catering.products.view, permission:catering.products.manage`
- `PUT catering/products/{product}` — `catering.products.update` — `App\Http\Controllers\Catering\ProductController@update` — `app/Http/Controllers/Catering/ProductController.php:49` — middleware `web, auth, verified, permission:catering.products.view, permission:catering.products.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Catering/ProductController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
