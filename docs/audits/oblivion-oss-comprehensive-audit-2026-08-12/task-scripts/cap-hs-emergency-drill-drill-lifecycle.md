# CAP-HS-EMERGENCY-DRILL-DRILL-LIFECYCLE: Emergency drill scheduling execution participants and evidence

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage|hazards.create`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-EMERGENCY-DRILL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/drills` (`health-safety.drills.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage|hazards.create`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.view`, `permission:hazards.manage|hazards.create`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/drills` (`health-safety.drills.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD health-safety/drills/{drill}` (`health-safety.drills.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:235-240`.
3. Use `GET|HEAD health-safety/drills/{drill}/attachments/{attachment}/download` (`health-safety.drills.attachments.download`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:472-483`.
4. Use `GET|HEAD health-safety/drills/create` (`health-safety.drills.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:176-179`.
5. Invoke only the owning control for `POST health-safety/drills` (`health-safety.drills.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:185-230`; `site_id`.
6. Invoke only the owning control for `PUT health-safety/drills/{drill}` (`health-safety.drills.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:245-268`; `title`.
7. Invoke only the owning control for `POST health-safety/drills/{drill}/attachments` (`health-safety.drills.attachments.store`, action `uploadAttachment`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:444-470`; `file`.
8. Invoke only the owning control for `DELETE health-safety/drills/{drill}/attachments/{attachment}` (`health-safety.drills.attachments.destroy`, action `destroyAttachment`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:485-496`; no exact validation fields extracted.
9. Invoke only the owning control for `POST health-safety/drills/{drill}/cancel` (`health-safety.drills.cancel`, action `cancel`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:327-350`; `reason`.
10. Invoke only the owning control for `POST health-safety/drills/{drill}/complete` (`health-safety.drills.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:293-322`; `completed_at`.
11. Invoke only the owning control for `POST health-safety/drills/{drill}/participants` (`health-safety.drills.participants.store`, action `addParticipant`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:355-373`; `user_id`.
12. Invoke only the owning control for `POST health-safety/drills/{drill}/start` (`health-safety.drills.start`, action `start`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:273-286`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1083` at `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:49`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1084` at `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:185`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1085` at `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:235`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1086` at `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:245`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadAttachment` / `ROUTE-1087` at `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:444`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyAttachment` / `ROUTE-1088` at `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:485`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-1089` at `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:472`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancel` / `ROUTE-1090` at `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:327`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-1091` at `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:293`; it is not runtime-observed.
- **created/recorded** is applicable only to `addParticipant` / `ROUTE-1094` at `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:355`; it is not runtime-observed.
- **created/recorded** is applicable only to `start` / `ROUTE-1095` at `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:273`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1096` at `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:176`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/health-safety/drills/index.tsx`, `resources/js/pages/health-safety/drills/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1084` / `store`: fields `site_id`; success app/Http/Controllers/HealthSafety/EmergencyDrillController.php:226 `return back()->with('success', 'Emergency drill scheduled.');`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:229 `return redirect()->route('health-safety.drills.index')->with('success', 'Emergency drill scheduled.');`.
- `ROUTE-1086` / `update`: fields `title`; success app/Http/Controllers/HealthSafety/EmergencyDrillController.php:267 `return back()->with('success', 'Drill updated.');`.
- `ROUTE-1087` / `uploadAttachment`: fields `file`; success app/Http/Controllers/HealthSafety/EmergencyDrillController.php:469 `return back()->with('success', 'Evidence uploaded.');`.
- `ROUTE-1088` / `destroyAttachment`: success app/Http/Controllers/HealthSafety/EmergencyDrillController.php:495 `return back()->with('success', 'Evidence removed.');`.
- `ROUTE-1090` / `cancel`: fields `reason`; success app/Http/Controllers/HealthSafety/EmergencyDrillController.php:349 `return back()->with('success', 'Drill cancelled.');`.
- `ROUTE-1091` / `complete`: fields `completed_at`; success app/Http/Controllers/HealthSafety/EmergencyDrillController.php:321 `return back()->with('success', 'Completion recorded.');`.
- `ROUTE-1094` / `addParticipant`: fields `user_id`; success app/Http/Controllers/HealthSafety/EmergencyDrillController.php:372 `return back()->with('success', 'Participant added.');`.
- `ROUTE-1095` / `start`: success app/Http/Controllers/HealthSafety/EmergencyDrillController.php:285 `return back()->with('success', 'Drill started — now in progress.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/EmergencyDrillController.php:205 `$drill = EmergencyDrill::create(array_merge($validated, [`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:222 `$drill->participants()->create(['user_id' => $userId, 'role' => $role, 'attended' => false]);`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:265 `$drill->update($validated);`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:457 `$drill->attachments()->create([`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:491 `Storage::disk($disk)->delete($attachment->path);`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:493 `$attachment->delete();`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:343 `$drill->update([`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:312 `$drill->update(array_merge($validated, [`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:279 `$drill->update([`; responses app/Http/Controllers/HealthSafety/EmergencyDrillController.php:153 `return Inertia::render('health-safety/drills/index', [`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:226 `return back()->with('success', 'Emergency drill scheduled.');`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:229 `return redirect()->route('health-safety.drills.index')->with('success', 'Emergency drill scheduled.');`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:237 `return Inertia::render('health-safety/drills/show', [`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:267 `return back()->with('success', 'Drill updated.');`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:469 `return back()->with('success', 'Evidence uploaded.');`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:495 `return back()->with('success', 'Evidence removed.');`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:477 `return $this->streamPrivateAttachment(`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:330 `return back()->with('error', 'This drill cannot be cancelled.');`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:349 `return back()->with('success', 'Drill cancelled.');`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:296 `return back()->with('error', 'This drill has already been closed out.');`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:321 `return back()->with('success', 'Completion recorded.');`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:372 `return back()->with('success', 'Participant added.');`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:276 `return back()->with('error', 'Only a scheduled drill can be started.');`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:285 `return back()->with('success', 'Drill started — now in progress.');`; app/Http/Controllers/HealthSafety/EmergencyDrillController.php:178 `return redirect()->route('health-safety.drills.index', ['schedule' => 1]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD health-safety/drills` — `health-safety.drills.index` — `App\Http\Controllers\HealthSafety\EmergencyDrillController@index` — `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:49` — middleware `web, auth, permission:hazards.view`
- `POST health-safety/drills` — `health-safety.drills.store` — `App\Http\Controllers\HealthSafety\EmergencyDrillController@store` — `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:185` — middleware `web, auth, permission:hazards.manage|hazards.create`
- `GET|HEAD health-safety/drills/{drill}` — `health-safety.drills.show` — `App\Http\Controllers\HealthSafety\EmergencyDrillController@show` — `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:235` — middleware `web, auth, permission:hazards.view`
- `PUT health-safety/drills/{drill}` — `health-safety.drills.update` — `App\Http\Controllers\HealthSafety\EmergencyDrillController@update` — `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:245` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/drills/{drill}/attachments` — `health-safety.drills.attachments.store` — `App\Http\Controllers\HealthSafety\EmergencyDrillController@uploadAttachment` — `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:444` — middleware `web, auth, permission:hazards.manage`
- `DELETE health-safety/drills/{drill}/attachments/{attachment}` — `health-safety.drills.attachments.destroy` — `App\Http\Controllers\HealthSafety\EmergencyDrillController@destroyAttachment` — `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:485` — middleware `web, auth, permission:hazards.manage`
- `GET|HEAD health-safety/drills/{drill}/attachments/{attachment}/download` — `health-safety.drills.attachments.download` — `App\Http\Controllers\HealthSafety\EmergencyDrillController@downloadAttachment` — `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:472` — middleware `web, auth, permission:hazards.view`
- `POST health-safety/drills/{drill}/cancel` — `health-safety.drills.cancel` — `App\Http\Controllers\HealthSafety\EmergencyDrillController@cancel` — `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:327` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/drills/{drill}/complete` — `health-safety.drills.complete` — `App\Http\Controllers\HealthSafety\EmergencyDrillController@complete` — `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:293` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/drills/{drill}/participants` — `health-safety.drills.participants.store` — `App\Http\Controllers\HealthSafety\EmergencyDrillController@addParticipant` — `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:355` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/drills/{drill}/start` — `health-safety.drills.start` — `App\Http\Controllers\HealthSafety\EmergencyDrillController@start` — `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:273` — middleware `web, auth, permission:hazards.manage`
- `GET|HEAD health-safety/drills/create` — `health-safety.drills.create` — `App\Http\Controllers\HealthSafety\EmergencyDrillController@create` — `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:176` — middleware `web, auth, permission:hazards.manage|hazards.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/EmergencyDrillController.php`.
- Exact render/action page relationships: `resources/js/pages/health-safety/drills/index.tsx`, `resources/js/pages/health-safety/drills/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
