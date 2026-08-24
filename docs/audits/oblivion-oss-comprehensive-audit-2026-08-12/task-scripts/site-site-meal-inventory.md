# SITE-SITE-MEAL-INVENTORY: Site Meal Inventory

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.inventory.adjust`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-MEAL-INVENTORY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/meal-inventory` (`sites.meals.inventory.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.inventory.adjust`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.inventory.adjust`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/meal-inventory` (`sites.meals.inventory.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD sites/{site}/meal-inventory/movements` (`sites.meals.inventory.movements`, action `movements`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteMealInventoryController.php:152-170`.
3. Invoke only the owning control for `POST sites/{site}/meal-inventory/adjust` (`sites.meals.inventory.adjust`, action `adjust`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteMealInventoryController.php:103-125`; `product_id`, `delta`, `unit`, `reason`, `note`.
4. Invoke only the owning control for `POST sites/{site}/meal-inventory/items` (`sites.meals.inventory.items.store`, action `storeItem`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteMealInventoryController.php:37-76`; `product_id`, `unit`, `current_qty`, `par_level`, `reorder_level`, `location_label`.
5. Invoke only the owning control for `DELETE sites/{site}/meal-inventory/items/{item}` (`sites.meals.inventory.items.destroy`, action `destroyItem`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteMealInventoryController.php:95-101`; no exact validation fields extracted.
6. Invoke only the owning control for `PUT sites/{site}/meal-inventory/items/{item}` (`sites.meals.inventory.items.update`, action `updateItem`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteMealInventoryController.php:78-93`; `unit`, `par_level`, `reorder_level`, `location_label`.
7. Invoke only the owning control for `POST sites/{site}/meal-inventory/stocktake` (`sites.meals.inventory.stocktake`, action `stocktake`). Source category: **mutation outcome source gap (stocktake)**; controller `app/Http/Controllers/Sites/SiteMealInventoryController.php:127-150`; `counts`, `note`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2823` at `app/Http/Controllers/Sites/SiteMealInventoryController.php:19`; it is not runtime-observed.
- **updated/revised** is applicable only to `adjust` / `ROUTE-2824` at `app/Http/Controllers/Sites/SiteMealInventoryController.php:103`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeItem` / `ROUTE-2825` at `app/Http/Controllers/Sites/SiteMealInventoryController.php:37`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyItem` / `ROUTE-2826` at `app/Http/Controllers/Sites/SiteMealInventoryController.php:95`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateItem` / `ROUTE-2827` at `app/Http/Controllers/Sites/SiteMealInventoryController.php:78`; it is not runtime-observed.
- **information presented** is applicable only to `movements` / `ROUTE-2828` at `app/Http/Controllers/Sites/SiteMealInventoryController.php:152`; it is not runtime-observed.
- **mutation outcome source gap (stocktake)** is applicable only to `stocktake` / `ROUTE-2829` at `app/Http/Controllers/Sites/SiteMealInventoryController.php:127`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2824` / `adjust`: fields `product_id`, `delta`, `unit`, `reason`, `note`.
- `ROUTE-2825` / `storeItem`: fields `product_id`, `unit`, `current_qty`, `par_level`, `reorder_level`, `location_label`.
- `ROUTE-2827` / `updateItem`: fields `unit`, `par_level`, `reorder_level`, `location_label`.
- `ROUTE-2829` / `stocktake`: fields `counts`, `note`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteMealInventoryController.php:50 `$item = SiteMealInventoryItem::firstOrCreate(`; app/Http/Controllers/Sites/SiteMealInventoryController.php:58 `$item->update([`; app/Http/Controllers/Sites/SiteMealInventoryController.php:99 `$item->delete();`; app/Http/Controllers/Sites/SiteMealInventoryController.php:90 `$item->update(array_filter($data, fn ($v) => $v !== null));`; responses app/Http/Controllers/Sites/SiteMealInventoryController.php:29 `return response()->json([`; app/Http/Controllers/Sites/SiteMealInventoryController.php:124 `return $this->inertiaOrJson($request, 'Adjustment recorded');`; app/Http/Controllers/Sites/SiteMealInventoryController.php:75 `return $this->inertiaOrJson($request, 'Inventory item saved');`; app/Http/Controllers/Sites/SiteMealInventoryController.php:100 `return $this->inertiaOrJson($request, 'Inventory item removed');`; app/Http/Controllers/Sites/SiteMealInventoryController.php:92 `return $this->inertiaOrJson($request, 'Inventory item updated');`; app/Http/Controllers/Sites/SiteMealInventoryController.php:166 `return response()->json([`; app/Http/Controllers/Sites/SiteMealInventoryController.php:149 `return $this->inertiaOrJson($request, 'Stocktake recorded for ' . count($data['counts']) . ' items');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/meal-inventory` — `sites.meals.inventory.index` — `App\Http\Controllers\Sites\SiteMealInventoryController@index` — `app/Http/Controllers/Sites/SiteMealInventoryController.php:19` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view`
- `POST sites/{site}/meal-inventory/adjust` — `sites.meals.inventory.adjust` — `App\Http\Controllers\Sites\SiteMealInventoryController@adjust` — `app/Http/Controllers/Sites/SiteMealInventoryController.php:103` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.inventory.adjust`
- `POST sites/{site}/meal-inventory/items` — `sites.meals.inventory.items.store` — `App\Http\Controllers\Sites\SiteMealInventoryController@storeItem` — `app/Http/Controllers/Sites/SiteMealInventoryController.php:37` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.inventory.adjust`
- `DELETE sites/{site}/meal-inventory/items/{item}` — `sites.meals.inventory.items.destroy` — `App\Http\Controllers\Sites\SiteMealInventoryController@destroyItem` — `app/Http/Controllers/Sites/SiteMealInventoryController.php:95` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.inventory.adjust`
- `PUT sites/{site}/meal-inventory/items/{item}` — `sites.meals.inventory.items.update` — `App\Http\Controllers\Sites\SiteMealInventoryController@updateItem` — `app/Http/Controllers/Sites/SiteMealInventoryController.php:78` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.inventory.adjust`
- `GET|HEAD sites/{site}/meal-inventory/movements` — `sites.meals.inventory.movements` — `App\Http\Controllers\Sites\SiteMealInventoryController@movements` — `app/Http/Controllers/Sites/SiteMealInventoryController.php:152` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view`
- `POST sites/{site}/meal-inventory/stocktake` — `sites.meals.inventory.stocktake` — `App\Http\Controllers\Sites\SiteMealInventoryController@stocktake` — `app/Http/Controllers/Sites/SiteMealInventoryController.php:127` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.inventory.adjust`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteMealInventoryController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
