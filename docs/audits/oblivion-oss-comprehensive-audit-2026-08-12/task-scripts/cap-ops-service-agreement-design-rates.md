# CAP-OPS-SERVICE-AGREEMENT-DESIGN-RATES: Service agreement terms rates and line items

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:service_agreements.viewAny`, `permission:service_agreements.create`, `permission:service_agreements.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-SERVICE-AGREEMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/service-agreements` (`operations.service_agreements.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:service_agreements.viewAny`, `permission:service_agreements.create`, `permission:service_agreements.update`.
- Exact middleware atoms: `web`, `auth`, `permission:service_agreements.viewAny`, `permission:service_agreements.create`, `permission:service_agreements.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/service-agreements` (`operations.service_agreements.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/service-agreements/{agreement}` (`operations.service_agreements.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/ServiceAgreementController.php:202-265`.
3. Use `GET|HEAD operations/service-agreements/{agreement}/edit` (`operations.service_agreements.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/ServiceAgreementController.php:267-286`.
4. Use `GET|HEAD operations/service-agreements/create` (`operations.service_agreements.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/ServiceAgreementController.php:87-100`.
5. Invoke only the owning control for `POST operations/service-agreements` (`operations.service_agreements.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ServiceAgreementController.php:102-200`; `client_id`.
6. Invoke only the owning control for `PUT operations/service-agreements/{agreement}` (`operations.service_agreements.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/ServiceAgreementController.php:288-344`; `client_id`.
7. Invoke only the owning control for `POST operations/service-agreements/{serviceAgreement}/line-items` (`unnamed`, action `storeLineItem`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ServiceAgreementController.php:512-543`; `description`.
8. Invoke only the owning control for `PUT operations/service-agreements/{serviceAgreement}/line-items/{lineItem}` (`unnamed`, action `updateLineItem`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/ServiceAgreementController.php:545-578`; `description`.
9. Invoke only the owning control for `POST operations/service-agreements/{serviceAgreement}/rates` (`unnamed`, action `storeRate`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ServiceAgreementController.php:601-628`; `rate_type`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2169` at `app/Http/Controllers/Operations/ServiceAgreementController.php:16`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2170` at `app/Http/Controllers/Operations/ServiceAgreementController.php:102`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2172` at `app/Http/Controllers/Operations/ServiceAgreementController.php:202`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2173` at `app/Http/Controllers/Operations/ServiceAgreementController.php:288`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2174` at `app/Http/Controllers/Operations/ServiceAgreementController.php:267`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeLineItem` / `ROUTE-2176` at `app/Http/Controllers/Operations/ServiceAgreementController.php:512`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateLineItem` / `ROUTE-2178` at `app/Http/Controllers/Operations/ServiceAgreementController.php:545`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeRate` / `ROUTE-2179` at `app/Http/Controllers/Operations/ServiceAgreementController.php:601`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2184` at `app/Http/Controllers/Operations/ServiceAgreementController.php:87`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/service-agreements/Create.tsx`, `resources/js/pages/operations/service-agreements/Edit.tsx`, `resources/js/pages/operations/service-agreements/Index.tsx`, `resources/js/pages/operations/service-agreements/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2169` / `index`: fields `q`.
- `ROUTE-2170` / `store`: fields `client_id`; success app/Http/Controllers/Operations/ServiceAgreementController.php:199 `->with('success', 'Service agreement created.');`.
- `ROUTE-2173` / `update`: fields `client_id`; success app/Http/Controllers/Operations/ServiceAgreementController.php:343 `->with('success', 'Service agreement updated.');`.
- `ROUTE-2176` / `storeLineItem`: fields `description`; success app/Http/Controllers/Operations/ServiceAgreementController.php:542 `return redirect()->back()->with('success', 'Line item added.');`.
- `ROUTE-2178` / `updateLineItem`: fields `description`; success app/Http/Controllers/Operations/ServiceAgreementController.php:577 `return redirect()->back()->with('success', 'Line item updated.');`.
- `ROUTE-2179` / `storeRate`: fields `rate_type`; success app/Http/Controllers/Operations/ServiceAgreementController.php:627 `return redirect()->back()->with('success', 'Rate added.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/ServiceAgreementController.php:150 `$agreement = ServiceAgreement::create([`; app/Http/Controllers/Operations/ServiceAgreementController.php:340 `$agreement->update($data);`; app/Http/Controllers/Operations/ServiceAgreementController.php:531 `$agreement->lineItems()->create([`; app/Http/Controllers/Operations/ServiceAgreementController.php:567 `$item->update([`; app/Http/Controllers/Operations/ServiceAgreementController.php:618 `$agreement->rates()->create([`; responses app/Http/Controllers/Operations/ServiceAgreementController.php:71 `return $agreement;`; app/Http/Controllers/Operations/ServiceAgreementController.php:79 `return inertia('operations/service-agreements/Index', [`; app/Http/Controllers/Operations/ServiceAgreementController.php:198 `return redirect()->route('operations.service_agreements.show', $agreement)`; app/Http/Controllers/Operations/ServiceAgreementController.php:252 `return inertia('operations/service-agreements/Show', [`; app/Http/Controllers/Operations/ServiceAgreementController.php:342 `return redirect()->route('operations.service_agreements.show', $agreement)`; app/Http/Controllers/Operations/ServiceAgreementController.php:282 `return inertia('operations/service-agreements/Edit', [`; app/Http/Controllers/Operations/ServiceAgreementController.php:542 `return redirect()->back()->with('success', 'Line item added.');`; app/Http/Controllers/Operations/ServiceAgreementController.php:577 `return redirect()->back()->with('success', 'Line item updated.');`; app/Http/Controllers/Operations/ServiceAgreementController.php:627 `return redirect()->back()->with('success', 'Rate added.');`; app/Http/Controllers/Operations/ServiceAgreementController.php:97 `return inertia('operations/service-agreements/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/service-agreements` — `operations.service_agreements.index` — `App\Http\Controllers\Operations\ServiceAgreementController@index` — `app/Http/Controllers/Operations/ServiceAgreementController.php:16` — middleware `web, auth, permission:service_agreements.viewAny`
- `POST operations/service-agreements` — `operations.service_agreements.store` — `App\Http\Controllers\Operations\ServiceAgreementController@store` — `app/Http/Controllers/Operations/ServiceAgreementController.php:102` — middleware `web, auth, permission:service_agreements.create`
- `GET|HEAD operations/service-agreements/{agreement}` — `operations.service_agreements.show` — `App\Http\Controllers\Operations\ServiceAgreementController@show` — `app/Http/Controllers/Operations/ServiceAgreementController.php:202` — middleware `web, auth, permission:service_agreements.viewAny`
- `PUT operations/service-agreements/{agreement}` — `operations.service_agreements.update` — `App\Http\Controllers\Operations\ServiceAgreementController@update` — `app/Http/Controllers/Operations/ServiceAgreementController.php:288` — middleware `web, auth, permission:service_agreements.update`
- `GET|HEAD operations/service-agreements/{agreement}/edit` — `operations.service_agreements.edit` — `App\Http\Controllers\Operations\ServiceAgreementController@edit` — `app/Http/Controllers/Operations/ServiceAgreementController.php:267` — middleware `web, auth, permission:service_agreements.update`
- `POST operations/service-agreements/{serviceAgreement}/line-items` — `unnamed` — `App\Http\Controllers\Operations\ServiceAgreementController@storeLineItem` — `app/Http/Controllers/Operations/ServiceAgreementController.php:512` — middleware `web, auth, permission:service_agreements.update`
- `PUT operations/service-agreements/{serviceAgreement}/line-items/{lineItem}` — `unnamed` — `App\Http\Controllers\Operations\ServiceAgreementController@updateLineItem` — `app/Http/Controllers/Operations/ServiceAgreementController.php:545` — middleware `web, auth, permission:service_agreements.update`
- `POST operations/service-agreements/{serviceAgreement}/rates` — `unnamed` — `App\Http\Controllers\Operations\ServiceAgreementController@storeRate` — `app/Http/Controllers/Operations/ServiceAgreementController.php:601` — middleware `web, auth, permission:service_agreements.update`
- `GET|HEAD operations/service-agreements/create` — `operations.service_agreements.create` — `App\Http\Controllers\Operations\ServiceAgreementController@create` — `app/Http/Controllers/Operations/ServiceAgreementController.php:87` — middleware `web, auth, permission:service_agreements.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/ServiceAgreementController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/service-agreements/Create.tsx`, `resources/js/pages/operations/service-agreements/Edit.tsx`, `resources/js/pages/operations/service-agreements/Index.tsx`, `resources/js/pages/operations/service-agreements/Show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
