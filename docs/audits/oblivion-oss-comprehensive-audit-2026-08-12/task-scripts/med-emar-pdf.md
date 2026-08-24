# MED-EMAR-PDF: Emar Pdf

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.reports.export|reports.viewAny`
- Owning module: eMAR and medications
- Legacy family: `MED-EMAR-PDF`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/pdf/controlled-register` (`emar.pdf.cd_register`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.reports.export|reports.viewAny`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.reports.export|reports.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/pdf/controlled-register` (`emar.pdf.cd_register`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD emar/pdf/mar-chart` (`emar.pdf.mar`, action `marChart`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Emar/EmarPdfController.php:22-77`.
3. Use `GET|HEAD emar/pdf/round-sheet` (`emar.pdf.round_sheet`, action `roundSheet`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Emar/EmarPdfController.php:120-150`.

## Source-applicable states and transitions

- **information presented** is applicable only to `controlledDrugRegister` / `ROUTE-0392` at `app/Http/Controllers/Emar/EmarPdfController.php:82`; it is not runtime-observed.
- **information presented** is applicable only to `marChart` / `ROUTE-0393` at `app/Http/Controllers/Emar/EmarPdfController.php:22`; it is not runtime-observed.
- **information presented** is applicable only to `roundSheet` / `ROUTE-0394` at `app/Http/Controllers/Emar/EmarPdfController.php:120`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0392` / `controlledDrugRegister`: fields `client_id`, `date_from`, `date_to`.
- `ROUTE-0393` / `marChart`: fields `client_id`, `date_from`, `date_to`.
- `ROUTE-0394` / `roundSheet`: fields `date`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/pdf/controlled-register` — `emar.pdf.cd_register` — `App\Http\Controllers\Emar\EmarPdfController@controlledDrugRegister` — `app/Http/Controllers/Emar/EmarPdfController.php:82` — middleware `web, auth, permission:medications.reports.export|reports.viewAny`
- `GET|HEAD emar/pdf/mar-chart` — `emar.pdf.mar` — `App\Http\Controllers\Emar\EmarPdfController@marChart` — `app/Http/Controllers/Emar/EmarPdfController.php:22` — middleware `web, auth, permission:medications.reports.export|reports.viewAny`
- `GET|HEAD emar/pdf/round-sheet` — `emar.pdf.round_sheet` — `App\Http\Controllers\Emar\EmarPdfController@roundSheet` — `app/Http/Controllers/Emar/EmarPdfController.php:120` — middleware `web, auth, permission:medications.reports.export|reports.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/EmarPdfController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
