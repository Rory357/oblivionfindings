# CAP-OPS-SERVICE-AGREEMENT-STATE-CLOSURE: Service agreement state transition and deletion

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:service_agreements.delete`, `permission:service_agreements.update`
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

- Actor satisfying exact route middleware `auth`, `permission:service_agreements.delete`, `permission:service_agreements.update`.
- Exact middleware atoms: `web`, `auth`, `permission:service_agreements.delete`, `permission:service_agreements.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/service-agreements` (`operations.service_agreements.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `DELETE operations/service-agreements/{agreement}` (`operations.service_agreements.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/ServiceAgreementController.php:493-506`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE operations/service-agreements/{serviceAgreement}/line-items/{lineItem}` (`unnamed`, action `destroyLineItem`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/ServiceAgreementController.php:580-595`; no exact validation fields extracted.
4. Invoke only the owning control for `DELETE operations/service-agreements/{serviceAgreement}/rates/{rate}` (`unnamed`, action `destroyRate`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/ServiceAgreementController.php:630-645`; no exact validation fields extracted.
5. Invoke only the owning control for `POST operations/service-agreements/{serviceAgreement}/transition` (`operations.service_agreements.transition`, action `transition`). Source category: **mutation outcome source gap (transition)**; controller `app/Http/Controllers/Operations/ServiceAgreementController.php:346-392`; no exact validation fields extracted.

## Source-applicable states and transitions

- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2171` at `app/Http/Controllers/Operations/ServiceAgreementController.php:493`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyLineItem` / `ROUTE-2177` at `app/Http/Controllers/Operations/ServiceAgreementController.php:580`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyRate` / `ROUTE-2180` at `app/Http/Controllers/Operations/ServiceAgreementController.php:630`; it is not runtime-observed.
- **mutation outcome source gap (transition)** is applicable only to `transition` / `ROUTE-2183` at `app/Http/Controllers/Operations/ServiceAgreementController.php:346`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2171` / `destroy`: success app/Http/Controllers/Operations/ServiceAgreementController.php:505 `->with('success', 'Service agreement deleted.');`.
- `ROUTE-2177` / `destroyLineItem`: success app/Http/Controllers/Operations/ServiceAgreementController.php:594 `return redirect()->back()->with('success', 'Line item deleted.');`.
- `ROUTE-2180` / `destroyRate`: success app/Http/Controllers/Operations/ServiceAgreementController.php:644 `return redirect()->back()->with('success', 'Rate deleted.');`.
- `ROUTE-2183` / `transition`: success app/Http/Controllers/Operations/ServiceAgreementController.php:391 `return redirect()->back()->with('success', "Agreement status changed to {$data['status']}.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/ServiceAgreementController.php:502 `$agreement->delete();`; app/Http/Controllers/Operations/ServiceAgreementController.php:592 `$item->delete();`; app/Http/Controllers/Operations/ServiceAgreementController.php:642 `$rateModel->delete();`; app/Http/Controllers/Operations/ServiceAgreementController.php:364 `ServiceAgreementStatusChange::create([`; app/Http/Controllers/Operations/ServiceAgreementController.php:387 `$agreement->update($updates);`; responses app/Http/Controllers/Operations/ServiceAgreementController.php:504 `return redirect()->route('operations.service_agreements.index')`; app/Http/Controllers/Operations/ServiceAgreementController.php:594 `return redirect()->back()->with('success', 'Line item deleted.');`; app/Http/Controllers/Operations/ServiceAgreementController.php:644 `return redirect()->back()->with('success', 'Rate deleted.');`; app/Http/Controllers/Operations/ServiceAgreementController.php:391 `return redirect()->back()->with('success', "Agreement status changed to {$data['status']}.");`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `DELETE operations/service-agreements/{agreement}` — `operations.service_agreements.destroy` — `App\Http\Controllers\Operations\ServiceAgreementController@destroy` — `app/Http/Controllers/Operations/ServiceAgreementController.php:493` — middleware `web, auth, permission:service_agreements.delete`
- `DELETE operations/service-agreements/{serviceAgreement}/line-items/{lineItem}` — `unnamed` — `App\Http\Controllers\Operations\ServiceAgreementController@destroyLineItem` — `app/Http/Controllers/Operations/ServiceAgreementController.php:580` — middleware `web, auth, permission:service_agreements.update`
- `DELETE operations/service-agreements/{serviceAgreement}/rates/{rate}` — `unnamed` — `App\Http\Controllers\Operations\ServiceAgreementController@destroyRate` — `app/Http/Controllers/Operations/ServiceAgreementController.php:630` — middleware `web, auth, permission:service_agreements.update`
- `POST operations/service-agreements/{serviceAgreement}/transition` — `operations.service_agreements.transition` — `App\Http\Controllers\Operations\ServiceAgreementController@transition` — `app/Http/Controllers/Operations/ServiceAgreementController.php:346` — middleware `web, auth, permission:service_agreements.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/ServiceAgreementController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
