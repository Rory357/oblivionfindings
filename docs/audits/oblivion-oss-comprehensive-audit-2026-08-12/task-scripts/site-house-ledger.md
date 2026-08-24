# SITE-HOUSE-LEDGER: House Ledger

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.ledger.create`, `permission:sites.ledger.manage`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-HOUSE-LEDGER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/ledger` (`sites.ledger.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.ledger.create`, `permission:sites.ledger.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.ledger.create`, `permission:sites.ledger.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/ledger` (`sites.ledger.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD sites/{site}/ledger/entries/{entry}/attachment` (`sites.ledger.entries.attachment`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Sites/HouseLedgerController.php:101-127`.
3. Use `GET|HEAD sites/{site}/ledger/entries/{entry}/download` (`sites.ledger.entries.download`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Sites/HouseLedgerController.php:101-127`.
4. Invoke only the owning control for `POST sites/{site}/ledger` (`sites.ledger.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/HouseLedgerController.php:53-99`; `entry_type`.
5. Invoke only the owning control for `POST sites/{site}/ledger/entries` (`sites.ledger.entries.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/HouseLedgerController.php:53-99`; `entry_type`.
6. Invoke only the owning control for `POST sites/{site}/ledger/reconcile` (`sites.ledger.reconcile`, action `reconcile`). Source category: **retried/replayed/reconciled**; controller `app/Http/Controllers/Sites/HouseLedgerController.php:129-143`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2816` at `app/Http/Controllers/Sites/HouseLedgerController.php:18`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2817` at `app/Http/Controllers/Sites/HouseLedgerController.php:53`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2818` at `app/Http/Controllers/Sites/HouseLedgerController.php:53`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-2819` at `app/Http/Controllers/Sites/HouseLedgerController.php:101`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-2820` at `app/Http/Controllers/Sites/HouseLedgerController.php:101`; it is not runtime-observed.
- **retried/replayed/reconciled** is applicable only to `reconcile` / `ROUTE-2821` at `app/Http/Controllers/Sites/HouseLedgerController.php:129`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/ledger/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2816` / `index`: fields `from`.
- `ROUTE-2817` / `store`: fields `entry_type`; success app/Http/Controllers/Sites/HouseLedgerController.php:98 `return redirect()->back()->with('success', 'Ledger entry recorded.');`.
- `ROUTE-2818` / `store`: fields `entry_type`; success app/Http/Controllers/Sites/HouseLedgerController.php:98 `return redirect()->back()->with('success', 'Ledger entry recorded.');`.
- `ROUTE-2819` / `downloadAttachment`: failure app/Http/Controllers/Sites/HouseLedgerController.php:111 `abort(404, 'No attachment found.');`.
- `ROUTE-2820` / `downloadAttachment`: failure app/Http/Controllers/Sites/HouseLedgerController.php:111 `abort(404, 'No attachment found.');`.
- `ROUTE-2821` / `reconcile`: success app/Http/Controllers/Sites/HouseLedgerController.php:142 `return redirect()->back()->with('success', 'Ledger reconciled.');`.

## Failure and recovery paths

- `downloadAttachment`: app/Http/Controllers/Sites/HouseLedgerController.php:111 `abort(404, 'No attachment found.');`.
- `downloadAttachment`: app/Http/Controllers/Sites/HouseLedgerController.php:111 `abort(404, 'No attachment found.');`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Sites/HouseLedgerController.php:47 `return response()->json($payload);`; app/Http/Controllers/Sites/HouseLedgerController.php:50 `return Inertia::render('sites/ledger/index', $payload);`; app/Http/Controllers/Sites/HouseLedgerController.php:92 `return response()->json([`; app/Http/Controllers/Sites/HouseLedgerController.php:98 `return redirect()->back()->with('success', 'Ledger entry recorded.');`; app/Http/Controllers/Sites/HouseLedgerController.php:123 `return Storage::disk($disk)->download(`; app/Http/Controllers/Sites/HouseLedgerController.php:137 `return response()->json([`; app/Http/Controllers/Sites/HouseLedgerController.php:142 `return redirect()->back()->with('success', 'Ledger reconciled.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/ledger` — `sites.ledger.index` — `App\Http\Controllers\Sites\HouseLedgerController@index` — `app/Http/Controllers/Sites/HouseLedgerController.php:18` — middleware `web, auth, verified, permission:sites.viewAny`
- `POST sites/{site}/ledger` — `sites.ledger.store` — `App\Http\Controllers\Sites\HouseLedgerController@store` — `app/Http/Controllers/Sites/HouseLedgerController.php:53` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.ledger.create`
- `POST sites/{site}/ledger/entries` — `sites.ledger.entries.store` — `App\Http\Controllers\Sites\HouseLedgerController@store` — `app/Http/Controllers/Sites/HouseLedgerController.php:53` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.ledger.create`
- `GET|HEAD sites/{site}/ledger/entries/{entry}/attachment` — `sites.ledger.entries.attachment` — `App\Http\Controllers\Sites\HouseLedgerController@downloadAttachment` — `app/Http/Controllers/Sites/HouseLedgerController.php:101` — middleware `web, auth, verified, permission:sites.viewAny`
- `GET|HEAD sites/{site}/ledger/entries/{entry}/download` — `sites.ledger.entries.download` — `App\Http\Controllers\Sites\HouseLedgerController@downloadAttachment` — `app/Http/Controllers/Sites/HouseLedgerController.php:101` — middleware `web, auth, verified, permission:sites.viewAny`
- `POST sites/{site}/ledger/reconcile` — `sites.ledger.reconcile` — `App\Http\Controllers\Sites\HouseLedgerController@reconcile` — `app/Http/Controllers/Sites/HouseLedgerController.php:129` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.ledger.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/HouseLedgerController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/ledger/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
