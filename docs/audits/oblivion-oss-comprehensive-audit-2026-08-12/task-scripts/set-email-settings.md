# SET-EMAIL-SETTINGS: Email Settings

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`
- Owning module: Settings and system access
- Legacy family: `SET-EMAIL-SETTINGS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/email` (`settings.email`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:settings.access.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/email` (`settings.email`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT settings/email` (`settings.email.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/EmailSettingsController.php:30-59`; no exact validation fields extracted.
3. Invoke only the owning control for `POST settings/email/test` (`settings.email.test`, action `test`). Source category: **mutation outcome source gap (test)**; controller `app/Http/Controllers/Settings/EmailSettingsController.php:61-102`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2650` at `app/Http/Controllers/Settings/EmailSettingsController.php:19`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2651` at `app/Http/Controllers/Settings/EmailSettingsController.php:30`; it is not runtime-observed.
- **mutation outcome source gap (test)** is applicable only to `test` / `ROUTE-2652` at `app/Http/Controllers/Settings/EmailSettingsController.php:61`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/email-settings.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2651` / `update`: success app/Http/Controllers/Settings/EmailSettingsController.php:58 `return back()->with('success', 'Email settings updated.');`.
- `ROUTE-2652` / `test`: success app/Http/Controllers/Settings/EmailSettingsController.php:72 `return back()->with('success', 'Test email sent to '.$request->user()->email.'.');`; app/Http/Controllers/Settings/EmailSettingsController.php:92 `? back()->with('success', 'Test email sent to '.$request->user()->email.'.')`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/EmailSettingsController.php:46 `AppSetting::updateOrCreate(`; app/Http/Controllers/Settings/EmailSettingsController.php:52 `AppSetting::updateOrCreate(`; responses app/Http/Controllers/Settings/EmailSettingsController.php:23 `return inertia('settings/email-settings', [`; app/Http/Controllers/Settings/EmailSettingsController.php:58 `return back()->with('success', 'Email settings updated.');`; app/Http/Controllers/Settings/EmailSettingsController.php:72 `return back()->with('success', 'Test email sent to '.$request->user()->email.'.');`; app/Http/Controllers/Settings/EmailSettingsController.php:82 `return back()->with('error', 'Connect a Microsoft account before sending a test email.');`; app/Http/Controllers/Settings/EmailSettingsController.php:91 `return $sent`; app/Http/Controllers/Settings/EmailSettingsController.php:96 `return back()->with('warning', 'Test email is not available for Google Workspace yet.');`; app/Http/Controllers/Settings/EmailSettingsController.php:100 `return back()->with('error', 'Test email failed: '.$exception->getMessage());`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/email` — `settings.email` — `App\Http\Controllers\Settings\EmailSettingsController@index` — `app/Http/Controllers/Settings/EmailSettingsController.php:19` — middleware `web, auth, permission:settings.access.manage`
- `PUT settings/email` — `settings.email.update` — `App\Http\Controllers\Settings\EmailSettingsController@update` — `app/Http/Controllers/Settings/EmailSettingsController.php:30` — middleware `web, auth, permission:settings.access.manage`
- `POST settings/email/test` — `settings.email.test` — `App\Http\Controllers\Settings\EmailSettingsController@test` — `app/Http/Controllers/Settings/EmailSettingsController.php:61` — middleware `web, auth, permission:settings.access.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/EmailSettingsController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/email-settings.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
