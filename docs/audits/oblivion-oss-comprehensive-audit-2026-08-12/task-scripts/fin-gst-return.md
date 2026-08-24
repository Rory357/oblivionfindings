# FIN-GST-RETURN: Gst Return

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.tax.view`, `permission:finance.tax.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-GST-RETURN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/gst-returns` (`finance.gst-returns.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.tax.view`, `permission:finance.tax.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.tax.view`, `permission:finance.tax.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/gst-returns` (`finance.gst-returns.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/gst-returns/{gstReturn}` (`finance.gst-returns.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/GstReturnController.php:96-116`.
3. Use `GET|HEAD finance/gst-returns/prepare` (`finance.gst-returns.prepare`, action `prepare`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/GstReturnController.php:53-69`.
4. Invoke only the owning control for `POST finance/gst-returns` (`finance.gst-returns.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/GstReturnController.php:74-91`; `period_start`.
5. Invoke only the owning control for `POST finance/gst-returns/{gstReturn}/file` (`finance.gst-returns.file`, action `file`). Source category: **mutation outcome source gap (file)**; controller `app/Domain/Finance/Http/Controllers/GstReturnController.php:121-134`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0581` at `app/Domain/Finance/Http/Controllers/GstReturnController.php:22`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0582` at `app/Domain/Finance/Http/Controllers/GstReturnController.php:74`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0583` at `app/Domain/Finance/Http/Controllers/GstReturnController.php:96`; it is not runtime-observed.
- **mutation outcome source gap (file)** is applicable only to `file` / `ROUTE-0584` at `app/Domain/Finance/Http/Controllers/GstReturnController.php:121`; it is not runtime-observed.
- **information presented** is applicable only to `prepare` / `ROUTE-0585` at `app/Domain/Finance/Http/Controllers/GstReturnController.php:53`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/gst-returns/Index.tsx`, `resources/js/pages/finance/gst-returns/Prepare.tsx`, `resources/js/pages/finance/gst-returns/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0582` / `store`: fields `period_start`; success app/Domain/Finance/Http/Controllers/GstReturnController.php:90 `->with('success', 'GST return prepared for period ending ' . $gstReturn->period_end->format('d M Y') . '.');`.
- `ROUTE-0584` / `file`: success app/Domain/Finance/Http/Controllers/GstReturnController.php:133 `->with('success', 'GST return has been marked as filed.');`; failure app/Domain/Finance/Http/Controllers/GstReturnController.php:127 `->withErrors(['status' => 'Only draft returns can be filed.']);`.

## Failure and recovery paths

- `file`: app/Domain/Finance/Http/Controllers/GstReturnController.php:127 `->withErrors(['status' => 'Only draft returns can be filed.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Finance/Http/Controllers/GstReturnController.php:44 `return Inertia::render('finance/gst-returns/Index', [`; app/Domain/Finance/Http/Controllers/GstReturnController.php:89 `return redirect()->route('finance.gst-returns.show', $gstReturn)`; app/Domain/Finance/Http/Controllers/GstReturnController.php:90 `->with('success', 'GST return prepared for period ending ' . $gstReturn->period_end->format('d M Y') . '.');`; app/Domain/Finance/Http/Controllers/GstReturnController.php:111 `return Inertia::render('finance/gst-returns/Show', [`; app/Domain/Finance/Http/Controllers/GstReturnController.php:126 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/GstReturnController.php:132 `return redirect()->route('finance.gst-returns.show', $gstReturn)`; app/Domain/Finance/Http/Controllers/GstReturnController.php:133 `->with('success', 'GST return has been marked as filed.');`; app/Domain/Finance/Http/Controllers/GstReturnController.php:65 `return Inertia::render('finance/gst-returns/Prepare', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/gst-returns` — `finance.gst-returns.index` — `App\Domain\Finance\Http\Controllers\GstReturnController@index` — `app/Domain/Finance/Http/Controllers/GstReturnController.php:22` — middleware `web, auth, permission:finance.tax.view`
- `POST finance/gst-returns` — `finance.gst-returns.store` — `App\Domain\Finance\Http\Controllers\GstReturnController@store` — `app/Domain/Finance/Http/Controllers/GstReturnController.php:74` — middleware `web, auth, permission:finance.tax.manage`
- `GET|HEAD finance/gst-returns/{gstReturn}` — `finance.gst-returns.show` — `App\Domain\Finance\Http\Controllers\GstReturnController@show` — `app/Domain/Finance/Http/Controllers/GstReturnController.php:96` — middleware `web, auth, permission:finance.tax.view`
- `POST finance/gst-returns/{gstReturn}/file` — `finance.gst-returns.file` — `App\Domain\Finance\Http\Controllers\GstReturnController@file` — `app/Domain/Finance/Http/Controllers/GstReturnController.php:121` — middleware `web, auth, permission:finance.tax.manage`
- `GET|HEAD finance/gst-returns/prepare` — `finance.gst-returns.prepare` — `App\Domain\Finance\Http\Controllers\GstReturnController@prepare` — `app/Domain/Finance/Http/Controllers/GstReturnController.php:53` — middleware `web, auth, permission:finance.tax.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/GstReturnController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/gst-returns/Index.tsx`, `resources/js/pages/finance/gst-returns/Prepare.tsx`, `resources/js/pages/finance/gst-returns/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
