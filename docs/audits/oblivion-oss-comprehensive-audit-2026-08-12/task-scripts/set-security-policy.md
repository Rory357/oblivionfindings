# SET-SECURITY-POLICY: Security Policy

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`
- Owning module: Settings and system access
- Legacy family: `SET-SECURITY-POLICY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/security` (`settings.security`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:settings.access.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/security` (`settings.security`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT settings/security` (`settings.security.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/SecurityPolicyController.php:38-74`; `password_min_length`.

## Source-applicable states and transitions

- **information presented** is applicable only to `edit` / `ROUTE-2681` at `app/Http/Controllers/Settings/SecurityPolicyController.php:15`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2682` at `app/Http/Controllers/Settings/SecurityPolicyController.php:38`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/security.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2682` / `update`: fields `password_min_length`; success app/Http/Controllers/Settings/SecurityPolicyController.php:73 `return back()->with('success', 'Security settings updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/SecurityPolicyController.php:65 `fn ($value, $key) => AppSetting::updateOrCreate(['key' => $key], ['value' => $value])`; responses app/Http/Controllers/Settings/SecurityPolicyController.php:17 `return Inertia::render('settings/security', [`; app/Http/Controllers/Settings/SecurityPolicyController.php:73 `return back()->with('success', 'Security settings updated.');`; audit calls app/Http/Controllers/Settings/SecurityPolicyController.php:68 `AuditLogger::log('settings.security.updated', null, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/security` — `settings.security` — `App\Http\Controllers\Settings\SecurityPolicyController@edit` — `app/Http/Controllers/Settings/SecurityPolicyController.php:15` — middleware `web, auth, permission:settings.access.manage`
- `PUT settings/security` — `settings.security.update` — `App\Http\Controllers\Settings\SecurityPolicyController@update` — `app/Http/Controllers/Settings/SecurityPolicyController.php:38` — middleware `web, auth, permission:settings.access.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/SecurityPolicyController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/security.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
