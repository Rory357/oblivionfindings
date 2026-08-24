# CAP-HS-RETURN-TO-WORK-INJURY-CAPACITY: Injury record evidence and capacity assessment

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.view|hr.wellbeing.view`, `permission:hazards.manage|hazards.create`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-RETURN-TO-WORK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/injuries` (`health-safety.injuries.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.view|hr.wellbeing.view`, `permission:hazards.manage|hazards.create`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.view|hr.wellbeing.view`, `permission:hazards.manage|hazards.create`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/injuries` (`health-safety.injuries.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD health-safety/injuries/{injury}` (`health-safety.injuries.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:561-564`.
3. Use `GET|HEAD health-safety/injuries/{injury}/attachments/{attachment}/download` (`health-safety.injuries.attachments.download`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:703-714`.
4. Use `GET|HEAD health-safety/injuries/create` (`health-safety.injuries.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:422-425`.
5. Use `GET|HEAD health-safety/injuries/export` (`health-safety.injuries.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:526-558`.
6. Invoke only the owning control for `POST health-safety/injuries` (`health-safety.injuries.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:427-460`; `user_id`.
7. Invoke only the owning control for `PUT health-safety/injuries/{injury}` (`health-safety.injuries.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:462-492`; `user_id`.
8. Invoke only the owning control for `POST health-safety/injuries/{injury}/attachments` (`health-safety.injuries.attachments.store`, action `uploadAttachment`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:672-701`; `file`.
9. Invoke only the owning control for `DELETE health-safety/injuries/{injury}/attachments/{attachment}` (`health-safety.injuries.attachments.destroy`, action `destroyAttachment`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:716-727`; no exact validation fields extracted.
10. Invoke only the owning control for `POST health-safety/injuries/{injury}/capacity-assessments` (`health-safety.injuries.capacity-assessments.store`, action `storeCapacityAssessment`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:625-645`; `assessment_date`.
11. Invoke only the owning control for `POST health-safety/injuries/{injury}/status` (`health-safety.injuries.status`, action `transitionStatus`). Source category: **mutation outcome source gap (transitionStatus)**; controller `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:498-523`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1127` at `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:83`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1128` at `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:427`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1129` at `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:561`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1130` at `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:462`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadAttachment` / `ROUTE-1131` at `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:672`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyAttachment` / `ROUTE-1132` at `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:716`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-1133` at `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:703`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeCapacityAssessment` / `ROUTE-1134` at `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:625`; it is not runtime-observed.
- **mutation outcome source gap (transitionStatus)** is applicable only to `transitionStatus` / `ROUTE-1136` at `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:498`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1137` at `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:422`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1138` at `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:526`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/health-safety/injuries/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1128` / `store`: fields `user_id`; success app/Http/Controllers/HealthSafety/ReturnToWorkController.php:454 `return back()->with('success', 'Workplace injury recorded.')->with('created_injury_id', $injury->id);`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:458 `->with('success', 'Workplace injury recorded.')`.
- `ROUTE-1130` / `update`: fields `user_id`; success app/Http/Controllers/HealthSafety/ReturnToWorkController.php:491 `return back()->with('success', 'Injury record updated.');`.
- `ROUTE-1131` / `uploadAttachment`: fields `file`; success app/Http/Controllers/HealthSafety/ReturnToWorkController.php:700 `return back()->with('success', 'Document uploaded.');`.
- `ROUTE-1132` / `destroyAttachment`: success app/Http/Controllers/HealthSafety/ReturnToWorkController.php:726 `return back()->with('success', 'Document removed.');`.
- `ROUTE-1134` / `storeCapacityAssessment`: fields `assessment_date`; success app/Http/Controllers/HealthSafety/ReturnToWorkController.php:644 `return back()->with('success', 'Capacity assessment recorded.');`.
- `ROUTE-1136` / `transitionStatus`: success app/Http/Controllers/HealthSafety/ReturnToWorkController.php:522 `return back()->with('success', 'Injury moved to '.str_replace('_', ' ', $to).'.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/ReturnToWorkController.php:445 `$injury = WorkplaceInjury::create(array_merge($validated, [`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:489 `$injury->update($validated);`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:688 `$injury->attachments()->create([`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:722 `Storage::disk($disk)->delete($attachment->path);`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:724 `$attachment->delete();`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:639 `$injury->capacityAssessments()->create(array_merge($validated, [`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:520 `$injury->update($changes);`; responses app/Http/Controllers/HealthSafety/ReturnToWorkController.php:156 `return Inertia::render('health-safety/injuries/index', [`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:454 `return back()->with('success', 'Workplace injury recorded.')->with('created_injury_id', $injury->id);`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:457 `return redirect()->route('health-safety.injuries.index')`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:563 `return redirect()->route('health-safety.injuries.index', ['injury' => $injury->id]);`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:491 `return back()->with('success', 'Injury record updated.');`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:700 `return back()->with('success', 'Document uploaded.');`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:726 `return back()->with('success', 'Document removed.');`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:708 `return $this->streamPrivateAttachment(`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:644 `return back()->with('success', 'Capacity assessment recorded.');`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:508 `return back(); // no-op — don't bump updated_by or emit a phantom audit entry`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:512 `return back()->with('error', 'That status change is not allowed from "'.str_replace('_', ' ', $from).'".');`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:522 `return back()->with('success', 'Injury moved to '.str_replace('_', ' ', $to).'.');`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:424 `return redirect()->route('health-safety.injuries.index');`; app/Http/Controllers/HealthSafety/ReturnToWorkController.php:535 `return response()->streamDownload(function () use ($query) {`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD health-safety/injuries` — `health-safety.injuries.index` — `App\Http\Controllers\HealthSafety\ReturnToWorkController@index` — `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:83` — middleware `web, auth, permission:hazards.view|hr.wellbeing.view`
- `POST health-safety/injuries` — `health-safety.injuries.store` — `App\Http\Controllers\HealthSafety\ReturnToWorkController@store` — `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:427` — middleware `web, auth, permission:hazards.manage|hazards.create`
- `GET|HEAD health-safety/injuries/{injury}` — `health-safety.injuries.show` — `App\Http\Controllers\HealthSafety\ReturnToWorkController@show` — `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:561` — middleware `web, auth, permission:hazards.view|hr.wellbeing.view`
- `PUT health-safety/injuries/{injury}` — `health-safety.injuries.update` — `App\Http\Controllers\HealthSafety\ReturnToWorkController@update` — `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:462` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/injuries/{injury}/attachments` — `health-safety.injuries.attachments.store` — `App\Http\Controllers\HealthSafety\ReturnToWorkController@uploadAttachment` — `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:672` — middleware `web, auth, permission:hazards.manage`
- `DELETE health-safety/injuries/{injury}/attachments/{attachment}` — `health-safety.injuries.attachments.destroy` — `App\Http\Controllers\HealthSafety\ReturnToWorkController@destroyAttachment` — `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:716` — middleware `web, auth, permission:hazards.manage`
- `GET|HEAD health-safety/injuries/{injury}/attachments/{attachment}/download` — `health-safety.injuries.attachments.download` — `App\Http\Controllers\HealthSafety\ReturnToWorkController@downloadAttachment` — `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:703` — middleware `web, auth, permission:hazards.view|hr.wellbeing.view`
- `POST health-safety/injuries/{injury}/capacity-assessments` — `health-safety.injuries.capacity-assessments.store` — `App\Http\Controllers\HealthSafety\ReturnToWorkController@storeCapacityAssessment` — `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:625` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/injuries/{injury}/status` — `health-safety.injuries.status` — `App\Http\Controllers\HealthSafety\ReturnToWorkController@transitionStatus` — `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:498` — middleware `web, auth, permission:hazards.manage`
- `GET|HEAD health-safety/injuries/create` — `health-safety.injuries.create` — `App\Http\Controllers\HealthSafety\ReturnToWorkController@create` — `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:422` — middleware `web, auth, permission:hazards.manage|hazards.create`
- `GET|HEAD health-safety/injuries/export` — `health-safety.injuries.export` — `App\Http\Controllers\HealthSafety\ReturnToWorkController@export` — `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:526` — middleware `web, auth, permission:hazards.view|hr.wellbeing.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/ReturnToWorkController.php`.
- Exact render/action page relationships: `resources/js/pages/health-safety/injuries/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
