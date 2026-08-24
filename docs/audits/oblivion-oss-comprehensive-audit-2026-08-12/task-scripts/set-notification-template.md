# SET-NOTIFICATION-TEMPLATE: Notification Template

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:settings.templates.manage`
- Owning module: Settings and system access
- Legacy family: `SET-NOTIFICATION-TEMPLATE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/templates` (`settings.templates`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:settings.templates.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:settings.templates.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/templates` (`settings.templates`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT settings/templates/{template}` (`settings.templates.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/NotificationTemplateController.php:31-44`; `subject`, `body`, `is_active`.
3. Invoke only the owning control for `POST settings/templates/{template}/preview` (`settings.templates.preview`, action `preview`). Source category: **mutation outcome source gap (preview)**; controller `app/Http/Controllers/Settings/NotificationTemplateController.php:46-59`; no exact validation fields extracted.
4. Invoke only the owning control for `POST settings/templates/{template}/reset` (`settings.templates.reset`, action `reset`). Source category: **mutation outcome source gap (reset)**; controller `app/Http/Controllers/Settings/NotificationTemplateController.php:78-97`; no exact validation fields extracted.
5. Invoke only the owning control for `POST settings/templates/{template}/send-test` (`settings.templates.send-test`, action `sendTest`). Source category: **created/recorded**; controller `app/Http/Controllers/Settings/NotificationTemplateController.php:61-76`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2693` at `app/Http/Controllers/Settings/NotificationTemplateController.php:20`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2694` at `app/Http/Controllers/Settings/NotificationTemplateController.php:31`; it is not runtime-observed.
- **mutation outcome source gap (preview)** is applicable only to `preview` / `ROUTE-2695` at `app/Http/Controllers/Settings/NotificationTemplateController.php:46`; it is not runtime-observed.
- **mutation outcome source gap (reset)** is applicable only to `reset` / `ROUTE-2696` at `app/Http/Controllers/Settings/NotificationTemplateController.php:78`; it is not runtime-observed.
- **created/recorded** is applicable only to `sendTest` / `ROUTE-2697` at `app/Http/Controllers/Settings/NotificationTemplateController.php:61`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/templates.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2694` / `update`: fields `subject`, `body`, `is_active`; success app/Http/Controllers/Settings/NotificationTemplateController.php:43 `return redirect()->back()->with('success', 'Template updated successfully.');`.
- `ROUTE-2696` / `reset`: success app/Http/Controllers/Settings/NotificationTemplateController.php:96 `return redirect()->back()->with('success', 'Template reset to default.');`.
- `ROUTE-2697` / `sendTest`: success app/Http/Controllers/Settings/NotificationTemplateController.php:75 `return redirect()->back()->with('success', 'Test email sent to ' . $user->email);`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/NotificationTemplateController.php:41 `$template->update($validated);`; app/Http/Controllers/Settings/NotificationTemplateController.php:90 `$template->update([`; responses app/Http/Controllers/Settings/NotificationTemplateController.php:24 `return Inertia::render('settings/templates', [`; app/Http/Controllers/Settings/NotificationTemplateController.php:43 `return redirect()->back()->with('success', 'Template updated successfully.');`; app/Http/Controllers/Settings/NotificationTemplateController.php:55 `return response()->json([`; app/Http/Controllers/Settings/NotificationTemplateController.php:83 `return redirect()->back()->with('error', 'Only system templates can be reset.');`; app/Http/Controllers/Settings/NotificationTemplateController.php:96 `return redirect()->back()->with('success', 'Template reset to default.');`; app/Http/Controllers/Settings/NotificationTemplateController.php:75 `return redirect()->back()->with('success', 'Test email sent to ' . $user->email);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/templates` — `settings.templates` — `App\Http\Controllers\Settings\NotificationTemplateController@index` — `app/Http/Controllers/Settings/NotificationTemplateController.php:20` — middleware `web, auth, permission:settings.templates.manage`
- `PUT settings/templates/{template}` — `settings.templates.update` — `App\Http\Controllers\Settings\NotificationTemplateController@update` — `app/Http/Controllers/Settings/NotificationTemplateController.php:31` — middleware `web, auth, permission:settings.templates.manage`
- `POST settings/templates/{template}/preview` — `settings.templates.preview` — `App\Http\Controllers\Settings\NotificationTemplateController@preview` — `app/Http/Controllers/Settings/NotificationTemplateController.php:46` — middleware `web, auth, permission:settings.templates.manage`
- `POST settings/templates/{template}/reset` — `settings.templates.reset` — `App\Http\Controllers\Settings\NotificationTemplateController@reset` — `app/Http/Controllers/Settings/NotificationTemplateController.php:78` — middleware `web, auth, permission:settings.templates.manage`
- `POST settings/templates/{template}/send-test` — `settings.templates.send-test` — `App\Http\Controllers\Settings\NotificationTemplateController@sendTest` — `app/Http/Controllers/Settings/NotificationTemplateController.php:61` — middleware `web, auth, permission:settings.templates.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/NotificationTemplateController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/templates.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
