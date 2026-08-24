# HR-CALENDAR: Calendar

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Human resources
- Legacy family: `HR-CALENDAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/calendar` (`hr.calendar.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/calendar` (`hr.calendar.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/calendar/attachments/{attachment}/download` (`hr.calendar.attachments.download`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/CalendarController.php:316-328`.
3. Use `GET|HEAD hr/calendar/feed` (`hr.calendar.feed`, action `feed`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/CalendarController.php:179-218`.
4. Invoke only the owning control for `DELETE hr/calendar/attachments/{attachment}` (`hr.calendar.attachments.destroy`, action `destroyAttachment`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/CalendarController.php:303-313`; no exact validation fields extracted.
5. Invoke only the owning control for `POST hr/calendar/events` (`hr.calendar.events.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CalendarController.php:223-270`; `title`.
6. Invoke only the owning control for `DELETE hr/calendar/events/{event}` (`hr.calendar.events.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/CalendarController.php:585-594`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT hr/calendar/events/{event}` (`hr.calendar.events.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/CalendarController.php:345-419`; `title`.
8. Invoke only the owning control for `POST hr/calendar/events/{event}/attachments` (`hr.calendar.events.attachments.store`, action `storeAttachment`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CalendarController.php:276-300`; `file`.
9. Invoke only the owning control for `POST hr/calendar/events/{event}/rsvp` (`hr.calendar.events.rsvp`, action `rsvp`). Source category: **mutation outcome source gap (rsvp)**; controller `app/Http/Controllers/Hr/CalendarController.php:498-521`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1296` at `app/Http/Controllers/Hr/CalendarController.php:38`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyAttachment` / `ROUTE-1297` at `app/Http/Controllers/Hr/CalendarController.php:303`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-1298` at `app/Http/Controllers/Hr/CalendarController.php:316`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1299` at `app/Http/Controllers/Hr/CalendarController.php:223`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1300` at `app/Http/Controllers/Hr/CalendarController.php:585`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1301` at `app/Http/Controllers/Hr/CalendarController.php:345`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeAttachment` / `ROUTE-1302` at `app/Http/Controllers/Hr/CalendarController.php:276`; it is not runtime-observed.
- **mutation outcome source gap (rsvp)** is applicable only to `rsvp` / `ROUTE-1303` at `app/Http/Controllers/Hr/CalendarController.php:498`; it is not runtime-observed.
- **information presented** is applicable only to `feed` / `ROUTE-1304` at `app/Http/Controllers/Hr/CalendarController.php:179`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/calendar/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1299` / `store`: fields `title`; success app/Http/Controllers/Hr/CalendarController.php:268 `->with('success', 'Calendar event created.')`.
- `ROUTE-1300` / `destroy`: success app/Http/Controllers/Hr/CalendarController.php:593 `return redirect()->back()->with('success', 'Calendar event deleted.');`.
- `ROUTE-1301` / `update`: fields `title`; success app/Http/Controllers/Hr/CalendarController.php:399 `return redirect()->back()->with('success', 'This occurrence was updated.');`; app/Http/Controllers/Hr/CalendarController.php:405 `return redirect()->back()->with('success', 'This and following events were updated.');`; app/Http/Controllers/Hr/CalendarController.php:418 `return redirect()->back()->with('success', 'Calendar event updated.');`.
- `ROUTE-1302` / `storeAttachment`: fields `file`.
- `ROUTE-1303` / `rsvp`: success app/Http/Controllers/Hr/CalendarController.php:520 `return redirect()->back()->with('success', 'Your response was saved.');`.
- `ROUTE-1304` / `feed`: fields `from`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/CalendarController.php:309 `Storage::disk($attachment->disk ?: 'private')->delete($attachment->path);`; app/Http/Controllers/Hr/CalendarController.php:310 `$attachment->delete();`; app/Http/Controllers/Hr/CalendarController.php:257 `$event = HrCalendarEvent::create([`; app/Http/Controllers/Hr/CalendarController.php:591 `$event->delete();`; app/Http/Controllers/Hr/CalendarController.php:409 `$event->update($data);`; app/Http/Controllers/Hr/CalendarController.php:289 `$attachment = $event->attachments()->create([`; app/Http/Controllers/Hr/CalendarController.php:515 `$attendee->update([`; responses app/Http/Controllers/Hr/CalendarController.php:85 `return Inertia::render('hr/calendar/index', [`; app/Http/Controllers/Hr/CalendarController.php:312 `return response()->json(['ok' => true]);`; app/Http/Controllers/Hr/CalendarController.php:322 `return $this->streamPrivateAttachment(`; app/Http/Controllers/Hr/CalendarController.php:267 `return redirect()->back()`; app/Http/Controllers/Hr/CalendarController.php:593 `return redirect()->back()->with('success', 'Calendar event deleted.');`; app/Http/Controllers/Hr/CalendarController.php:399 `return redirect()->back()->with('success', 'This occurrence was updated.');`; app/Http/Controllers/Hr/CalendarController.php:405 `return redirect()->back()->with('success', 'This and following events were updated.');`; app/Http/Controllers/Hr/CalendarController.php:418 `return redirect()->back()->with('success', 'Calendar event updated.');`; app/Http/Controllers/Hr/CalendarController.php:299 `return response()->json(['attachment' => $this->attachmentPayload($attachment)]);`; app/Http/Controllers/Hr/CalendarController.php:520 `return redirect()->back()->with('success', 'Your response was saved.');`; app/Http/Controllers/Hr/CalendarController.php:217 `return response()->json(['events' => $events]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/calendar` — `hr.calendar.index` — `App\Http\Controllers\Hr\CalendarController@index` — `app/Http/Controllers/Hr/CalendarController.php:38` — middleware `web, auth`
- `DELETE hr/calendar/attachments/{attachment}` — `hr.calendar.attachments.destroy` — `App\Http\Controllers\Hr\CalendarController@destroyAttachment` — `app/Http/Controllers/Hr/CalendarController.php:303` — middleware `web, auth`
- `GET|HEAD hr/calendar/attachments/{attachment}/download` — `hr.calendar.attachments.download` — `App\Http\Controllers\Hr\CalendarController@downloadAttachment` — `app/Http/Controllers/Hr/CalendarController.php:316` — middleware `web, auth`
- `POST hr/calendar/events` — `hr.calendar.events.store` — `App\Http\Controllers\Hr\CalendarController@store` — `app/Http/Controllers/Hr/CalendarController.php:223` — middleware `web, auth`
- `DELETE hr/calendar/events/{event}` — `hr.calendar.events.destroy` — `App\Http\Controllers\Hr\CalendarController@destroy` — `app/Http/Controllers/Hr/CalendarController.php:585` — middleware `web, auth`
- `PUT hr/calendar/events/{event}` — `hr.calendar.events.update` — `App\Http\Controllers\Hr\CalendarController@update` — `app/Http/Controllers/Hr/CalendarController.php:345` — middleware `web, auth`
- `POST hr/calendar/events/{event}/attachments` — `hr.calendar.events.attachments.store` — `App\Http\Controllers\Hr\CalendarController@storeAttachment` — `app/Http/Controllers/Hr/CalendarController.php:276` — middleware `web, auth`
- `POST hr/calendar/events/{event}/rsvp` — `hr.calendar.events.rsvp` — `App\Http\Controllers\Hr\CalendarController@rsvp` — `app/Http/Controllers/Hr/CalendarController.php:498` — middleware `web, auth`
- `GET|HEAD hr/calendar/feed` — `hr.calendar.feed` — `App\Http\Controllers\Hr\CalendarController@feed` — `app/Http/Controllers/Hr/CalendarController.php:179` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/CalendarController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/calendar/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
