# PRIV-LEGAL-HOLD: Legal Hold

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:privacy.manageLegalHolds`
- Owning module: Privacy and compliance
- Legacy family: `PRIV-LEGAL-HOLD`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `privacy/legal-holds` (`privacy.legal-holds.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:privacy.manageLegalHolds`.
- Exact middleware atoms: `web`, `auth`, `permission:privacy.manageLegalHolds`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD privacy/legal-holds` (`privacy.legal-holds.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD privacy/legal-holds/{hold}/edit` (`privacy.legal-holds.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/LegalHoldController.php:106-113`.
3. Use `GET|HEAD privacy/legal-holds/create` (`privacy.legal-holds.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/LegalHoldController.php:56-61`.
4. Invoke only the owning control for `POST privacy/legal-holds` (`privacy.legal-holds.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/LegalHoldController.php:66-101`; `hold_type`, `reason`, `holdable_type`, `holdable_id`, `related_records`, `legal_authority`, `review_date`.
5. Invoke only the owning control for `PUT privacy/legal-holds/{hold}` (`privacy.legal-holds.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/LegalHoldController.php:118-132`; `reason`, `related_records`, `legal_authority`, `review_date`.
6. Invoke only the owning control for `POST privacy/legal-holds/{hold}/release` (`privacy.legal-holds.release`, action `release`). Source category: **completed/closed/released**; controller `app/Http/Controllers/LegalHoldController.php:137-153`; `release_reason`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2308` at `app/Http/Controllers/LegalHoldController.php:17`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2309` at `app/Http/Controllers/LegalHoldController.php:66`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2310` at `app/Http/Controllers/LegalHoldController.php:118`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2311` at `app/Http/Controllers/LegalHoldController.php:106`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `release` / `ROUTE-2312` at `app/Http/Controllers/LegalHoldController.php:137`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2313` at `app/Http/Controllers/LegalHoldController.php:56`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/privacy/legal-holds/edit.tsx`, `resources/js/pages/privacy/legal-holds.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2309` / `store`: fields `hold_type`, `reason`, `holdable_type`, `holdable_id`, `related_records`, `legal_authority`, `review_date`; success app/Http/Controllers/LegalHoldController.php:95 `return back()->with('success', $message);`; app/Http/Controllers/LegalHoldController.php:100 `->with('success', $message);`.
- `ROUTE-2310` / `update`: fields `reason`, `related_records`, `legal_authority`, `review_date`; success app/Http/Controllers/LegalHoldController.php:131 `return back()->with('success', 'Legal hold updated.');`.
- `ROUTE-2312` / `release`: fields `release_reason`; success app/Http/Controllers/LegalHoldController.php:152 `return back()->with('success', 'Legal hold released.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/LegalHoldController.php:90 `$hold = LegalHold::create($validated);`; app/Http/Controllers/LegalHoldController.php:129 `$hold->update($validated);`; app/Http/Controllers/LegalHoldController.php:145 `$hold->update([`; responses app/Http/Controllers/LegalHoldController.php:43 `return Inertia::render('privacy/legal-holds', [`; app/Http/Controllers/LegalHoldController.php:95 `return back()->with('success', $message);`; app/Http/Controllers/LegalHoldController.php:98 `return redirect()`; app/Http/Controllers/LegalHoldController.php:131 `return back()->with('success', 'Legal hold updated.');`; app/Http/Controllers/LegalHoldController.php:110 `return Inertia::render('privacy/legal-holds/edit', [`; app/Http/Controllers/LegalHoldController.php:152 `return back()->with('success', 'Legal hold released.');`; app/Http/Controllers/LegalHoldController.php:60 `return redirect('/privacy/dashboard?new=hold');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD privacy/legal-holds` — `privacy.legal-holds.index` — `App\Http\Controllers\LegalHoldController@index` — `app/Http/Controllers/LegalHoldController.php:17` — middleware `web, auth, permission:privacy.manageLegalHolds`
- `POST privacy/legal-holds` — `privacy.legal-holds.store` — `App\Http\Controllers\LegalHoldController@store` — `app/Http/Controllers/LegalHoldController.php:66` — middleware `web, auth, permission:privacy.manageLegalHolds`
- `PUT privacy/legal-holds/{hold}` — `privacy.legal-holds.update` — `App\Http\Controllers\LegalHoldController@update` — `app/Http/Controllers/LegalHoldController.php:118` — middleware `web, auth, permission:privacy.manageLegalHolds`
- `GET|HEAD privacy/legal-holds/{hold}/edit` — `privacy.legal-holds.edit` — `App\Http\Controllers\LegalHoldController@edit` — `app/Http/Controllers/LegalHoldController.php:106` — middleware `web, auth, permission:privacy.manageLegalHolds`
- `POST privacy/legal-holds/{hold}/release` — `privacy.legal-holds.release` — `App\Http\Controllers\LegalHoldController@release` — `app/Http/Controllers/LegalHoldController.php:137` — middleware `web, auth, permission:privacy.manageLegalHolds`
- `GET|HEAD privacy/legal-holds/create` — `privacy.legal-holds.create` — `App\Http\Controllers\LegalHoldController@create` — `app/Http/Controllers/LegalHoldController.php:56` — middleware `web, auth, permission:privacy.manageLegalHolds`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/LegalHoldController.php`.
- Exact render/action page relationships: `resources/js/pages/privacy/legal-holds/edit.tsx`, `resources/js/pages/privacy/legal-holds.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
