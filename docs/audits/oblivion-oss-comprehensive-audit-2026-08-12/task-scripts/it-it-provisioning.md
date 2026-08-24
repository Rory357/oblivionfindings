# IT-IT-PROVISIONING: It Provisioning

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:it.view`, `permission:it.manage`
- Owning module: IT and service support
- Legacy family: `IT-IT-PROVISIONING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `it` (`it.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:it.view`, `permission:it.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:it.view`, `permission:it.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD it` (`it.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST it/provisioning/{provisioning}/assign` (`it.provisioning.assign`, action `assign`). Source category: **assigned**; controller `app/Http/Controllers/It/ItProvisioningController.php:69-93`; FormRequest `app/Models/ItProvisioningRequest.php:line unresolved`; `assigned_to_user_id`.
3. Invoke only the owning control for `POST it/provisioning/{provisioning}/cancel` (`it.provisioning.cancel`, action `cancel`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/It/ItProvisioningController.php:142-186`; FormRequest `app/Models/ItProvisioningRequest.php:line unresolved`; `reason`.
4. Invoke only the owning control for `POST it/provisioning/{provisioning}/fulfil` (`it.provisioning.fulfil`, action `fulfil`). Source category: **mutation outcome source gap (fulfil)**; controller `app/Http/Controllers/It/ItProvisioningController.php:95-140`; FormRequest `app/Models/ItProvisioningRequest.php:line unresolved`; `external_ref`.
5. Invoke only the owning control for `POST it/tickets` (`it.tickets.store`, action `storeTicket`). Source category: **created/recorded**; controller `app/Http/Controllers/It/ItProvisioningController.php:192-221`; `title`.
6. Invoke only the owning control for `PATCH it/tickets/{ticket}` (`it.tickets.update`, action `updateTicket`). Source category: **updated/revised**; controller `app/Http/Controllers/It/ItProvisioningController.php:223-250`; no exact validation fields extracted.
7. Invoke only the owning control for `POST it/tickets/{ticket}/resolve` (`it.tickets.resolve`, action `resolveTicket`). Source category: **completed/closed/released**; controller `app/Http/Controllers/It/ItProvisioningController.php:252-269`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1862` at `app/Http/Controllers/It/ItProvisioningController.php:38`; it is not runtime-observed.
- **assigned** is applicable only to `assign` / `ROUTE-1863` at `app/Http/Controllers/It/ItProvisioningController.php:69`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancel` / `ROUTE-1864` at `app/Http/Controllers/It/ItProvisioningController.php:142`; it is not runtime-observed.
- **mutation outcome source gap (fulfil)** is applicable only to `fulfil` / `ROUTE-1865` at `app/Http/Controllers/It/ItProvisioningController.php:95`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeTicket` / `ROUTE-1866` at `app/Http/Controllers/It/ItProvisioningController.php:192`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateTicket` / `ROUTE-1867` at `app/Http/Controllers/It/ItProvisioningController.php:223`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `resolveTicket` / `ROUTE-1868` at `app/Http/Controllers/It/ItProvisioningController.php:252`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/it/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1863` / `assign`: FormRequest `app/Models/ItProvisioningRequest.php:line unresolved`; fields `assigned_to_user_id`; success app/Http/Controllers/It/ItProvisioningController.php:92 `return redirect()->back()->with('success', 'Request assigned.');`.
- `ROUTE-1864` / `cancel`: FormRequest `app/Models/ItProvisioningRequest.php:line unresolved`; fields `reason`; success app/Http/Controllers/It/ItProvisioningController.php:185 `return redirect()->back()->with('success', 'Request cancelled.');`.
- `ROUTE-1865` / `fulfil`: FormRequest `app/Models/ItProvisioningRequest.php:line unresolved`; fields `external_ref`; success app/Http/Controllers/It/ItProvisioningController.php:139 `return redirect()->back()->with('success', "Fulfilled “{$provisioning->item}”.");`.
- `ROUTE-1866` / `storeTicket`: fields `title`; success app/Http/Controllers/It/ItProvisioningController.php:220 `return redirect()->back()->with('success', 'Ticket logged.');`.
- `ROUTE-1867` / `updateTicket`: success app/Http/Controllers/It/ItProvisioningController.php:249 `return redirect()->back()->with('success', 'Ticket updated.');`.
- `ROUTE-1868` / `resolveTicket`: success app/Http/Controllers/It/ItProvisioningController.php:268 `return redirect()->back()->with('success', "Resolved “{$ticket->title}”.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/It/ItProvisioningController.php:87 `$provisioning->update([`; app/Http/Controllers/It/ItProvisioningController.php:158 `$provisioning->update(['status' => 'cancelled']);`; app/Http/Controllers/It/ItProvisioningController.php:169 `$task->update(['notes' => $existing === '' ? $note : $existing."\n".$note]);`; app/Http/Controllers/It/ItProvisioningController.php:116 `$provisioning->update([`; app/Http/Controllers/It/ItProvisioningController.php:209 `ItTicket::create([`; app/Http/Controllers/It/ItProvisioningController.php:247 `$ticket->update($update);`; app/Http/Controllers/It/ItProvisioningController.php:263 `$ticket->update([`; responses app/Http/Controllers/It/ItProvisioningController.php:53 `return Inertia::render('it/index', [`; app/Http/Controllers/It/ItProvisioningController.php:84 `return redirect()->back()->with('error', 'This request is closed — reopen it before reassigning.');`; app/Http/Controllers/It/ItProvisioningController.php:92 `return redirect()->back()->with('success', 'Request assigned.');`; app/Http/Controllers/It/ItProvisioningController.php:155 `return redirect()->back()->with('error', 'A fulfilled request cannot be cancelled.');`; app/Http/Controllers/It/ItProvisioningController.php:185 `return redirect()->back()->with('success', 'Request cancelled.');`; app/Http/Controllers/It/ItProvisioningController.php:108 `return redirect()->back()->with('error', 'This request is already fulfilled.');`; app/Http/Controllers/It/ItProvisioningController.php:111 `return redirect()->back()->with('error', 'This request was cancelled — it can no longer be fulfilled.');`; app/Http/Controllers/It/ItProvisioningController.php:136 `return redirect()->back()->with('error', $exception->getMessage());`; app/Http/Controllers/It/ItProvisioningController.php:139 `return redirect()->back()->with('success', "Fulfilled “{$provisioning->item}”.");`; app/Http/Controllers/It/ItProvisioningController.php:220 `return redirect()->back()->with('success', 'Ticket logged.');`; app/Http/Controllers/It/ItProvisioningController.php:249 `return redirect()->back()->with('success', 'Ticket updated.');`; app/Http/Controllers/It/ItProvisioningController.php:260 `return redirect()->back()->with('error', 'This ticket is already resolved.');`; app/Http/Controllers/It/ItProvisioningController.php:268 `return redirect()->back()->with('success', "Resolved “{$ticket->title}”.");`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/It/ItProvisioningController.php:174 `$creator?->notify(new ItProvisioningCancelledNotification($provisioning, $task, $reason));`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD it` — `it.index` — `App\Http\Controllers\It\ItProvisioningController@index` — `app/Http/Controllers/It/ItProvisioningController.php:38` — middleware `web, auth, permission:it.view`
- `POST it/provisioning/{provisioning}/assign` — `it.provisioning.assign` — `App\Http\Controllers\It\ItProvisioningController@assign` — `app/Http/Controllers/It/ItProvisioningController.php:69` — middleware `web, auth, permission:it.view, permission:it.manage`
- `POST it/provisioning/{provisioning}/cancel` — `it.provisioning.cancel` — `App\Http\Controllers\It\ItProvisioningController@cancel` — `app/Http/Controllers/It/ItProvisioningController.php:142` — middleware `web, auth, permission:it.view, permission:it.manage`
- `POST it/provisioning/{provisioning}/fulfil` — `it.provisioning.fulfil` — `App\Http\Controllers\It\ItProvisioningController@fulfil` — `app/Http/Controllers/It/ItProvisioningController.php:95` — middleware `web, auth, permission:it.view, permission:it.manage`
- `POST it/tickets` — `it.tickets.store` — `App\Http\Controllers\It\ItProvisioningController@storeTicket` — `app/Http/Controllers/It/ItProvisioningController.php:192` — middleware `web, auth, permission:it.view, permission:it.manage`
- `PATCH it/tickets/{ticket}` — `it.tickets.update` — `App\Http\Controllers\It\ItProvisioningController@updateTicket` — `app/Http/Controllers/It/ItProvisioningController.php:223` — middleware `web, auth, permission:it.view, permission:it.manage`
- `POST it/tickets/{ticket}/resolve` — `it.tickets.resolve` — `App\Http\Controllers\It\ItProvisioningController@resolveTicket` — `app/Http/Controllers/It/ItProvisioningController.php:252` — middleware `web, auth, permission:it.view, permission:it.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/It/ItProvisioningController.php`.
- Exact render/action page relationships: `resources/js/pages/it/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
