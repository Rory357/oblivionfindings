# HR-VETTING: Vetting

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.vetting.view`, `permission:hr.vetting.manage`
- Owning module: Human resources
- Legacy family: `HR-VETTING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/compliance/vetting` (`hr.vetting.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.vetting.view`, `permission:hr.vetting.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.vetting.view`, `permission:hr.vetting.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/compliance/vetting` (`hr.vetting.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/compliance/vetting/{check}` (`hr.vetting.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/VettingController.php:86-106`.
3. Use `GET|HEAD hr/compliance/vetting/{check}/edit` (`hr.vetting.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/VettingController.php:143-185`.
4. Use `GET|HEAD hr/compliance/vetting/create` (`hr.vetting.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/VettingController.php:112-137`.
5. Invoke only the owning control for `POST hr/compliance/vetting` (`hr.vetting.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/VettingController.php:191-225`; `user_id`.
6. Invoke only the owning control for `DELETE hr/compliance/vetting/{check}` (`hr.vetting.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/VettingController.php:282-292`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT hr/compliance/vetting/{check}` (`hr.vetting.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/VettingController.php:231-276`; no exact validation fields extracted.
8. Invoke only the owning control for `POST hr/compliance/vetting/{check}/clear` (`hr.vetting.clear`, action `clear`). Source category: **mutation outcome source gap (clear)**; controller `app/Http/Controllers/Hr/VettingController.php:298-313`; no exact validation fields extracted.
9. Invoke only the owning control for `POST hr/compliance/vetting/{check}/consent` (`hr.vetting.consent`, action `captureConsent`). Source category: **mutation outcome source gap (captureConsent)**; controller `app/Http/Controllers/Hr/VettingController.php:338-357`; `consent_given`.
10. Invoke only the owning control for `POST hr/compliance/vetting/{check}/renew` (`hr.vetting.renew`, action `renew`). Source category: **mutation outcome source gap (renew)**; controller `app/Http/Controllers/Hr/VettingController.php:319-332`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1377` at `app/Http/Controllers/Hr/VettingController.php:26`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1378` at `app/Http/Controllers/Hr/VettingController.php:191`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1379` at `app/Http/Controllers/Hr/VettingController.php:282`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1380` at `app/Http/Controllers/Hr/VettingController.php:86`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1381` at `app/Http/Controllers/Hr/VettingController.php:231`; it is not runtime-observed.
- **mutation outcome source gap (clear)** is applicable only to `clear` / `ROUTE-1382` at `app/Http/Controllers/Hr/VettingController.php:298`; it is not runtime-observed.
- **mutation outcome source gap (captureConsent)** is applicable only to `captureConsent` / `ROUTE-1383` at `app/Http/Controllers/Hr/VettingController.php:338`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-1384` at `app/Http/Controllers/Hr/VettingController.php:143`; it is not runtime-observed.
- **mutation outcome source gap (renew)** is applicable only to `renew` / `ROUTE-1385` at `app/Http/Controllers/Hr/VettingController.php:319`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1386` at `app/Http/Controllers/Hr/VettingController.php:112`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/vetting/create.tsx`, `resources/js/pages/hr/vetting/edit.tsx`, `resources/js/pages/hr/vetting/index.tsx`, `resources/js/pages/hr/vetting/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1378` / `store`: fields `user_id`; success app/Http/Controllers/Hr/VettingController.php:224 `return redirect()->back()->with('success', 'Background check initiated.');`.
- `ROUTE-1379` / `destroy`: success app/Http/Controllers/Hr/VettingController.php:291 `return redirect()->route('hr.vetting.index')->with('success', 'Background check deleted.');`.
- `ROUTE-1381` / `update`: success app/Http/Controllers/Hr/VettingController.php:275 `return redirect()->back()->with('success', 'Background check updated.');`.
- `ROUTE-1382` / `clear`: success app/Http/Controllers/Hr/VettingController.php:312 `return redirect()->back()->with('success', 'Background check marked as clear.');`.
- `ROUTE-1383` / `captureConsent`: fields `consent_given`; success app/Http/Controllers/Hr/VettingController.php:356 `return redirect()->back()->with('success', 'Consent recorded successfully.');`.
- `ROUTE-1385` / `renew`: success app/Http/Controllers/Hr/VettingController.php:331 `return redirect()->back()->with('success', 'Background check marked for renewal.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/VettingController.php:218 `StaffBackgroundCheck::create([`; app/Http/Controllers/Hr/VettingController.php:289 `$check->delete();`; app/Http/Controllers/Hr/VettingController.php:273 `$check->update($validated);`; app/Http/Controllers/Hr/VettingController.php:305 `$check->update([`; app/Http/Controllers/Hr/VettingController.php:350 `$check->update([`; app/Http/Controllers/Hr/VettingController.php:326 `$check->update([`; responses app/Http/Controllers/Hr/VettingController.php:67 `return Inertia::render('hr/vetting/index', [`; app/Http/Controllers/Hr/VettingController.php:215 `return redirect()->back()->with('error', 'Selected staff member is not in your HR tenant scope.');`; app/Http/Controllers/Hr/VettingController.php:224 `return redirect()->back()->with('success', 'Background check initiated.');`; app/Http/Controllers/Hr/VettingController.php:291 `return redirect()->route('hr.vetting.index')->with('success', 'Background check deleted.');`; app/Http/Controllers/Hr/VettingController.php:100 `return Inertia::render('hr/vetting/show', [`; app/Http/Controllers/Hr/VettingController.php:275 `return redirect()->back()->with('success', 'Background check updated.');`; app/Http/Controllers/Hr/VettingController.php:312 `return redirect()->back()->with('success', 'Background check marked as clear.');`; app/Http/Controllers/Hr/VettingController.php:356 `return redirect()->back()->with('success', 'Consent recorded successfully.');`; app/Http/Controllers/Hr/VettingController.php:158 `return Inertia::render('hr/vetting/edit', [`; app/Http/Controllers/Hr/VettingController.php:331 `return redirect()->back()->with('success', 'Background check marked for renewal.');`; app/Http/Controllers/Hr/VettingController.php:124 `return Inertia::render('hr/vetting/create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/compliance/vetting` — `hr.vetting.index` — `App\Http\Controllers\Hr\VettingController@index` — `app/Http/Controllers/Hr/VettingController.php:26` — middleware `web, auth, permission:hr.vetting.view`
- `POST hr/compliance/vetting` — `hr.vetting.store` — `App\Http\Controllers\Hr\VettingController@store` — `app/Http/Controllers/Hr/VettingController.php:191` — middleware `web, auth, permission:hr.vetting.view, permission:hr.vetting.manage`
- `DELETE hr/compliance/vetting/{check}` — `hr.vetting.destroy` — `App\Http\Controllers\Hr\VettingController@destroy` — `app/Http/Controllers/Hr/VettingController.php:282` — middleware `web, auth, permission:hr.vetting.view, permission:hr.vetting.manage`
- `GET|HEAD hr/compliance/vetting/{check}` — `hr.vetting.show` — `App\Http\Controllers\Hr\VettingController@show` — `app/Http/Controllers/Hr/VettingController.php:86` — middleware `web, auth, permission:hr.vetting.view`
- `PUT hr/compliance/vetting/{check}` — `hr.vetting.update` — `App\Http\Controllers\Hr\VettingController@update` — `app/Http/Controllers/Hr/VettingController.php:231` — middleware `web, auth, permission:hr.vetting.view, permission:hr.vetting.manage`
- `POST hr/compliance/vetting/{check}/clear` — `hr.vetting.clear` — `App\Http\Controllers\Hr\VettingController@clear` — `app/Http/Controllers/Hr/VettingController.php:298` — middleware `web, auth, permission:hr.vetting.view, permission:hr.vetting.manage`
- `POST hr/compliance/vetting/{check}/consent` — `hr.vetting.consent` — `App\Http\Controllers\Hr\VettingController@captureConsent` — `app/Http/Controllers/Hr/VettingController.php:338` — middleware `web, auth, permission:hr.vetting.view, permission:hr.vetting.manage`
- `GET|HEAD hr/compliance/vetting/{check}/edit` — `hr.vetting.edit` — `App\Http\Controllers\Hr\VettingController@edit` — `app/Http/Controllers/Hr/VettingController.php:143` — middleware `web, auth, permission:hr.vetting.view, permission:hr.vetting.manage`
- `POST hr/compliance/vetting/{check}/renew` — `hr.vetting.renew` — `App\Http\Controllers\Hr\VettingController@renew` — `app/Http/Controllers/Hr/VettingController.php:319` — middleware `web, auth, permission:hr.vetting.view, permission:hr.vetting.manage`
- `GET|HEAD hr/compliance/vetting/create` — `hr.vetting.create` — `App\Http\Controllers\Hr\VettingController@create` — `app/Http/Controllers/Hr/VettingController.php:112` — middleware `web, auth, permission:hr.vetting.view, permission:hr.vetting.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/VettingController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/vetting/create.tsx`, `resources/js/pages/hr/vetting/edit.tsx`, `resources/js/pages/hr/vetting/index.tsx`, `resources/js/pages/hr/vetting/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
