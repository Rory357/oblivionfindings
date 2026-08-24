# HR-HR-WEBHOOK: Hr Webhook

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.settings.manage`
- Owning module: Human resources
- Legacy family: `HR-HR-WEBHOOK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/settings/webhooks` (`hr.settings.webhooks.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.settings.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.settings.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/settings/webhooks` (`hr.settings.webhooks.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/settings/webhooks` (`hr.settings.webhooks.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/HrWebhookController.php:79-101`; `name`.
3. Invoke only the owning control for `PUT hr/settings/webhooks/{endpoint}` (`hr.settings.webhooks.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/HrWebhookController.php:103-126`; `name`.
4. Invoke only the owning control for `POST hr/settings/webhooks/{endpoint}/toggle-active` (`hr.settings.webhooks.toggleActive`, action `toggle`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/HrWebhookController.php:128-141`; no exact validation fields extracted.
5. Invoke only the owning control for `POST hr/settings/webhooks/deliveries/{delivery}/retry` (`hr.settings.webhooks.deliveries.retry`, action `retryDelivery`). Source category: **retried/replayed/reconciled**; controller `app/Http/Controllers/Hr/HrWebhookController.php:143-153`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1745` at `app/Http/Controllers/Hr/HrWebhookController.php:22`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1746` at `app/Http/Controllers/Hr/HrWebhookController.php:79`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1747` at `app/Http/Controllers/Hr/HrWebhookController.php:103`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggle` / `ROUTE-1748` at `app/Http/Controllers/Hr/HrWebhookController.php:128`; it is not runtime-observed.
- **retried/replayed/reconciled** is applicable only to `retryDelivery` / `ROUTE-1749` at `app/Http/Controllers/Hr/HrWebhookController.php:143`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/settings/webhooks.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1746` / `store`: fields `name`; success app/Http/Controllers/Hr/HrWebhookController.php:100 `return redirect()->back()->with('success', 'Webhook endpoint created.');`.
- `ROUTE-1747` / `update`: fields `name`; success app/Http/Controllers/Hr/HrWebhookController.php:125 `return redirect()->back()->with('success', 'Webhook endpoint updated.');`.
- `ROUTE-1748` / `toggle`: success app/Http/Controllers/Hr/HrWebhookController.php:140 `return redirect()->back()->with('success', $wasActive ? 'Webhook endpoint paused.' : 'Webhook endpoint resumed.');`.
- `ROUTE-1749` / `retryDelivery`: success app/Http/Controllers/Hr/HrWebhookController.php:152 `return redirect()->back()->with('success', 'Webhook delivery retry queued.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/HrWebhookController.php:69 `return Inertia::render('hr/settings/webhooks', [`; app/Http/Controllers/Hr/HrWebhookController.php:100 `return redirect()->back()->with('success', 'Webhook endpoint created.');`; app/Http/Controllers/Hr/HrWebhookController.php:125 `return redirect()->back()->with('success', 'Webhook endpoint updated.');`; app/Http/Controllers/Hr/HrWebhookController.php:140 `return redirect()->back()->with('success', $wasActive ? 'Webhook endpoint paused.' : 'Webhook endpoint resumed.');`; app/Http/Controllers/Hr/HrWebhookController.php:152 `return redirect()->back()->with('success', 'Webhook delivery retry queued.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/settings/webhooks` — `hr.settings.webhooks.index` — `App\Http\Controllers\Hr\HrWebhookController@index` — `app/Http/Controllers/Hr/HrWebhookController.php:22` — middleware `web, auth, permission:hr.settings.manage`
- `POST hr/settings/webhooks` — `hr.settings.webhooks.store` — `App\Http\Controllers\Hr\HrWebhookController@store` — `app/Http/Controllers/Hr/HrWebhookController.php:79` — middleware `web, auth, permission:hr.settings.manage`
- `PUT hr/settings/webhooks/{endpoint}` — `hr.settings.webhooks.update` — `App\Http\Controllers\Hr\HrWebhookController@update` — `app/Http/Controllers/Hr/HrWebhookController.php:103` — middleware `web, auth, permission:hr.settings.manage`
- `POST hr/settings/webhooks/{endpoint}/toggle-active` — `hr.settings.webhooks.toggleActive` — `App\Http\Controllers\Hr\HrWebhookController@toggle` — `app/Http/Controllers/Hr/HrWebhookController.php:128` — middleware `web, auth, permission:hr.settings.manage`
- `POST hr/settings/webhooks/deliveries/{delivery}/retry` — `hr.settings.webhooks.deliveries.retry` — `App\Http\Controllers\Hr\HrWebhookController@retryDelivery` — `app/Http/Controllers/Hr/HrWebhookController.php:143` — middleware `web, auth, permission:hr.settings.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/HrWebhookController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/settings/webhooks.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
