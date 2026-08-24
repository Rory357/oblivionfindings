# CAP-HS-WORKER-PARTICIPATION-MEETINGS: Worker-participation meetings attendees and minutes

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.manage`, `permission:hazards.view`
- Owning module: Health and safety
- Legacy family: `HS-WORKER-PARTICIPATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/worker-participation/meetings/{meeting}/minutes/download` (`health-safety.worker-participation.meetings.minutes.download`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.manage`, `permission:hazards.view`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.manage`, `permission:hazards.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/worker-participation/meetings/{meeting}/minutes/download` (`health-safety.worker-participation.meetings.minutes.download`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT health-safety/worker-participation/meetings/{meeting}` (`health-safety.worker-participation.meetings.update`, action `updateMeeting`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:437-470`; no exact validation fields extracted.
3. Invoke only the owning control for `POST health-safety/worker-participation/meetings/{meeting}/attendees` (`health-safety.worker-participation.meetings.attendees`, action `addMeetingAttendees`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:472-488`; `user_ids`.
4. Invoke only the owning control for `PUT health-safety/worker-participation/meetings/{meeting}/cancel` (`health-safety.worker-participation.meetings.cancel`, action `cancelMeeting`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:524-531`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT health-safety/worker-participation/meetings/{meeting}/complete` (`health-safety.worker-participation.meetings.complete`, action `completeMeeting`). Source category: **completed/closed/released**; controller `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:490-522`; `minutes`.
6. Invoke only the owning control for `POST health-safety/worker-participation/meetings/{meeting}/minutes` (`health-safety.worker-participation.meetings.minutes.upload`, action `uploadMeetingMinutes`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:533-544`; `document`.

## Source-applicable states and transitions

- **updated/revised** is applicable only to `updateMeeting` / `ROUTE-1246` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:437`; it is not runtime-observed.
- **created/recorded** is applicable only to `addMeetingAttendees` / `ROUTE-1247` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:472`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancelMeeting` / `ROUTE-1248` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:524`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `completeMeeting` / `ROUTE-1249` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:490`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadMeetingMinutes` / `ROUTE-1250` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:533`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadMeetingMinutes` / `ROUTE-1251` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:546`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1246` / `updateMeeting`: success app/Http/Controllers/HealthSafety/WorkerParticipationController.php:469 `return back()->with('success', 'Meeting updated successfully.');`.
- `ROUTE-1247` / `addMeetingAttendees`: fields `user_ids`; success app/Http/Controllers/HealthSafety/WorkerParticipationController.php:487 `return back()->with('success', 'Attendees added successfully.');`.
- `ROUTE-1248` / `cancelMeeting`: success app/Http/Controllers/HealthSafety/WorkerParticipationController.php:530 `return back()->with('success', 'Meeting cancelled successfully.');`.
- `ROUTE-1249` / `completeMeeting`: fields `minutes`; success app/Http/Controllers/HealthSafety/WorkerParticipationController.php:521 `return back()->with('success', 'Meeting completed successfully.');`.
- `ROUTE-1250` / `uploadMeetingMinutes`: fields `document`; success app/Http/Controllers/HealthSafety/WorkerParticipationController.php:543 `return back()->with('success', 'Meeting minutes uploaded successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/WorkerParticipationController.php:461 `$meeting->update($validated);`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:528 `$meeting->update(['status' => 'cancelled']);`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:513 `$meeting->update([`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:538 `$meeting->update([`; responses app/Http/Controllers/HealthSafety/WorkerParticipationController.php:469 `return back()->with('success', 'Meeting updated successfully.');`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:487 `return back()->with('success', 'Attendees added successfully.');`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:530 `return back()->with('success', 'Meeting cancelled successfully.');`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:521 `return back()->with('success', 'Meeting completed successfully.');`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:543 `return back()->with('success', 'Meeting minutes uploaded successfully.');`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:551 `return $this->streamPrivateAttachment(`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `PUT health-safety/worker-participation/meetings/{meeting}` — `health-safety.worker-participation.meetings.update` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@updateMeeting` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:437` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/worker-participation/meetings/{meeting}/attendees` — `health-safety.worker-participation.meetings.attendees` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@addMeetingAttendees` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:472` — middleware `web, auth, permission:hazards.manage`
- `PUT health-safety/worker-participation/meetings/{meeting}/cancel` — `health-safety.worker-participation.meetings.cancel` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@cancelMeeting` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:524` — middleware `web, auth, permission:hazards.manage`
- `PUT health-safety/worker-participation/meetings/{meeting}/complete` — `health-safety.worker-participation.meetings.complete` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@completeMeeting` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:490` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/worker-participation/meetings/{meeting}/minutes` — `health-safety.worker-participation.meetings.minutes.upload` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@uploadMeetingMinutes` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:533` — middleware `web, auth, permission:hazards.manage`
- `GET|HEAD health-safety/worker-participation/meetings/{meeting}/minutes/download` — `health-safety.worker-participation.meetings.minutes.download` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@downloadMeetingMinutes` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:546` — middleware `web, auth, permission:hazards.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/WorkerParticipationController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
