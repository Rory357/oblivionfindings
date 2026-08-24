# HR-STAFF-CREDENTIAL: Staff Credential

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:staff.credentials.viewAny|staff.viewAny|staff.credentials.updateSelf`, `permission:staff.credentials.updateAny|staff.update|staff.credentials.updateSelf`
- Owning module: Human resources
- Legacy family: `HR-STAFF-CREDENTIAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `staff/{user}/credentials` (`staff.credentials.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:staff.credentials.viewAny|staff.viewAny|staff.credentials.updateSelf`, `permission:staff.credentials.updateAny|staff.update|staff.credentials.updateSelf`.
- Exact middleware atoms: `web`, `auth`, `permission:staff.credentials.viewAny|staff.viewAny|staff.credentials.updateSelf`, `permission:staff.credentials.updateAny|staff.update|staff.credentials.updateSelf`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD staff/{user}/credentials` (`staff.credentials.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST staff/{user}/credentials` (`staff.credentials.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/StaffCredentialController.php:51-73`; `type`.
3. Invoke only the owning control for `DELETE staff/{user}/credentials/{credential}` (`staff.credentials.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/StaffCredentialController.php:100-114`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT staff/{user}/credentials/{credential}` (`staff.credentials.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/StaffCredentialController.php:75-98`; `type`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2927` at `app/Http/Controllers/StaffCredentialController.php:28`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2928` at `app/Http/Controllers/StaffCredentialController.php:51`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2929` at `app/Http/Controllers/StaffCredentialController.php:100`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2930` at `app/Http/Controllers/StaffCredentialController.php:75`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/staff/credentials.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2928` / `store`: fields `type`; success app/Http/Controllers/StaffCredentialController.php:72 `return back()->with('success', 'Credential added.');`.
- `ROUTE-2929` / `destroy`: success app/Http/Controllers/StaffCredentialController.php:113 `return back()->with('success', 'Credential removed.');`.
- `ROUTE-2930` / `update`: fields `type`; success app/Http/Controllers/StaffCredentialController.php:97 `return back()->with('success', 'Credential updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/StaffCredentialController.php:64 `$credential = StaffCredential::create(array_merge($data, ['user_id' => $user->id]));`; app/Http/Controllers/StaffCredentialController.php:105 `$credential->delete();`; app/Http/Controllers/StaffCredentialController.php:89 `$credential->update($data);`; responses app/Http/Controllers/StaffCredentialController.php:44 `return inertia('staff/credentials', [`; app/Http/Controllers/StaffCredentialController.php:72 `return back()->with('success', 'Credential added.');`; app/Http/Controllers/StaffCredentialController.php:113 `return back()->with('success', 'Credential removed.');`; app/Http/Controllers/StaffCredentialController.php:97 `return back()->with('success', 'Credential updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD staff/{user}/credentials` — `staff.credentials.index` — `App\Http\Controllers\StaffCredentialController@index` — `app/Http/Controllers/StaffCredentialController.php:28` — middleware `web, auth, permission:staff.credentials.viewAny|staff.viewAny|staff.credentials.updateSelf`
- `POST staff/{user}/credentials` — `staff.credentials.store` — `App\Http\Controllers\StaffCredentialController@store` — `app/Http/Controllers/StaffCredentialController.php:51` — middleware `web, auth, permission:staff.credentials.updateAny|staff.update|staff.credentials.updateSelf`
- `DELETE staff/{user}/credentials/{credential}` — `staff.credentials.destroy` — `App\Http\Controllers\StaffCredentialController@destroy` — `app/Http/Controllers/StaffCredentialController.php:100` — middleware `web, auth, permission:staff.credentials.updateAny|staff.update|staff.credentials.updateSelf`
- `PUT staff/{user}/credentials/{credential}` — `staff.credentials.update` — `App\Http\Controllers\StaffCredentialController@update` — `app/Http/Controllers/StaffCredentialController.php:75` — middleware `web, auth, permission:staff.credentials.updateAny|staff.update|staff.credentials.updateSelf`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/StaffCredentialController.php`.
- Exact render/action page relationships: `resources/js/pages/staff/credentials.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
