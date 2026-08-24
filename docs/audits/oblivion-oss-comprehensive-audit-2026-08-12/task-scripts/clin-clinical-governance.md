# CLIN-CLINICAL-GOVERNANCE: Clinical Governance

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.clinical.view`, `permission:governance.clinical.manage`
- Owning module: Health and clinical
- Legacy family: `CLIN-CLINICAL-GOVERNANCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/clinical` (`governance.clinical.dashboard`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.clinical.view`, `permission:governance.clinical.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.clinical.view`, `permission:governance.clinical.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/clinical` (`governance.clinical.dashboard`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/clinical/trends` (`governance.clinical.trends`, action `trends`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:88-99`.
3. Invoke only the owning control for `POST governance/clinical/indicators` (`governance.clinical.indicators.store`, action `storeIndicator`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:32-64`; `name`, `category`.
4. Invoke only the owning control for `POST governance/clinical/snapshots` (`governance.clinical.snapshots.store`, action `recordSnapshot`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:66-86`; `period_start`, `period_end`, `indicator_values`, `narrative`.

## Source-applicable states and transitions

- **information presented** is applicable only to `dashboard` / `ROUTE-0897` at `app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:20`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeIndicator` / `ROUTE-0898` at `app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:32`; it is not runtime-observed.
- **created/recorded** is applicable only to `recordSnapshot` / `ROUTE-0899` at `app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:66`; it is not runtime-observed.
- **information presented** is applicable only to `trends` / `ROUTE-0900` at `app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:88`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Clinical/Dashboard.tsx`, `resources/js/pages/Governance/Clinical/Trends.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0898` / `storeIndicator`: fields `name`, `category`; success app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:63 `return redirect()->back()->with('success', 'Clinical indicator added.');`.
- `ROUTE-0899` / `recordSnapshot`: fields `period_start`, `period_end`, `indicator_values`, `narrative`; success app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:85 `return redirect()->back()->with('success', 'Clinical governance snapshot recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:48 `ClinicalGovernanceIndicator::create([`; app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:77 `ClinicalGovernanceSnapshot::create([`; responses app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:25 `return Inertia::render('Governance/Clinical/Dashboard', [`; app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:63 `return redirect()->back()->with('success', 'Clinical indicator added.');`; app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:85 `return redirect()->back()->with('success', 'Clinical governance snapshot recorded.');`; app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:94 `return Inertia::render('Governance/Clinical/Trends', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/clinical` — `governance.clinical.dashboard` — `App\Domain\Governance\Http\Controllers\ClinicalGovernanceController@dashboard` — `app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:20` — middleware `web, auth, permission:governance.clinical.view`
- `POST governance/clinical/indicators` — `governance.clinical.indicators.store` — `App\Domain\Governance\Http\Controllers\ClinicalGovernanceController@storeIndicator` — `app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:32` — middleware `web, auth, permission:governance.clinical.view, permission:governance.clinical.manage`
- `POST governance/clinical/snapshots` — `governance.clinical.snapshots.store` — `App\Domain\Governance\Http\Controllers\ClinicalGovernanceController@recordSnapshot` — `app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:66` — middleware `web, auth, permission:governance.clinical.view, permission:governance.clinical.manage`
- `GET|HEAD governance/clinical/trends` — `governance.clinical.trends` — `App\Domain\Governance\Http\Controllers\ClinicalGovernanceController@trends` — `app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php:88` — middleware `web, auth, permission:governance.clinical.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Clinical/Dashboard.tsx`, `resources/js/pages/Governance/Clinical/Trends.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
