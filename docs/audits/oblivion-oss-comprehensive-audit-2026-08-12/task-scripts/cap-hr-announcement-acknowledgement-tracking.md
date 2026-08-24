# CAP-HR-ANNOUNCEMENT-ACKNOWLEDGEMENT-TRACKING: Announcement acknowledgement tracking and reminders

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.announcements.manage`
- Owning module: Human resources
- Legacy family: `HR-ANNOUNCEMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/announcements/{announcement}/tracking` (`hr.announcements.tracking`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.announcements.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.announcements.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/announcements/{announcement}/tracking` (`hr.announcements.tracking`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/announcements/{announcement}/tracking/export` (`hr.announcements.tracking.export`, action `trackingExport`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/AnnouncementController.php:507-526`.
3. Invoke only the owning control for `POST hr/announcements/{announcement}/acknowledge` (`hr.announcements.acknowledge`, action `acknowledge`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/AnnouncementController.php:296-307`; no exact validation fields extracted.
4. Invoke only the owning control for `POST hr/announcements/{announcement}/acknowledge-for` (`hr.announcements.acknowledge-for`, action `acknowledgeFor`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/AnnouncementController.php:309-330`; `user_id`.
5. Invoke only the owning control for `POST hr/announcements/{announcement}/remind` (`hr.announcements.remind`, action `remind`). Source category: **mutation outcome source gap (remind)**; controller `app/Http/Controllers/Hr/AnnouncementController.php:332-345`; `user_ids`.
6. Invoke only the owning control for `POST hr/announcements/bulk` (`hr.announcements.bulk`, action `bulk`). Source category: **mutation outcome source gap (bulk)**; controller `app/Http/Controllers/Hr/AnnouncementController.php:415-462`; `action`.
7. Invoke only the owning control for `POST hr/announcements/remind-bulk` (`hr.announcements.remind-bulk`, action `remindBulk`). Source category: **mutation outcome source gap (remindBulk)**; controller `app/Http/Controllers/Hr/AnnouncementController.php:347-369`; `announcement_ids`.

## Source-applicable states and transitions

- **approved/acknowledged/verified** is applicable only to `acknowledge` / `ROUTE-1262` at `app/Http/Controllers/Hr/AnnouncementController.php:296`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledgeFor` / `ROUTE-1263` at `app/Http/Controllers/Hr/AnnouncementController.php:309`; it is not runtime-observed.
- **mutation outcome source gap (remind)** is applicable only to `remind` / `ROUTE-1265` at `app/Http/Controllers/Hr/AnnouncementController.php:332`; it is not runtime-observed.
- **information presented** is applicable only to `tracking` / `ROUTE-1266` at `app/Http/Controllers/Hr/AnnouncementController.php:375`; it is not runtime-observed.
- **information presented** is applicable only to `trackingExport` / `ROUTE-1267` at `app/Http/Controllers/Hr/AnnouncementController.php:507`; it is not runtime-observed.
- **mutation outcome source gap (bulk)** is applicable only to `bulk` / `ROUTE-1270` at `app/Http/Controllers/Hr/AnnouncementController.php:415`; it is not runtime-observed.
- **mutation outcome source gap (remindBulk)** is applicable only to `remindBulk` / `ROUTE-1274` at `app/Http/Controllers/Hr/AnnouncementController.php:347`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1262` / `acknowledge`: success app/Http/Controllers/Hr/AnnouncementController.php:306 `return redirect()->back()->with('success', 'Announcement acknowledged.');`.
- `ROUTE-1263` / `acknowledgeFor`: fields `user_id`; success app/Http/Controllers/Hr/AnnouncementController.php:329 `return redirect()->back()->with('success', 'Marked acknowledged.');`.
- `ROUTE-1265` / `remind`: fields `user_ids`; success app/Http/Controllers/Hr/AnnouncementController.php:342 `return redirect()->back()->with('success', $sent === 0`.
- `ROUTE-1270` / `bulk`: fields `action`; success app/Http/Controllers/Hr/AnnouncementController.php:461 `return redirect()->back()->with('success', $this->bulkMessage($data['action'], $count));`.
- `ROUTE-1274` / `remindBulk`: fields `announcement_ids`; success app/Http/Controllers/Hr/AnnouncementController.php:368 `return redirect()->back()->with('success', "Reminders sent to {$total} ".($total === 1 ? 'person' : 'people').'.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/AnnouncementController.php:301 `HrAnnouncementAcknowledgement::firstOrCreate(`; app/Http/Controllers/Hr/AnnouncementController.php:320 `$ack = HrAnnouncementAcknowledgement::firstOrCreate(`; app/Http/Controllers/Hr/AnnouncementController.php:326 `$ack->update(['acknowledged_by' => $user->id]);`; app/Http/Controllers/Hr/AnnouncementController.php:436 `$announcement->update(['is_pinned' => true]);`; app/Http/Controllers/Hr/AnnouncementController.php:439 `$announcement->update(['is_pinned' => false]);`; app/Http/Controllers/Hr/AnnouncementController.php:442 `$announcement->update(['status' => 'archived']);`; app/Http/Controllers/Hr/AnnouncementController.php:447 `$announcement->update(['status' => 'published', 'published_at' => $announcement->published_at ?? now()]);`; app/Http/Controllers/Hr/AnnouncementController.php:452 `$announcement->delete();`; responses app/Http/Controllers/Hr/AnnouncementController.php:306 `return redirect()->back()->with('success', 'Announcement acknowledged.');`; app/Http/Controllers/Hr/AnnouncementController.php:329 `return redirect()->back()->with('success', 'Marked acknowledged.');`; app/Http/Controllers/Hr/AnnouncementController.php:342 `return redirect()->back()->with('success', $sent === 0`; app/Http/Controllers/Hr/AnnouncementController.php:384 `return response()->json($this->trackingData($announcement, $tenantId));`; app/Http/Controllers/Hr/AnnouncementController.php:525 `return $this->streamCsv("{$slug}-acknowledgements-".now()->format('Y-m-d'), $headers, $records);`; app/Http/Controllers/Hr/AnnouncementController.php:461 `return redirect()->back()->with('success', $this->bulkMessage($data['action'], $count));`; app/Http/Controllers/Hr/AnnouncementController.php:368 `return redirect()->back()->with('success', "Reminders sent to {$total} ".($total === 1 ? 'person' : 'people').'.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/announcements/{announcement}/acknowledge` — `hr.announcements.acknowledge` — `App\Http\Controllers\Hr\AnnouncementController@acknowledge` — `app/Http/Controllers/Hr/AnnouncementController.php:296` — middleware `web, auth`
- `POST hr/announcements/{announcement}/acknowledge-for` — `hr.announcements.acknowledge-for` — `App\Http\Controllers\Hr\AnnouncementController@acknowledgeFor` — `app/Http/Controllers/Hr/AnnouncementController.php:309` — middleware `web, auth, permission:hr.announcements.manage`
- `POST hr/announcements/{announcement}/remind` — `hr.announcements.remind` — `App\Http\Controllers\Hr\AnnouncementController@remind` — `app/Http/Controllers/Hr/AnnouncementController.php:332` — middleware `web, auth, permission:hr.announcements.manage`
- `GET|HEAD hr/announcements/{announcement}/tracking` — `hr.announcements.tracking` — `App\Http\Controllers\Hr\AnnouncementController@tracking` — `app/Http/Controllers/Hr/AnnouncementController.php:375` — middleware `web, auth, permission:hr.announcements.manage`
- `GET|HEAD hr/announcements/{announcement}/tracking/export` — `hr.announcements.tracking.export` — `App\Http\Controllers\Hr\AnnouncementController@trackingExport` — `app/Http/Controllers/Hr/AnnouncementController.php:507` — middleware `web, auth, permission:hr.announcements.manage`
- `POST hr/announcements/bulk` — `hr.announcements.bulk` — `App\Http\Controllers\Hr\AnnouncementController@bulk` — `app/Http/Controllers/Hr/AnnouncementController.php:415` — middleware `web, auth, permission:hr.announcements.manage`
- `POST hr/announcements/remind-bulk` — `hr.announcements.remind-bulk` — `App\Http\Controllers\Hr\AnnouncementController@remindBulk` — `app/Http/Controllers/Hr/AnnouncementController.php:347` — middleware `web, auth, permission:hr.announcements.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/AnnouncementController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
