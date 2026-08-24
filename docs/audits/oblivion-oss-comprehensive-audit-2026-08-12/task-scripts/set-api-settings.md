# SET-API-SETTINGS: Api Settings

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:integrations.view`, `permission:integrations.manage_tenant_secrets`
- Owning module: Settings and system access
- Legacy family: `SET-API-SETTINGS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/api` (`settings.api`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:integrations.view`, `permission:integrations.manage_tenant_secrets`.
- Exact middleware atoms: `web`, `auth`, `permission:integrations.view`, `permission:integrations.manage_tenant_secrets`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/api` (`settings.api`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST settings/api/keys` (`settings.api.keys.store`, action `storeKey`). Source category: **created/recorded**; controller `app/Http/Controllers/Settings/ApiSettingsController.php:72-103`; `name`.
3. Invoke only the owning control for `POST settings/api/keys/{keyId}/revoke` (`settings.api.keys.revoke`, action `revokeKey`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Settings/ApiSettingsController.php:105-130`; no exact validation fields extracted.
4. Invoke only the owning control for `POST settings/api/webhooks` (`settings.api.webhooks.store`, action `storeWebhook`). Source category: **created/recorded**; controller `app/Http/Controllers/Settings/ApiSettingsController.php:132-161`; `url`.
5. Invoke only the owning control for `DELETE settings/api/webhooks/{webhookId}` (`settings.api.webhooks.destroy`, action `destroyWebhook`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Settings/ApiSettingsController.php:163-177`; no exact validation fields extracted.
6. Invoke only the owning control for `POST settings/api/webhooks/{webhookId}/test` (`settings.api.webhooks.test`, action `testWebhook`). Source category: **mutation outcome source gap (testWebhook)**; controller `app/Http/Controllers/Settings/ApiSettingsController.php:179-212`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2617` at `app/Http/Controllers/Settings/ApiSettingsController.php:48`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeKey` / `ROUTE-2618` at `app/Http/Controllers/Settings/ApiSettingsController.php:72`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `revokeKey` / `ROUTE-2619` at `app/Http/Controllers/Settings/ApiSettingsController.php:105`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeWebhook` / `ROUTE-2620` at `app/Http/Controllers/Settings/ApiSettingsController.php:132`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyWebhook` / `ROUTE-2621` at `app/Http/Controllers/Settings/ApiSettingsController.php:163`; it is not runtime-observed.
- **mutation outcome source gap (testWebhook)** is applicable only to `testWebhook` / `ROUTE-2622` at `app/Http/Controllers/Settings/ApiSettingsController.php:179`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/api.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2618` / `storeKey`: fields `name`.
- `ROUTE-2620` / `storeWebhook`: fields `url`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Settings/ApiSettingsController.php:55 `return Inertia::render('settings/api', [`; app/Http/Controllers/Settings/ApiSettingsController.php:98 `return response()->json([`; app/Http/Controllers/Settings/ApiSettingsController.php:115 `return $record;`; app/Http/Controllers/Settings/ApiSettingsController.php:126 `return response()->json([`; app/Http/Controllers/Settings/ApiSettingsController.php:156 `return response()->json([`; app/Http/Controllers/Settings/ApiSettingsController.php:174 `return response()->json([`; app/Http/Controllers/Settings/ApiSettingsController.php:199 `return response()->json([`; app/Http/Controllers/Settings/ApiSettingsController.php:208 `return response()->json([`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/api` — `settings.api` — `App\Http\Controllers\Settings\ApiSettingsController@index` — `app/Http/Controllers/Settings/ApiSettingsController.php:48` — middleware `web, auth, permission:integrations.view`
- `POST settings/api/keys` — `settings.api.keys.store` — `App\Http\Controllers\Settings\ApiSettingsController@storeKey` — `app/Http/Controllers/Settings/ApiSettingsController.php:72` — middleware `web, auth, permission:integrations.manage_tenant_secrets`
- `POST settings/api/keys/{keyId}/revoke` — `settings.api.keys.revoke` — `App\Http\Controllers\Settings\ApiSettingsController@revokeKey` — `app/Http/Controllers/Settings/ApiSettingsController.php:105` — middleware `web, auth, permission:integrations.manage_tenant_secrets`
- `POST settings/api/webhooks` — `settings.api.webhooks.store` — `App\Http\Controllers\Settings\ApiSettingsController@storeWebhook` — `app/Http/Controllers/Settings/ApiSettingsController.php:132` — middleware `web, auth, permission:integrations.manage_tenant_secrets`
- `DELETE settings/api/webhooks/{webhookId}` — `settings.api.webhooks.destroy` — `App\Http\Controllers\Settings\ApiSettingsController@destroyWebhook` — `app/Http/Controllers/Settings/ApiSettingsController.php:163` — middleware `web, auth, permission:integrations.manage_tenant_secrets`
- `POST settings/api/webhooks/{webhookId}/test` — `settings.api.webhooks.test` — `App\Http\Controllers\Settings\ApiSettingsController@testWebhook` — `app/Http/Controllers/Settings/ApiSettingsController.php:179` — middleware `web, auth, permission:integrations.manage_tenant_secrets`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/ApiSettingsController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/api.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
