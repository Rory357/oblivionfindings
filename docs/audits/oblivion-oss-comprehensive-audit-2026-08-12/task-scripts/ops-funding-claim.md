# OPS-FUNDING-CLAIM: Funding Claim

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:funding.viewAny`, `permission:funding.claims.create`, `permission:funding.claims.approve`, `permission:funding.claims.submit`
- Owning module: Operations and rostering
- Legacy family: `OPS-FUNDING-CLAIM`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/funding/claims` (`operations.funding.claims.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:funding.viewAny`, `permission:funding.claims.create`, `permission:funding.claims.approve`, `permission:funding.claims.submit`.
- Exact middleware atoms: `web`, `auth`, `permission:funding.viewAny`, `permission:funding.claims.create`, `permission:funding.claims.approve`, `permission:funding.claims.submit`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/funding/claims` (`operations.funding.claims.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/funding/claims/{claim}` (`operations.funding.claims.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/FundingClaimController.php:124-143`.
3. Use `GET|HEAD operations/funding/claims/create` (`operations.funding.claims.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/FundingClaimController.php:50-64`.
4. Invoke only the owning control for `POST operations/funding/claims` (`operations.funding.claims.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/FundingClaimController.php:66-122`; `service_agreement_id`.
5. Invoke only the owning control for `POST operations/funding/claims/{claim}/approve` (`operations.funding.claims.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Operations/FundingClaimController.php:163-179`; no exact validation fields extracted.
6. Invoke only the owning control for `POST operations/funding/claims/{claim}/submit` (`operations.funding.claims.submit`, action `submit`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/FundingClaimController.php:145-161`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2076` at `app/Http/Controllers/Operations/FundingClaimController.php:15`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2077` at `app/Http/Controllers/Operations/FundingClaimController.php:66`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2078` at `app/Http/Controllers/Operations/FundingClaimController.php:124`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-2079` at `app/Http/Controllers/Operations/FundingClaimController.php:163`; it is not runtime-observed.
- **created/recorded** is applicable only to `submit` / `ROUTE-2080` at `app/Http/Controllers/Operations/FundingClaimController.php:145`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2081` at `app/Http/Controllers/Operations/FundingClaimController.php:50`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/funding/claims/Create.tsx`, `resources/js/pages/operations/funding/claims/Index.tsx`, `resources/js/pages/operations/funding/claims/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2077` / `store`: fields `service_agreement_id`; success app/Http/Controllers/Operations/FundingClaimController.php:121 `->with('success', 'Funding claim created.');`.
- `ROUTE-2079` / `approve`: success app/Http/Controllers/Operations/FundingClaimController.php:178 `return redirect()->back()->with('success', 'Claim approved.');`.
- `ROUTE-2080` / `submit`: success app/Http/Controllers/Operations/FundingClaimController.php:160 `return redirect()->back()->with('success', 'Claim submitted.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/FundingClaimController.php:90 `$claim = FundingClaim::create([`; app/Http/Controllers/Operations/FundingClaimController.php:102 `FundingClaimItem::create([`; app/Http/Controllers/Operations/FundingClaimController.php:172 `$claim->update([`; app/Http/Controllers/Operations/FundingClaimController.php:154 `$claim->update([`; responses app/Http/Controllers/Operations/FundingClaimController.php:43 `return inertia('operations/funding/claims/Index', [`; app/Http/Controllers/Operations/FundingClaimController.php:117 `return $claim;`; app/Http/Controllers/Operations/FundingClaimController.php:120 `return redirect()->route('operations.funding.claims.show', $claim)`; app/Http/Controllers/Operations/FundingClaimController.php:140 `return inertia('operations/funding/claims/Show', [`; app/Http/Controllers/Operations/FundingClaimController.php:178 `return redirect()->back()->with('success', 'Claim approved.');`; app/Http/Controllers/Operations/FundingClaimController.php:160 `return redirect()->back()->with('success', 'Claim submitted.');`; app/Http/Controllers/Operations/FundingClaimController.php:61 `return inertia('operations/funding/claims/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/funding/claims` — `operations.funding.claims.index` — `App\Http\Controllers\Operations\FundingClaimController@index` — `app/Http/Controllers/Operations/FundingClaimController.php:15` — middleware `web, auth, permission:funding.viewAny`
- `POST operations/funding/claims` — `operations.funding.claims.store` — `App\Http\Controllers\Operations\FundingClaimController@store` — `app/Http/Controllers/Operations/FundingClaimController.php:66` — middleware `web, auth, permission:funding.claims.create`
- `GET|HEAD operations/funding/claims/{claim}` — `operations.funding.claims.show` — `App\Http\Controllers\Operations\FundingClaimController@show` — `app/Http/Controllers/Operations/FundingClaimController.php:124` — middleware `web, auth, permission:funding.viewAny`
- `POST operations/funding/claims/{claim}/approve` — `operations.funding.claims.approve` — `App\Http\Controllers\Operations\FundingClaimController@approve` — `app/Http/Controllers/Operations/FundingClaimController.php:163` — middleware `web, auth, permission:funding.claims.approve`
- `POST operations/funding/claims/{claim}/submit` — `operations.funding.claims.submit` — `App\Http\Controllers\Operations\FundingClaimController@submit` — `app/Http/Controllers/Operations/FundingClaimController.php:145` — middleware `web, auth, permission:funding.claims.submit`
- `GET|HEAD operations/funding/claims/create` — `operations.funding.claims.create` — `App\Http\Controllers\Operations\FundingClaimController@create` — `app/Http/Controllers/Operations/FundingClaimController.php:50` — middleware `web, auth, permission:funding.claims.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/FundingClaimController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/funding/claims/Create.tsx`, `resources/js/pages/operations/funding/claims/Index.tsx`, `resources/js/pages/operations/funding/claims/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
