# PRIV-DATA-BREACH: Data Breach

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:privacy.reportBreaches`
- Owning module: Privacy and compliance
- Legacy family: `PRIV-DATA-BREACH`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `privacy/breaches` (`privacy.breaches.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:privacy.reportBreaches`.
- Exact middleware atoms: `web`, `auth`, `permission:privacy.reportBreaches`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD privacy/breaches` (`privacy.breaches.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD privacy/breaches/{breach}` (`privacy.breaches.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/DataBreachController.php:112-121`.
3. Use `GET|HEAD privacy/breaches/create` (`privacy.breaches.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/DataBreachController.php:66-71`.
4. Invoke only the owning control for `POST privacy/breaches` (`privacy.breaches.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/DataBreachController.php:76-107`; `nature_of_breach`, `discovered_at`, `affected_data_categories`, `approximate_individuals_affected`, `likely_consequences`, `measures_taken`, `requires_authority_notification`, `requires_subject_notification`.
5. Invoke only the owning control for `PUT privacy/breaches/{breach}` (`privacy.breaches.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/DataBreachController.php:126-144`; `nature_of_breach`, `affected_data_categories`, `approximate_individuals_affected`, `likely_consequences`, `measures_taken`, `requires_authority_notification`, `requires_subject_notification`.
6. Invoke only the owning control for `POST privacy/breaches/{breach}/notify-opc` (`privacy.breaches.notify-opc`, action `notifyOPC`). Source category: **mutation outcome source gap (notifyOPC)**; controller `app/Http/Controllers/DataBreachController.php:149-171`; `authority_reference`.
7. Invoke only the owning control for `POST privacy/breaches/{breach}/notify-subjects` (`privacy.breaches.notify-subjects`, action `notifySubjects`). Source category: **mutation outcome source gap (notifySubjects)**; controller `app/Http/Controllers/DataBreachController.php:176-198`; `notification_method`.
8. Invoke only the owning control for `POST privacy/breaches/{breach}/resolve` (`privacy.breaches.resolve`, action `resolve`). Source category: **completed/closed/released**; controller `app/Http/Controllers/DataBreachController.php:224-239`; `resolution_notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2297` at `app/Http/Controllers/DataBreachController.php:20`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2298` at `app/Http/Controllers/DataBreachController.php:76`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2299` at `app/Http/Controllers/DataBreachController.php:112`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2300` at `app/Http/Controllers/DataBreachController.php:126`; it is not runtime-observed.
- **mutation outcome source gap (notifyOPC)** is applicable only to `notifyOPC` / `ROUTE-2301` at `app/Http/Controllers/DataBreachController.php:149`; it is not runtime-observed.
- **mutation outcome source gap (notifySubjects)** is applicable only to `notifySubjects` / `ROUTE-2302` at `app/Http/Controllers/DataBreachController.php:176`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `resolve` / `ROUTE-2303` at `app/Http/Controllers/DataBreachController.php:224`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2304` at `app/Http/Controllers/DataBreachController.php:66`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/privacy/breaches/show.tsx`, `resources/js/pages/privacy/breaches.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2298` / `store`: fields `nature_of_breach`, `discovered_at`, `affected_data_categories`, `approximate_individuals_affected`, `likely_consequences`, `measures_taken`, `requires_authority_notification`, `requires_subject_notification`; success app/Http/Controllers/DataBreachController.php:101 `return back()->with('success', $message);`; app/Http/Controllers/DataBreachController.php:106 `->with('success', $message);`.
- `ROUTE-2300` / `update`: fields `nature_of_breach`, `affected_data_categories`, `approximate_individuals_affected`, `likely_consequences`, `measures_taken`, `requires_authority_notification`, `requires_subject_notification`; success app/Http/Controllers/DataBreachController.php:143 `return back()->with('success', 'Breach record updated.');`.
- `ROUTE-2301` / `notifyOPC`: fields `authority_reference`; success app/Http/Controllers/DataBreachController.php:170 `return back()->with('success', 'OPC notification recorded and the privacy team notified.');`.
- `ROUTE-2302` / `notifySubjects`: fields `notification_method`; success app/Http/Controllers/DataBreachController.php:197 `return back()->with('success', 'Subject notification recorded and the privacy team notified.');`.
- `ROUTE-2303` / `resolve`: fields `resolution_notes`; success app/Http/Controllers/DataBreachController.php:238 `return back()->with('success', 'Breach marked as resolved.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/DataBreachController.php:96 `$breach = DataBreachLog::create($validated);`; app/Http/Controllers/DataBreachController.php:141 `$breach->update($validated);`; app/Http/Controllers/DataBreachController.php:166 `$breach->update($attributes);`; app/Http/Controllers/DataBreachController.php:193 `$breach->update($attributes);`; app/Http/Controllers/DataBreachController.php:232 `$breach->update([`; responses app/Http/Controllers/DataBreachController.php:47 `return Inertia::render('privacy/breaches', [`; app/Http/Controllers/DataBreachController.php:101 `return back()->with('success', $message);`; app/Http/Controllers/DataBreachController.php:104 `return redirect()`; app/Http/Controllers/DataBreachController.php:118 `return Inertia::render('privacy/breaches/show', [`; app/Http/Controllers/DataBreachController.php:143 `return back()->with('success', 'Breach record updated.');`; app/Http/Controllers/DataBreachController.php:170 `return back()->with('success', 'OPC notification recorded and the privacy team notified.');`; app/Http/Controllers/DataBreachController.php:197 `return back()->with('success', 'Subject notification recorded and the privacy team notified.');`; app/Http/Controllers/DataBreachController.php:238 `return back()->with('success', 'Breach marked as resolved.');`; app/Http/Controllers/DataBreachController.php:70 `return redirect('/privacy/dashboard?new=breach');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD privacy/breaches` — `privacy.breaches.index` — `App\Http\Controllers\DataBreachController@index` — `app/Http/Controllers/DataBreachController.php:20` — middleware `web, auth, permission:privacy.reportBreaches`
- `POST privacy/breaches` — `privacy.breaches.store` — `App\Http\Controllers\DataBreachController@store` — `app/Http/Controllers/DataBreachController.php:76` — middleware `web, auth, permission:privacy.reportBreaches`
- `GET|HEAD privacy/breaches/{breach}` — `privacy.breaches.show` — `App\Http\Controllers\DataBreachController@show` — `app/Http/Controllers/DataBreachController.php:112` — middleware `web, auth, permission:privacy.reportBreaches`
- `PUT privacy/breaches/{breach}` — `privacy.breaches.update` — `App\Http\Controllers\DataBreachController@update` — `app/Http/Controllers/DataBreachController.php:126` — middleware `web, auth, permission:privacy.reportBreaches`
- `POST privacy/breaches/{breach}/notify-opc` — `privacy.breaches.notify-opc` — `App\Http\Controllers\DataBreachController@notifyOPC` — `app/Http/Controllers/DataBreachController.php:149` — middleware `web, auth, permission:privacy.reportBreaches`
- `POST privacy/breaches/{breach}/notify-subjects` — `privacy.breaches.notify-subjects` — `App\Http\Controllers\DataBreachController@notifySubjects` — `app/Http/Controllers/DataBreachController.php:176` — middleware `web, auth, permission:privacy.reportBreaches`
- `POST privacy/breaches/{breach}/resolve` — `privacy.breaches.resolve` — `App\Http\Controllers\DataBreachController@resolve` — `app/Http/Controllers/DataBreachController.php:224` — middleware `web, auth, permission:privacy.reportBreaches`
- `GET|HEAD privacy/breaches/create` — `privacy.breaches.create` — `App\Http\Controllers\DataBreachController@create` — `app/Http/Controllers/DataBreachController.php:66` — middleware `web, auth, permission:privacy.reportBreaches`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/DataBreachController.php`.
- Exact render/action page relationships: `resources/js/pages/privacy/breaches/show.tsx`, `resources/js/pages/privacy/breaches.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
