# OPS-CLIENT-FUND: Client Fund

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:client_funds.manage`
- Owning module: Operations and rostering
- Legacy family: `OPS-CLIENT-FUND`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/client-funds` (`operations.client_funds.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:client_funds.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:client_funds.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/client-funds` (`operations.client_funds.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/client-funds/{fund}` (`operations.client_funds.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/ClientFundController.php:76-89`.
3. Use `GET|HEAD operations/client-funds/create` (`operations.client_funds.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/ClientFundController.php:91-105`.
4. Invoke only the owning control for `POST operations/client-funds` (`operations.client_funds.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ClientFundController.php:107-156`; `client_id`.
5. Invoke only the owning control for `PUT operations/client-funds/{fund}` (`operations.client_funds.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/ClientFundController.php:158-183`; `name`.
6. Invoke only the owning control for `POST operations/client-funds/{fund}/transactions` (`operations.client_funds.transactions.store`, action `addTransaction`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ClientFundController.php:185-205`; `type`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1927` at `app/Http/Controllers/Operations/ClientFundController.php:20`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1928` at `app/Http/Controllers/Operations/ClientFundController.php:107`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1929` at `app/Http/Controllers/Operations/ClientFundController.php:76`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1930` at `app/Http/Controllers/Operations/ClientFundController.php:158`; it is not runtime-observed.
- **created/recorded** is applicable only to `addTransaction` / `ROUTE-1931` at `app/Http/Controllers/Operations/ClientFundController.php:185`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1932` at `app/Http/Controllers/Operations/ClientFundController.php:91`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/client-funds/Create.tsx`, `resources/js/pages/operations/client-funds/Index.tsx`, `resources/js/pages/operations/client-funds/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1927` / `index`: fields `q`.
- `ROUTE-1928` / `store`: fields `client_id`; success app/Http/Controllers/Operations/ClientFundController.php:155 `return redirect()->back()->with('success', 'Client fund created.');`.
- `ROUTE-1930` / `update`: fields `name`; success app/Http/Controllers/Operations/ClientFundController.php:182 `return redirect()->back()->with('success', 'Client fund updated.');`.
- `ROUTE-1931` / `addTransaction`: fields `type`; success app/Http/Controllers/Operations/ClientFundController.php:204 `return redirect()->back()->with('success', 'Transaction recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/ClientFundController.php:134 `$fund = ClientFund::query()->create([`; app/Http/Controllers/Operations/ClientFundController.php:175 `$fund->update(array_filter([`; responses app/Http/Controllers/Operations/ClientFundController.php:54 `return inertia('operations/client-funds/Index', [`; app/Http/Controllers/Operations/ClientFundController.php:155 `return redirect()->back()->with('success', 'Client fund created.');`; app/Http/Controllers/Operations/ClientFundController.php:86 `return inertia('operations/client-funds/Show', [`; app/Http/Controllers/Operations/ClientFundController.php:182 `return redirect()->back()->with('success', 'Client fund updated.');`; app/Http/Controllers/Operations/ClientFundController.php:204 `return redirect()->back()->with('success', 'Transaction recorded.');`; app/Http/Controllers/Operations/ClientFundController.php:102 `return inertia('operations/client-funds/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/client-funds` — `operations.client_funds.index` — `App\Http\Controllers\Operations\ClientFundController@index` — `app/Http/Controllers/Operations/ClientFundController.php:20` — middleware `web, auth, permission:client_funds.manage`
- `POST operations/client-funds` — `operations.client_funds.store` — `App\Http\Controllers\Operations\ClientFundController@store` — `app/Http/Controllers/Operations/ClientFundController.php:107` — middleware `web, auth, permission:client_funds.manage`
- `GET|HEAD operations/client-funds/{fund}` — `operations.client_funds.show` — `App\Http\Controllers\Operations\ClientFundController@show` — `app/Http/Controllers/Operations/ClientFundController.php:76` — middleware `web, auth, permission:client_funds.manage`
- `PUT operations/client-funds/{fund}` — `operations.client_funds.update` — `App\Http\Controllers\Operations\ClientFundController@update` — `app/Http/Controllers/Operations/ClientFundController.php:158` — middleware `web, auth, permission:client_funds.manage`
- `POST operations/client-funds/{fund}/transactions` — `operations.client_funds.transactions.store` — `App\Http\Controllers\Operations\ClientFundController@addTransaction` — `app/Http/Controllers/Operations/ClientFundController.php:185` — middleware `web, auth, permission:client_funds.manage`
- `GET|HEAD operations/client-funds/create` — `operations.client_funds.create` — `App\Http\Controllers\Operations\ClientFundController@create` — `app/Http/Controllers/Operations/ClientFundController.php:91` — middleware `web, auth, permission:client_funds.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/ClientFundController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/client-funds/Create.tsx`, `resources/js/pages/operations/client-funds/Index.tsx`, `resources/js/pages/operations/client-funds/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
