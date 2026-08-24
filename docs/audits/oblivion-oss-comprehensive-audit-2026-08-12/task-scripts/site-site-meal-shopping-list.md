# SITE-SITE-MEAL-SHOPPING-LIST: Site Meal Shopping List

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.shopping.manage`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-MEAL-SHOPPING-LIST`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/meal-shopping-lists` (`sites.meals.shopping.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.shopping.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.shopping.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/meal-shopping-lists` (`sites.meals.shopping.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `DELETE sites/{site}/meal-shopping-lists/{list}` (`sites.meals.shopping.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteMealShoppingListController.php:208-217`; no exact validation fields extracted.
3. Invoke only the owning control for `PUT sites/{site}/meal-shopping-lists/{list}` (`sites.meals.shopping.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteMealShoppingListController.php:110-129`; `provider_key`, `provider_order_ref`, `notes`.
4. Invoke only the owning control for `POST sites/{site}/meal-shopping-lists/{list}/items` (`sites.meals.shopping.addItem`, action `addItem`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteMealShoppingListController.php:131-156`; `product_id`, `free_text_name`, `needed_qty`, `unit`, `notes`.
5. Invoke only the owning control for `DELETE sites/{site}/meal-shopping-lists/{list}/items/{item}` (`sites.meals.shopping.removeItem`, action `removeItem`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteMealShoppingListController.php:158-168`; no exact validation fields extracted.
6. Invoke only the owning control for `POST sites/{site}/meal-shopping-lists/{list}/receive` (`sites.meals.shopping.receive`, action `markReceived`). Source category: **mutation outcome source gap (markReceived)**; controller `app/Http/Controllers/Sites/SiteMealShoppingListController.php:170-206`; `items`.
7. Invoke only the owning control for `POST sites/{site}/meal-shopping-lists/generate` (`sites.meals.shopping.generate`, action `generate`). Source category: **mutation outcome source gap (generate)**; controller `app/Http/Controllers/Sites/SiteMealShoppingListController.php:90-108`; `covers_from`, `covers_to`, `include_restock_to_par`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2844` at `app/Http/Controllers/Sites/SiteMealShoppingListController.php:27`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2845` at `app/Http/Controllers/Sites/SiteMealShoppingListController.php:208`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2846` at `app/Http/Controllers/Sites/SiteMealShoppingListController.php:110`; it is not runtime-observed.
- **created/recorded** is applicable only to `addItem` / `ROUTE-2847` at `app/Http/Controllers/Sites/SiteMealShoppingListController.php:131`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `removeItem` / `ROUTE-2848` at `app/Http/Controllers/Sites/SiteMealShoppingListController.php:158`; it is not runtime-observed.
- **mutation outcome source gap (markReceived)** is applicable only to `markReceived` / `ROUTE-2849` at `app/Http/Controllers/Sites/SiteMealShoppingListController.php:170`; it is not runtime-observed.
- **mutation outcome source gap (generate)** is applicable only to `generate` / `ROUTE-2850` at `app/Http/Controllers/Sites/SiteMealShoppingListController.php:90`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2846` / `update`: fields `provider_key`, `provider_order_ref`, `notes`.
- `ROUTE-2847` / `addItem`: fields `product_id`, `free_text_name`, `needed_qty`, `unit`, `notes`.
- `ROUTE-2849` / `markReceived`: fields `items`.
- `ROUTE-2850` / `generate`: fields `covers_from`, `covers_to`, `include_restock_to_par`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteMealShoppingListController.php:214 `$list->delete();`; app/Http/Controllers/Sites/SiteMealShoppingListController.php:126 `$list->update($payload);`; app/Http/Controllers/Sites/SiteMealShoppingListController.php:145 `SiteMealShoppingListItem::create([`; app/Http/Controllers/Sites/SiteMealShoppingListController.php:165 `$item->delete();`; app/Http/Controllers/Sites/SiteMealShoppingListController.php:187 `$item->update(['received_qty' => $row['received_qty'], 'is_checked' => true]);`; app/Http/Controllers/Sites/SiteMealShoppingListController.php:203 `$list->update(['status' => 'received', 'received_at' => now()]);`; responses app/Http/Controllers/Sites/SiteMealShoppingListController.php:41 `return response()->json([`; app/Http/Controllers/Sites/SiteMealShoppingListController.php:216 `return $this->inertiaOrJson($request, 'Shopping list deleted');`; app/Http/Controllers/Sites/SiteMealShoppingListController.php:128 `return $this->inertiaOrJson($request, 'Shopping list updated');`; app/Http/Controllers/Sites/SiteMealShoppingListController.php:155 `return $this->inertiaOrJson($request, 'Item added');`; app/Http/Controllers/Sites/SiteMealShoppingListController.php:167 `return $this->inertiaOrJson($request, 'Item removed');`; app/Http/Controllers/Sites/SiteMealShoppingListController.php:205 `return $this->inertiaOrJson($request, 'Shopping list received and inventory updated');`; app/Http/Controllers/Sites/SiteMealShoppingListController.php:107 `return $this->inertiaOrJson($request, 'Shopping list generated', ['list_id' => $list->id]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/meal-shopping-lists` — `sites.meals.shopping.index` — `App\Http\Controllers\Sites\SiteMealShoppingListController@index` — `app/Http/Controllers/Sites/SiteMealShoppingListController.php:27` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view`
- `DELETE sites/{site}/meal-shopping-lists/{list}` — `sites.meals.shopping.destroy` — `App\Http\Controllers\Sites\SiteMealShoppingListController@destroy` — `app/Http/Controllers/Sites/SiteMealShoppingListController.php:208` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.shopping.manage`
- `PUT sites/{site}/meal-shopping-lists/{list}` — `sites.meals.shopping.update` — `App\Http\Controllers\Sites\SiteMealShoppingListController@update` — `app/Http/Controllers/Sites/SiteMealShoppingListController.php:110` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.shopping.manage`
- `POST sites/{site}/meal-shopping-lists/{list}/items` — `sites.meals.shopping.addItem` — `App\Http\Controllers\Sites\SiteMealShoppingListController@addItem` — `app/Http/Controllers/Sites/SiteMealShoppingListController.php:131` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.shopping.manage`
- `DELETE sites/{site}/meal-shopping-lists/{list}/items/{item}` — `sites.meals.shopping.removeItem` — `App\Http\Controllers\Sites\SiteMealShoppingListController@removeItem` — `app/Http/Controllers/Sites/SiteMealShoppingListController.php:158` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.shopping.manage`
- `POST sites/{site}/meal-shopping-lists/{list}/receive` — `sites.meals.shopping.receive` — `App\Http\Controllers\Sites\SiteMealShoppingListController@markReceived` — `app/Http/Controllers/Sites/SiteMealShoppingListController.php:170` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.shopping.manage`
- `POST sites/{site}/meal-shopping-lists/generate` — `sites.meals.shopping.generate` — `App\Http\Controllers\Sites\SiteMealShoppingListController@generate` — `app/Http/Controllers/Sites/SiteMealShoppingListController.php:90` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.shopping.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteMealShoppingListController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
