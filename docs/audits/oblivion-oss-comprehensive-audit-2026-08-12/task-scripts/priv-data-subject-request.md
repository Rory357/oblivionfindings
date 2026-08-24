# PRIV-DATA-SUBJECT-REQUEST: Data Subject Request

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:privacy.viewRequests`, `permission:privacy.processRequests`
- Owning module: Privacy and compliance
- Legacy family: `PRIV-DATA-SUBJECT-REQUEST`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `privacy/requests` (`privacy.requests.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:privacy.viewRequests`, `permission:privacy.processRequests`.
- Exact middleware atoms: `web`, `auth`, `permission:privacy.viewRequests`, `permission:privacy.processRequests`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD privacy/requests` (`privacy.requests.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD privacy/requests/{dsRequest}` (`privacy.requests.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/DataSubjectRequestController.php:119-135`.
3. Use `GET|HEAD privacy/requests/{dsRequest}/export` (`privacy.requests.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/DataSubjectRequestController.php:253-385`.
4. Use `GET|HEAD privacy/requests/create` (`privacy.requests.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/DataSubjectRequestController.php:70-76`.
5. Invoke only the owning control for `POST privacy/requests` (`privacy.requests.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/DataSubjectRequestController.php:81-114`; `request_type`, `subject_name`, `subject_email`, `request_details`, `specific_data_requested`, `assigned_to_user_id`, `client_id`, `received_at`, `verification_method`.
6. Invoke only the owning control for `PUT privacy/requests/{dsRequest}` (`privacy.requests.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/DataSubjectRequestController.php:140-159`; FormRequest `app/Models/DataSubjectRequest.php:line unresolved`; `assigned_to_user_id`, `completion_notes`.
7. Invoke only the owning control for `POST privacy/requests/{dsRequest}/complete` (`privacy.requests.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/DataSubjectRequestController.php:209-226`; FormRequest `app/Models/DataSubjectRequest.php:line unresolved`; `completion_notes`.
8. Invoke only the owning control for `POST privacy/requests/{dsRequest}/extend` (`privacy.requests.extend`, action `extend`). Source category: **mutation outcome source gap (extend)**; controller `app/Http/Controllers/DataSubjectRequestController.php:187-204`; FormRequest `app/Models/DataSubjectRequest.php:line unresolved`; `extension_reason`, `extended_due_date`.
9. Invoke only the owning control for `POST privacy/requests/{dsRequest}/refuse` (`privacy.requests.refuse`, action `refuse`). Source category: **mutation outcome source gap (refuse)**; controller `app/Http/Controllers/DataSubjectRequestController.php:231-248`; FormRequest `app/Models/DataSubjectRequest.php:line unresolved`; `rejection_reason`, `rejection_legal_basis`.
10. Invoke only the owning control for `POST privacy/requests/{dsRequest}/verify-identity` (`privacy.requests.verify-identity`, action `verifyIdentity`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/DataSubjectRequestController.php:164-182`; FormRequest `app/Models/DataSubjectRequest.php:line unresolved`; `verification_method`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2325` at `app/Http/Controllers/DataSubjectRequestController.php:22`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2326` at `app/Http/Controllers/DataSubjectRequestController.php:81`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2327` at `app/Http/Controllers/DataSubjectRequestController.php:119`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2328` at `app/Http/Controllers/DataSubjectRequestController.php:140`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-2329` at `app/Http/Controllers/DataSubjectRequestController.php:209`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-2330` at `app/Http/Controllers/DataSubjectRequestController.php:253`; it is not runtime-observed.
- **mutation outcome source gap (extend)** is applicable only to `extend` / `ROUTE-2331` at `app/Http/Controllers/DataSubjectRequestController.php:187`; it is not runtime-observed.
- **mutation outcome source gap (refuse)** is applicable only to `refuse` / `ROUTE-2332` at `app/Http/Controllers/DataSubjectRequestController.php:231`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `verifyIdentity` / `ROUTE-2333` at `app/Http/Controllers/DataSubjectRequestController.php:164`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2334` at `app/Http/Controllers/DataSubjectRequestController.php:70`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/privacy/requests/show.tsx`, `resources/js/pages/privacy/requests.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2325` / `index`: FormRequest `app/Models/DataSubjectRequest.php:line unresolved`.
- `ROUTE-2326` / `store`: fields `request_type`, `subject_name`, `subject_email`, `request_details`, `specific_data_requested`, `assigned_to_user_id`, `client_id`, `received_at`, `verification_method`; success app/Http/Controllers/DataSubjectRequestController.php:108 `return back()->with('success', $message);`; app/Http/Controllers/DataSubjectRequestController.php:113 `->with('success', $message);`.
- `ROUTE-2327` / `show`: FormRequest `app/Models/DataSubjectRequest.php:line unresolved`.
- `ROUTE-2328` / `update`: FormRequest `app/Models/DataSubjectRequest.php:line unresolved`; fields `assigned_to_user_id`, `completion_notes`; success app/Http/Controllers/DataSubjectRequestController.php:158 `return back()->with('success', 'Request updated successfully.');`.
- `ROUTE-2329` / `complete`: FormRequest `app/Models/DataSubjectRequest.php:line unresolved`; fields `completion_notes`; success app/Http/Controllers/DataSubjectRequestController.php:225 `return back()->with('success', 'Request marked as completed.');`.
- `ROUTE-2330` / `export`: FormRequest `app/Models/DataSubjectRequest.php:line unresolved`; success app/Http/Controllers/DataSubjectRequestController.php:384 `return back()->with('success', 'Data export generated successfully.');`.
- `ROUTE-2331` / `extend`: FormRequest `app/Models/DataSubjectRequest.php:line unresolved`; fields `extension_reason`, `extended_due_date`; success app/Http/Controllers/DataSubjectRequestController.php:203 `return back()->with('success', 'Deadline extended successfully.');`.
- `ROUTE-2332` / `refuse`: FormRequest `app/Models/DataSubjectRequest.php:line unresolved`; fields `rejection_reason`, `rejection_legal_basis`; success app/Http/Controllers/DataSubjectRequestController.php:247 `return back()->with('success', 'Request refused.');`.
- `ROUTE-2333` / `verifyIdentity`: FormRequest `app/Models/DataSubjectRequest.php:line unresolved`; fields `verification_method`; success app/Http/Controllers/DataSubjectRequestController.php:181 `return back()->with('success', 'Identity verified successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/DataSubjectRequestController.php:102 `$dsr = DataSubjectRequest::create($validated);`; app/Http/Controllers/DataSubjectRequestController.php:156 `$dsRequest->update($validated);`; app/Http/Controllers/DataSubjectRequestController.php:217 `$dsRequest->update([`; app/Http/Controllers/DataSubjectRequestController.php:196 `$dsRequest->update([`; app/Http/Controllers/DataSubjectRequestController.php:240 `$dsRequest->update([`; app/Http/Controllers/DataSubjectRequestController.php:172 `$dsRequest->update([`; responses app/Http/Controllers/DataSubjectRequestController.php:53 `return Inertia::render('privacy/requests', [`; app/Http/Controllers/DataSubjectRequestController.php:108 `return back()->with('success', $message);`; app/Http/Controllers/DataSubjectRequestController.php:111 `return redirect()`; app/Http/Controllers/DataSubjectRequestController.php:131 `return Inertia::render('privacy/requests/show', [`; app/Http/Controllers/DataSubjectRequestController.php:158 `return back()->with('success', 'Request updated successfully.');`; app/Http/Controllers/DataSubjectRequestController.php:225 `return back()->with('success', 'Request marked as completed.');`; app/Http/Controllers/DataSubjectRequestController.php:384 `return back()->with('success', 'Data export generated successfully.');`; app/Http/Controllers/DataSubjectRequestController.php:203 `return back()->with('success', 'Deadline extended successfully.');`; app/Http/Controllers/DataSubjectRequestController.php:247 `return back()->with('success', 'Request refused.');`; app/Http/Controllers/DataSubjectRequestController.php:181 `return back()->with('success', 'Identity verified successfully.');`; app/Http/Controllers/DataSubjectRequestController.php:75 `return redirect('/privacy/dashboard?new=request');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD privacy/requests` — `privacy.requests.index` — `App\Http\Controllers\DataSubjectRequestController@index` — `app/Http/Controllers/DataSubjectRequestController.php:22` — middleware `web, auth, permission:privacy.viewRequests`
- `POST privacy/requests` — `privacy.requests.store` — `App\Http\Controllers\DataSubjectRequestController@store` — `app/Http/Controllers/DataSubjectRequestController.php:81` — middleware `web, auth, permission:privacy.processRequests`
- `GET|HEAD privacy/requests/{dsRequest}` — `privacy.requests.show` — `App\Http\Controllers\DataSubjectRequestController@show` — `app/Http/Controllers/DataSubjectRequestController.php:119` — middleware `web, auth, permission:privacy.viewRequests`
- `PUT privacy/requests/{dsRequest}` — `privacy.requests.update` — `App\Http\Controllers\DataSubjectRequestController@update` — `app/Http/Controllers/DataSubjectRequestController.php:140` — middleware `web, auth, permission:privacy.processRequests`
- `POST privacy/requests/{dsRequest}/complete` — `privacy.requests.complete` — `App\Http\Controllers\DataSubjectRequestController@complete` — `app/Http/Controllers/DataSubjectRequestController.php:209` — middleware `web, auth, permission:privacy.processRequests`
- `GET|HEAD privacy/requests/{dsRequest}/export` — `privacy.requests.export` — `App\Http\Controllers\DataSubjectRequestController@export` — `app/Http/Controllers/DataSubjectRequestController.php:253` — middleware `web, auth, permission:privacy.viewRequests`
- `POST privacy/requests/{dsRequest}/extend` — `privacy.requests.extend` — `App\Http\Controllers\DataSubjectRequestController@extend` — `app/Http/Controllers/DataSubjectRequestController.php:187` — middleware `web, auth, permission:privacy.processRequests`
- `POST privacy/requests/{dsRequest}/refuse` — `privacy.requests.refuse` — `App\Http\Controllers\DataSubjectRequestController@refuse` — `app/Http/Controllers/DataSubjectRequestController.php:231` — middleware `web, auth, permission:privacy.processRequests`
- `POST privacy/requests/{dsRequest}/verify-identity` — `privacy.requests.verify-identity` — `App\Http\Controllers\DataSubjectRequestController@verifyIdentity` — `app/Http/Controllers/DataSubjectRequestController.php:164` — middleware `web, auth, permission:privacy.processRequests`
- `GET|HEAD privacy/requests/create` — `privacy.requests.create` — `App\Http\Controllers\DataSubjectRequestController@create` — `app/Http/Controllers/DataSubjectRequestController.php:70` — middleware `web, auth, permission:privacy.processRequests`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/DataSubjectRequestController.php`.
- Exact render/action page relationships: `resources/js/pages/privacy/requests/show.tsx`, `resources/js/pages/privacy/requests.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
