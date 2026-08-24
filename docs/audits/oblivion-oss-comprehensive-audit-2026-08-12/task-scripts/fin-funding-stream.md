# FIN-FUNDING-STREAM: Funding Stream

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.admin`
- Owning module: Finance and funding
- Legacy family: `FIN-FUNDING-STREAM`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/funding-streams` (`finance.funding-streams.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.admin`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.admin`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/funding-streams` (`finance.funding-streams.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST finance/funding-streams` (`finance.funding-streams.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/FundingStreamController.php:52-82`; `code`, `name`, `funder_type`, `contact_name`, `contact_email`, `default_revenue_account_id`, `is_active`.
3. Invoke only the owning control for `DELETE finance/funding-streams/{fundingStream}` (`finance.funding-streams.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Domain/Finance/Http/Controllers/FundingStreamController.php:112-118`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT finance/funding-streams/{fundingStream}` (`finance.funding-streams.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/FundingStreamController.php:84-110`; `code`, `name`, `funder_type`, `contact_name`, `contact_email`, `default_revenue_account_id`, `is_active`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0573` at `app/Domain/Finance/Http/Controllers/FundingStreamController.php:13`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0574` at `app/Domain/Finance/Http/Controllers/FundingStreamController.php:52`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0575` at `app/Domain/Finance/Http/Controllers/FundingStreamController.php:112`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0576` at `app/Domain/Finance/Http/Controllers/FundingStreamController.php:84`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/funding-streams/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0574` / `store`: fields `code`, `name`, `funder_type`, `contact_name`, `contact_email`, `default_revenue_account_id`, `is_active`; success app/Domain/Finance/Http/Controllers/FundingStreamController.php:81 `->with('success', 'Funding stream created successfully.');`; failure app/Domain/Finance/Http/Controllers/FundingStreamController.php:72 `return back()->withErrors(['code' => 'A funding stream with this code already exists.']);`.
- `ROUTE-0575` / `destroy`: success app/Domain/Finance/Http/Controllers/FundingStreamController.php:117 `->with('success', 'Funding stream deleted successfully.');`.
- `ROUTE-0576` / `update`: fields `code`, `name`, `funder_type`, `contact_name`, `contact_email`, `default_revenue_account_id`, `is_active`; success app/Domain/Finance/Http/Controllers/FundingStreamController.php:109 `->with('success', 'Funding stream updated successfully.');`; failure app/Domain/Finance/Http/Controllers/FundingStreamController.php:103 `return back()->withErrors(['code' => 'A funding stream with this code already exists.']);`.

## Failure and recovery paths

- `store`: app/Domain/Finance/Http/Controllers/FundingStreamController.php:72 `return back()->withErrors(['code' => 'A funding stream with this code already exists.']);`.
- `update`: app/Domain/Finance/Http/Controllers/FundingStreamController.php:103 `return back()->withErrors(['code' => 'A funding stream with this code already exists.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/FundingStreamController.php:75 `FinFundingStream::create(array_merge($validated, [`; app/Domain/Finance/Http/Controllers/FundingStreamController.php:114 `$fundingStream->delete();`; app/Domain/Finance/Http/Controllers/FundingStreamController.php:106 `$fundingStream->update($validated);`; responses app/Domain/Finance/Http/Controllers/FundingStreamController.php:43 `return Inertia::render('finance/funding-streams/Index', [`; app/Domain/Finance/Http/Controllers/FundingStreamController.php:72 `return back()->withErrors(['code' => 'A funding stream with this code already exists.']);`; app/Domain/Finance/Http/Controllers/FundingStreamController.php:80 `return redirect()->route('finance.funding-streams.index')`; app/Domain/Finance/Http/Controllers/FundingStreamController.php:116 `return redirect()->route('finance.funding-streams.index')`; app/Domain/Finance/Http/Controllers/FundingStreamController.php:103 `return back()->withErrors(['code' => 'A funding stream with this code already exists.']);`; app/Domain/Finance/Http/Controllers/FundingStreamController.php:108 `return redirect()->route('finance.funding-streams.index')`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/funding-streams` — `finance.funding-streams.index` — `App\Domain\Finance\Http\Controllers\FundingStreamController@index` — `app/Domain/Finance/Http/Controllers/FundingStreamController.php:13` — middleware `web, auth, permission:finance.admin`
- `POST finance/funding-streams` — `finance.funding-streams.store` — `App\Domain\Finance\Http\Controllers\FundingStreamController@store` — `app/Domain/Finance/Http/Controllers/FundingStreamController.php:52` — middleware `web, auth, permission:finance.admin`
- `DELETE finance/funding-streams/{fundingStream}` — `finance.funding-streams.destroy` — `App\Domain\Finance\Http\Controllers\FundingStreamController@destroy` — `app/Domain/Finance/Http/Controllers/FundingStreamController.php:112` — middleware `web, auth, permission:finance.admin`
- `PUT finance/funding-streams/{fundingStream}` — `finance.funding-streams.update` — `App\Domain\Finance\Http\Controllers\FundingStreamController@update` — `app/Domain/Finance/Http/Controllers/FundingStreamController.php:84` — middleware `web, auth, permission:finance.admin`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/FundingStreamController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/funding-streams/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
