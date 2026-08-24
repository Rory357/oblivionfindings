# CAP-HS-SAFE-WORK-PROCEDURE-AUTHOR-EVIDENCE: Safe work procedure authoring and evidence

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:procedures.view`, `permission:procedures.create|procedures.manage`, `permission:procedures.manage`
- Owning module: Health and safety
- Legacy family: `HS-SAFE-WORK-PROCEDURE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/procedures` (`health-safety.procedures.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:procedures.view`, `permission:procedures.create|procedures.manage`, `permission:procedures.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:procedures.view`, `permission:procedures.create|procedures.manage`, `permission:procedures.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/procedures` (`health-safety.procedures.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD health-safety/procedures/{procedure}` (`health-safety.procedures.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:103-107`.
3. Use `GET|HEAD health-safety/procedures/{procedure}/attachments/{attachment}/download` (`health-safety.procedures.attachments.download`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:326-336`.
4. Use `GET|HEAD health-safety/procedures/{procedure}/edit` (`health-safety.procedures.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:98-101`.
5. Use `GET|HEAD health-safety/procedures/create` (`health-safety.procedures.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:93-96`.
6. Use `GET|HEAD health-safety/procedures/export` (`health-safety.procedures.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:110-137`.
7. Invoke only the owning control for `POST health-safety/procedures` (`health-safety.procedures.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:143-159`; no exact validation fields extracted.
8. Invoke only the owning control for `PUT health-safety/procedures/{procedure}` (`health-safety.procedures.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:161-183`; no exact validation fields extracted.
9. Invoke only the owning control for `POST health-safety/procedures/{procedure}/attachments` (`health-safety.procedures.attachments.store`, action `uploadAttachment`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:302-324`; `file`.
10. Invoke only the owning control for `DELETE health-safety/procedures/{procedure}/attachments/{attachment}` (`health-safety.procedures.attachments.destroy`, action `destroyAttachment`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:338-349`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1177` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:65`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1178` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:143`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1179` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:103`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1180` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:161`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadAttachment` / `ROUTE-1184` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:302`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyAttachment` / `ROUTE-1185` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:338`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-1186` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:326`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-1187` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:98`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1192` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:93`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1193` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:110`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/health-safety/procedures/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1184` / `uploadAttachment`: fields `file`; success app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:323 `return back()->with('success', 'Document attached.');`.
- `ROUTE-1185` / `destroyAttachment`: success app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:348 `return back()->with('success', 'Document removed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:147 `$procedure = SafeWorkProcedure::create(array_merge($validated, [`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:176 `$procedure->update($validated);`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:312 `$procedure->attachments()->create([`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:344 `Storage::disk($disk)->delete($attachment->path);`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:346 `$attachment->delete();`; responses app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:76 `return Inertia::render('health-safety/procedures/index', array_merge([`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:155 `return back()->with([`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:106 `return redirect()->route('health-safety.procedures.index', ['procedure' => $procedure->id]);`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:179 `return back()->with([`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:323 `return back()->with('success', 'Document attached.');`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:348 `return back()->with('success', 'Document removed.');`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:330 `return $this->streamPrivateAttachment(`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:100 `return redirect()->route('health-safety.procedures.index', ['procedure' => $procedure->id, 'edit' => 1]);`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:95 `return redirect()->route('health-safety.procedures.index', ['new' => 1]);`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:118 `return response()->streamDownload(function () use ($procedures) {`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD health-safety/procedures` — `health-safety.procedures.index` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@index` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:65` — middleware `web, auth, permission:procedures.view`
- `POST health-safety/procedures` — `health-safety.procedures.store` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@store` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:143` — middleware `web, auth, permission:procedures.create|procedures.manage`
- `GET|HEAD health-safety/procedures/{procedure}` — `health-safety.procedures.show` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@show` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:103` — middleware `web, auth, permission:procedures.view`
- `PUT health-safety/procedures/{procedure}` — `health-safety.procedures.update` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@update` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:161` — middleware `web, auth, permission:procedures.create|procedures.manage`
- `POST health-safety/procedures/{procedure}/attachments` — `health-safety.procedures.attachments.store` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@uploadAttachment` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:302` — middleware `web, auth, permission:procedures.manage`
- `DELETE health-safety/procedures/{procedure}/attachments/{attachment}` — `health-safety.procedures.attachments.destroy` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@destroyAttachment` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:338` — middleware `web, auth, permission:procedures.manage`
- `GET|HEAD health-safety/procedures/{procedure}/attachments/{attachment}/download` — `health-safety.procedures.attachments.download` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@downloadAttachment` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:326` — middleware `web, auth, permission:procedures.view`
- `GET|HEAD health-safety/procedures/{procedure}/edit` — `health-safety.procedures.edit` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@edit` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:98` — middleware `web, auth, permission:procedures.create|procedures.manage`
- `GET|HEAD health-safety/procedures/create` — `health-safety.procedures.create` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@create` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:93` — middleware `web, auth, permission:procedures.create|procedures.manage`
- `GET|HEAD health-safety/procedures/export` — `health-safety.procedures.export` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@export` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:110` — middleware `web, auth, permission:procedures.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php`.
- Exact render/action page relationships: `resources/js/pages/health-safety/procedures/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
