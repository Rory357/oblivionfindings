# SET-PROFILE: Profile

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Settings and system access
- Legacy family: `SET-PROFILE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/profile` (`profile.edit`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/profile` (`profile.edit`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `DELETE settings/profile` (`profile.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Settings/ProfileController.php:285-301`; `password`.
3. Invoke only the owning control for `PATCH settings/profile` (`profile.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/ProfileController.php:119-163`; FormRequest `app/Http/Requests/Settings/ProfileUpdateRequest.php:41`; `name`, `email`, `phone`, `job_title`, `timezone`, `locale`, `en`, `date_format`, `time_format`.
4. Invoke only the owning control for `PUT settings/profile/landing` (`profile.landing.update`, action `updateLanding`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/ProfileController.php:84-114`; `landing_route_preference`.
5. Invoke only the owning control for `DELETE settings/profile/photo` (`profile.photo.destroy`, action `destroyPhoto`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Settings/ProfileController.php:218-230`; no exact validation fields extracted.
6. Invoke only the owning control for `POST settings/profile/photo` (`profile.photo.update`, action `updatePhoto`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/ProfileController.php:195-213`; `photo`.

## Source-applicable states and transitions

- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2670` at `app/Http/Controllers/Settings/ProfileController.php:285`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2671` at `app/Http/Controllers/Settings/ProfileController.php:24`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2672` at `app/Http/Controllers/Settings/ProfileController.php:119`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateLanding` / `ROUTE-2673` at `app/Http/Controllers/Settings/ProfileController.php:84`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyPhoto` / `ROUTE-2674` at `app/Http/Controllers/Settings/ProfileController.php:218`; it is not runtime-observed.
- **updated/revised** is applicable only to `updatePhoto` / `ROUTE-2675` at `app/Http/Controllers/Settings/ProfileController.php:195`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/profile.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2670` / `destroy`: fields `password`.
- `ROUTE-2672` / `update`: FormRequest `app/Http/Requests/Settings/ProfileUpdateRequest.php:41`; fields `name`, `email`, `phone`, `job_title`, `timezone`, `locale`, `en`, `date_format`, `time_format`.
- `ROUTE-2673` / `updateLanding`: fields `landing_route_preference`; success app/Http/Controllers/Settings/ProfileController.php:113 `return back()->with('success', 'Landing page preference updated.');`.
- `ROUTE-2674` / `destroyPhoto`: success app/Http/Controllers/Settings/ProfileController.php:229 `return back()->with('success', 'Profile photo removed.');`.
- `ROUTE-2675` / `updatePhoto`: fields `photo`; success app/Http/Controllers/Settings/ProfileController.php:212 `return back()->with('success', 'Profile photo updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/ProfileController.php:295 `$user->delete();`; app/Http/Controllers/Settings/ProfileController.php:144 `$user->save();`; app/Http/Controllers/Settings/ProfileController.php:111 `])->save();`; app/Http/Controllers/Settings/ProfileController.php:224 `Storage::disk('public')->delete($user->profile_photo_path);`; app/Http/Controllers/Settings/ProfileController.php:227 `$user->forceFill(['profile_photo_path' => null])->save();`; app/Http/Controllers/Settings/ProfileController.php:207 `Storage::disk('public')->delete($user->profile_photo_path);`; app/Http/Controllers/Settings/ProfileController.php:210 `$user->forceFill(['profile_photo_path' => $path])->save();`; responses app/Http/Controllers/Settings/ProfileController.php:300 `return redirect('/');`; app/Http/Controllers/Settings/ProfileController.php:52 `return Inertia::render('settings/profile', [`; app/Http/Controllers/Settings/ProfileController.php:162 `return to_route('profile.edit');`; app/Http/Controllers/Settings/ProfileController.php:113 `return back()->with('success', 'Landing page preference updated.');`; app/Http/Controllers/Settings/ProfileController.php:229 `return back()->with('success', 'Profile photo removed.');`; app/Http/Controllers/Settings/ProfileController.php:212 `return back()->with('success', 'Profile photo updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `DELETE settings/profile` — `profile.destroy` — `App\Http\Controllers\Settings\ProfileController@destroy` — `app/Http/Controllers/Settings/ProfileController.php:285` — middleware `web, auth`
- `GET|HEAD settings/profile` — `profile.edit` — `App\Http\Controllers\Settings\ProfileController@edit` — `app/Http/Controllers/Settings/ProfileController.php:24` — middleware `web, auth`
- `PATCH settings/profile` — `profile.update` — `App\Http\Controllers\Settings\ProfileController@update` — `app/Http/Controllers/Settings/ProfileController.php:119` — middleware `web, auth`
- `PUT settings/profile/landing` — `profile.landing.update` — `App\Http\Controllers\Settings\ProfileController@updateLanding` — `app/Http/Controllers/Settings/ProfileController.php:84` — middleware `web, auth`
- `DELETE settings/profile/photo` — `profile.photo.destroy` — `App\Http\Controllers\Settings\ProfileController@destroyPhoto` — `app/Http/Controllers/Settings/ProfileController.php:218` — middleware `web, auth`
- `POST settings/profile/photo` — `profile.photo.update` — `App\Http\Controllers\Settings\ProfileController@updatePhoto` — `app/Http/Controllers/Settings/ProfileController.php:195` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/ProfileController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/profile.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
