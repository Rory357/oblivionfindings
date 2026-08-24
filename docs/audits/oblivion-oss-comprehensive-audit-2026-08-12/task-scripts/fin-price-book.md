# FIN-PRICE-BOOK: Price Book

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-PRICE-BOOK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/price-books` (`finance.price_books.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/price-books` (`finance.price_books.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/price-books/{priceBook}` (`finance.price_books.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/PriceBookController.php:36-52`.
3. Invoke only the owning control for `POST finance/price-books` (`finance.price_books.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/PriceBookController.php:54-77`; `name`.
4. Invoke only the owning control for `PUT finance/price-books/{priceBook}` (`finance.price_books.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/PriceBookController.php:79-99`; `name`.
5. Invoke only the owning control for `POST finance/price-books/{priceBook}/items` (`finance.price_books.items.store`, action `storeItem`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/PriceBookController.php:101-121`; `name`.
6. Invoke only the owning control for `DELETE finance/price-books/{priceBook}/items/{item}` (`finance.price_books.items.destroy`, action `destroyItem`). Source category: **cancelled/removed/archived**; controller `app/Domain/Finance/Http/Controllers/PriceBookController.php:147-160`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT finance/price-books/{priceBook}/items/{item}` (`finance.price_books.items.update`, action `updateItem`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/PriceBookController.php:123-145`; `name`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0644` at `app/Domain/Finance/Http/Controllers/PriceBookController.php:12`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0645` at `app/Domain/Finance/Http/Controllers/PriceBookController.php:54`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0646` at `app/Domain/Finance/Http/Controllers/PriceBookController.php:36`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0647` at `app/Domain/Finance/Http/Controllers/PriceBookController.php:79`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeItem` / `ROUTE-0649` at `app/Domain/Finance/Http/Controllers/PriceBookController.php:101`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyItem` / `ROUTE-0650` at `app/Domain/Finance/Http/Controllers/PriceBookController.php:147`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateItem` / `ROUTE-0651` at `app/Domain/Finance/Http/Controllers/PriceBookController.php:123`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/price-books/Index.tsx`, `resources/js/pages/finance/price-books/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0644` / `index`: fields `active`.
- `ROUTE-0645` / `store`: fields `name`; success app/Domain/Finance/Http/Controllers/PriceBookController.php:76 `return redirect()->back()->with('success', 'Price book created.');`.
- `ROUTE-0647` / `update`: fields `name`; success app/Domain/Finance/Http/Controllers/PriceBookController.php:98 `return redirect()->back()->with('success', 'Price book updated.');`.
- `ROUTE-0649` / `storeItem`: fields `name`; success app/Domain/Finance/Http/Controllers/PriceBookController.php:120 `return redirect()->back()->with('success', 'Item added.');`.
- `ROUTE-0650` / `destroyItem`: success app/Domain/Finance/Http/Controllers/PriceBookController.php:159 `return redirect()->back()->with('success', 'Item removed.');`.
- `ROUTE-0651` / `updateItem`: fields `name`; success app/Domain/Finance/Http/Controllers/PriceBookController.php:144 `return redirect()->back()->with('success', 'Item updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/PriceBookController.php:67 `$priceBook = PriceBook::create([`; app/Domain/Finance/Http/Controllers/PriceBookController.php:96 `$priceBook->update($data);`; app/Domain/Finance/Http/Controllers/PriceBookController.php:118 `$priceBook->items()->create($data);`; app/Domain/Finance/Http/Controllers/PriceBookController.php:157 `$priceBookItem->delete();`; app/Domain/Finance/Http/Controllers/PriceBookController.php:142 `$priceBookItem->update($data);`; responses app/Domain/Finance/Http/Controllers/PriceBookController.php:29 `return inertia('finance/price-books/Index', [`; app/Domain/Finance/Http/Controllers/PriceBookController.php:76 `return redirect()->back()->with('success', 'Price book created.');`; app/Domain/Finance/Http/Controllers/PriceBookController.php:46 `return inertia('finance/price-books/Show', [`; app/Domain/Finance/Http/Controllers/PriceBookController.php:98 `return redirect()->back()->with('success', 'Price book updated.');`; app/Domain/Finance/Http/Controllers/PriceBookController.php:120 `return redirect()->back()->with('success', 'Item added.');`; app/Domain/Finance/Http/Controllers/PriceBookController.php:159 `return redirect()->back()->with('success', 'Item removed.');`; app/Domain/Finance/Http/Controllers/PriceBookController.php:144 `return redirect()->back()->with('success', 'Item updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/price-books` — `finance.price_books.index` — `App\Domain\Finance\Http\Controllers\PriceBookController@index` — `app/Domain/Finance/Http/Controllers/PriceBookController.php:12` — middleware `web, auth, permission:finance.ar.view`
- `POST finance/price-books` — `finance.price_books.store` — `App\Domain\Finance\Http\Controllers\PriceBookController@store` — `app/Domain/Finance/Http/Controllers/PriceBookController.php:54` — middleware `web, auth, permission:finance.ar.manage`
- `GET|HEAD finance/price-books/{priceBook}` — `finance.price_books.show` — `App\Domain\Finance\Http\Controllers\PriceBookController@show` — `app/Domain/Finance/Http/Controllers/PriceBookController.php:36` — middleware `web, auth, permission:finance.ar.view`
- `PUT finance/price-books/{priceBook}` — `finance.price_books.update` — `App\Domain\Finance\Http\Controllers\PriceBookController@update` — `app/Domain/Finance/Http/Controllers/PriceBookController.php:79` — middleware `web, auth, permission:finance.ar.manage`
- `POST finance/price-books/{priceBook}/items` — `finance.price_books.items.store` — `App\Domain\Finance\Http\Controllers\PriceBookController@storeItem` — `app/Domain/Finance/Http/Controllers/PriceBookController.php:101` — middleware `web, auth, permission:finance.ar.manage`
- `DELETE finance/price-books/{priceBook}/items/{item}` — `finance.price_books.items.destroy` — `App\Domain\Finance\Http\Controllers\PriceBookController@destroyItem` — `app/Domain/Finance/Http/Controllers/PriceBookController.php:147` — middleware `web, auth, permission:finance.ar.manage`
- `PUT finance/price-books/{priceBook}/items/{item}` — `finance.price_books.items.update` — `App\Domain\Finance\Http\Controllers\PriceBookController@updateItem` — `app/Domain/Finance/Http/Controllers/PriceBookController.php:123` — middleware `web, auth, permission:finance.ar.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/PriceBookController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/price-books/Index.tsx`, `resources/js/pages/finance/price-books/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
