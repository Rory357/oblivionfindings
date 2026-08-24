# CAP-HS-SITE-HAZARD-HAZARD-ASSESSMENT: Hazard assignment review evidence transition and closure

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:hazards.view`, `permission:hazards.create`, `permission:hazards.manage`, `permission:hazards.assign`, `permission:hazards.close`
- Owning module: Health and safety
- Legacy family: `HS-SITE-HAZARD`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hazards/{hazard}` (`sites.hazards.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:hazards.view`, `permission:hazards.create`, `permission:hazards.manage`, `permission:hazards.assign`, `permission:hazards.close`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:hazards.view`, `permission:hazards.create`, `permission:hazards.manage`, `permission:hazards.assign`, `permission:hazards.close`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hazards/{hazard}` (`sites.hazards.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hazards/{hazard}/media/{kind}/{index}` (`sites.hazards.media.show`, action `showMedia`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteHazardController.php:660-682`.
3. Invoke only the owning control for `PUT hazards/{hazard}` (`sites.hazards.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteHazardController.php:378-395`; `description`, `severity`, `likelihood`, `location`, `witnesses`.
4. Invoke only the owning control for `POST hazards/{hazard}/actions` (`sites.hazards.actions.store`, action `storeAction`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteHazardController.php:518-541`; `title`, `action_type`, `assigned_to_user_id`, `due_date`.
5. Invoke only the owning control for `POST hazards/{hazard}/assign` (`sites.hazards.assign`, action `assign`). Source category: **assigned**; controller `app/Http/Controllers/Sites/SiteHazardController.php:401-423`; `assigned_to_user_id`, `due_date`.
6. Invoke only the owning control for `POST hazards/{hazard}/close` (`sites.hazards.close`, action `close`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Sites/SiteHazardController.php:491-516`; `resolution_summary`, `resolution_evidence`.
7. Invoke only the owning control for `POST hazards/{hazard}/media` (`sites.hazards.media`, action `media`). Source category: **mutation outcome source gap (media)**; controller `app/Http/Controllers/Sites/SiteHazardController.php:560-589`; `file`, `kind`.
8. Invoke only the owning control for `POST hazards/{hazard}/review` (`sites.hazards.review`, action `review`). Source category: **mutation outcome source gap (review)**; controller `app/Http/Controllers/Sites/SiteHazardController.php:475-489`; `note`.
9. Invoke only the owning control for `POST hazards/{hazard}/status` (`sites.hazards.status`, action `transition`). Source category: **mutation outcome source gap (transition)**; controller `app/Http/Controllers/Sites/SiteHazardController.php:425-473`; `note`, `control_hierarchy`, `residual_severity`, `residual_likelihood`.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-1045` at `app/Http/Controllers/Sites/SiteHazardController.php:305`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1046` at `app/Http/Controllers/Sites/SiteHazardController.php:378`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeAction` / `ROUTE-1047` at `app/Http/Controllers/Sites/SiteHazardController.php:518`; it is not runtime-observed.
- **assigned** is applicable only to `assign` / `ROUTE-1048` at `app/Http/Controllers/Sites/SiteHazardController.php:401`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `close` / `ROUTE-1049` at `app/Http/Controllers/Sites/SiteHazardController.php:491`; it is not runtime-observed.
- **mutation outcome source gap (media)** is applicable only to `media` / `ROUTE-1050` at `app/Http/Controllers/Sites/SiteHazardController.php:560`; it is not runtime-observed.
- **information presented** is applicable only to `showMedia` / `ROUTE-1051` at `app/Http/Controllers/Sites/SiteHazardController.php:660`; it is not runtime-observed.
- **mutation outcome source gap (review)** is applicable only to `review` / `ROUTE-1052` at `app/Http/Controllers/Sites/SiteHazardController.php:475`; it is not runtime-observed.
- **mutation outcome source gap (transition)** is applicable only to `transition` / `ROUTE-1053` at `app/Http/Controllers/Sites/SiteHazardController.php:425`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/hazards/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1046` / `update`: fields `description`, `severity`, `likelihood`, `location`, `witnesses`; success app/Http/Controllers/Sites/SiteHazardController.php:394 `return back()->with('success', 'Hazard updated.');`.
- `ROUTE-1047` / `storeAction`: fields `title`, `action_type`, `assigned_to_user_id`, `due_date`; success app/Http/Controllers/Sites/SiteHazardController.php:540 `return back()->with('success', "Corrective action {$action->reference_number} added.");`.
- `ROUTE-1048` / `assign`: fields `assigned_to_user_id`, `due_date`; success app/Http/Controllers/Sites/SiteHazardController.php:422 `return back()->with('success', 'Hazard assigned to ' . ($assignee?->name ?? 'owner') . '.');`.
- `ROUTE-1049` / `close`: fields `resolution_summary`, `resolution_evidence`; success app/Http/Controllers/Sites/SiteHazardController.php:515 `return back()->with('success', "Hazard {$hazard->reference_number} closed.");`.
- `ROUTE-1050` / `media`: fields `file`, `kind`; success app/Http/Controllers/Sites/SiteHazardController.php:588 `return back()->with('success', 'Evidence uploaded.');`.
- `ROUTE-1052` / `review`: fields `note`; success app/Http/Controllers/Sites/SiteHazardController.php:488 `return back()->with('success', 'Review recorded.');`.
- `ROUTE-1053` / `transition`: fields `note`, `control_hierarchy`, `residual_severity`, `residual_likelihood`; success app/Http/Controllers/Sites/SiteHazardController.php:472 `return back()->with('success', "Hazard moved to {$label}.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteHazardController.php:392 `$hazard->update($validated);`; app/Http/Controllers/Sites/SiteHazardController.php:529 `$action = SiteHazardAction::create([`; app/Http/Controllers/Sites/SiteHazardController.php:411 `$hazard->update([`; app/Http/Controllers/Sites/SiteHazardController.php:509 `$hazard->update([`; app/Http/Controllers/Sites/SiteHazardController.php:468 `$hazard->update($patch);`; responses app/Http/Controllers/Sites/SiteHazardController.php:317 `return inertia('sites/hazards/show', [`; app/Http/Controllers/Sites/SiteHazardController.php:394 `return back()->with('success', 'Hazard updated.');`; app/Http/Controllers/Sites/SiteHazardController.php:540 `return back()->with('success', "Corrective action {$action->reference_number} added.");`; app/Http/Controllers/Sites/SiteHazardController.php:422 `return back()->with('success', 'Hazard assigned to ' . ($assignee?->name ?? 'owner') . '.');`; app/Http/Controllers/Sites/SiteHazardController.php:496 `return back()->with('error', 'This hazard is already closed.');`; app/Http/Controllers/Sites/SiteHazardController.php:515 `return back()->with('success', "Hazard {$hazard->reference_number} closed.");`; app/Http/Controllers/Sites/SiteHazardController.php:588 `return back()->with('success', 'Evidence uploaded.');`; app/Http/Controllers/Sites/SiteHazardController.php:681 `return $this->streamPrivateAttachment($disk, $path, $name ?: 'attachment');`; app/Http/Controllers/Sites/SiteHazardController.php:488 `return back()->with('success', 'Review recorded.');`; app/Http/Controllers/Sites/SiteHazardController.php:445 `return back()->with('error', "A {$from} hazard can't be moved to " . str_replace('_', ' ', $to) . '.');`; app/Http/Controllers/Sites/SiteHazardController.php:452 `return back()->with('error', 'Select at least one control from the hierarchy.');`; app/Http/Controllers/Sites/SiteHazardController.php:455 `return back()->with('error', 'Set the residual severity and likelihood.');`; app/Http/Controllers/Sites/SiteHazardController.php:472 `return back()->with('success', "Hazard moved to {$label}.");`; audit calls app/Http/Controllers/Sites/SiteHazardController.php:417 `AuditLogger::log('hazard.assigned', $hazard, [`; app/Http/Controllers/Sites/SiteHazardController.php:486 `AuditLogger::log('hazard.reviewed', $hazard, ['note' => $validated['note']]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hazards/{hazard}` — `sites.hazards.show` — `App\Http\Controllers\Sites\SiteHazardController@show` — `app/Http/Controllers/Sites/SiteHazardController.php:305` — middleware `web, auth, verified, permission:hazards.view`
- `PUT hazards/{hazard}` — `sites.hazards.update` — `App\Http\Controllers\Sites\SiteHazardController@update` — `app/Http/Controllers/Sites/SiteHazardController.php:378` — middleware `web, auth, verified, permission:hazards.create`
- `POST hazards/{hazard}/actions` — `sites.hazards.actions.store` — `App\Http\Controllers\Sites\SiteHazardController@storeAction` — `app/Http/Controllers/Sites/SiteHazardController.php:518` — middleware `web, auth, verified, permission:hazards.manage`
- `POST hazards/{hazard}/assign` — `sites.hazards.assign` — `App\Http\Controllers\Sites\SiteHazardController@assign` — `app/Http/Controllers/Sites/SiteHazardController.php:401` — middleware `web, auth, verified, permission:hazards.assign`
- `POST hazards/{hazard}/close` — `sites.hazards.close` — `App\Http\Controllers\Sites\SiteHazardController@close` — `app/Http/Controllers/Sites/SiteHazardController.php:491` — middleware `web, auth, verified, permission:hazards.close`
- `POST hazards/{hazard}/media` — `sites.hazards.media` — `App\Http\Controllers\Sites\SiteHazardController@media` — `app/Http/Controllers/Sites/SiteHazardController.php:560` — middleware `web, auth, verified, permission:hazards.manage`
- `GET|HEAD hazards/{hazard}/media/{kind}/{index}` — `sites.hazards.media.show` — `App\Http\Controllers\Sites\SiteHazardController@showMedia` — `app/Http/Controllers/Sites/SiteHazardController.php:660` — middleware `web, auth, verified, permission:hazards.view`
- `POST hazards/{hazard}/review` — `sites.hazards.review` — `App\Http\Controllers\Sites\SiteHazardController@review` — `app/Http/Controllers/Sites/SiteHazardController.php:475` — middleware `web, auth, verified, permission:hazards.manage`
- `POST hazards/{hazard}/status` — `sites.hazards.status` — `App\Http\Controllers\Sites\SiteHazardController@transition` — `app/Http/Controllers/Sites/SiteHazardController.php:425` — middleware `web, auth, verified, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteHazardController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/hazards/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
