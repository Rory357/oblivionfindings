# CAP-GOV-GOVERNANCE-MEETING-SCHEDULING-AGENDA: Meeting scheduling calendar and agenda

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.meetings.view`, `permission:governance.meetings.manage`
- Owning module: Governance
- Legacy family: `GOV-GOVERNANCE-MEETING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/meetings` (`governance.meetings.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.meetings.view`, `permission:governance.meetings.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.meetings.view`, `permission:governance.meetings.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/meetings` (`governance.meetings.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/meetings/{meeting}` (`governance.meetings.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:123-150`.
3. Use `GET|HEAD governance/meetings/{meeting}/edit` (`governance.meetings.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:152-164`.
4. Use `GET|HEAD governance/meetings/calendar` (`governance.meetings.calendar`, action `calendar`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:49-121`.
5. Use `GET|HEAD governance/meetings/create` (`governance.meetings.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:27-36`.
6. Invoke only the owning control for `POST governance/meetings` (`governance.meetings.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:166-175`; no exact validation fields extracted.
7. Invoke only the owning control for `DELETE governance/meetings/{meeting}` (`governance.meetings.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:185-193`; no exact validation fields extracted.
8. Invoke only the owning control for `PUT governance/meetings/{meeting}` (`governance.meetings.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:177-183`; FormRequest `app/Domain/Governance/Http/Requests/UpdateMeetingRequest.php:14`; `title`, `scheduled_at`, `duration_minutes`, `location`, `virtual_link`, `notes`, `chair_id`, `secretary_id`.
9. Invoke only the owning control for `POST governance/meetings/{meeting}/agenda` (`governance.meetings.agenda.add`, action `addAgendaItem`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:195-216`; `title`, `description`, `presenter_id`, `duration_minutes`, `item_type`, `is_confidential`.
10. Invoke only the owning control for `DELETE governance/meetings/{meeting}/agenda/{item}` (`governance.meetings.agenda.remove`, action `removeAgendaItem`). Source category: **cancelled/removed/archived**; controller `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:240-248`; no exact validation fields extracted.
11. Invoke only the owning control for `PUT governance/meetings/{meeting}/agenda/{item}` (`governance.meetings.agenda.update`, action `updateAgendaItem`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:218-238`; `title`, `description`, `presenter_id`, `duration_minutes`, `order`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0932` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:38`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0933` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:166`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0934` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:185`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0935` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:123`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0936` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:177`; it is not runtime-observed.
- **created/recorded** is applicable only to `addAgendaItem` / `ROUTE-0938` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:195`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `removeAgendaItem` / `ROUTE-0939` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:240`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateAgendaItem` / `ROUTE-0940` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:218`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-0942` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:152`; it is not runtime-observed.
- **information presented** is applicable only to `calendar` / `ROUTE-0950` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:49`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0951` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:27`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Meetings/Calendar.tsx`, `resources/js/pages/Governance/Meetings/Create.tsx`, `resources/js/pages/Governance/Meetings/Edit.tsx`, `resources/js/pages/Governance/Meetings/Index.tsx`, `resources/js/pages/Governance/Meetings/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0933` / `store`: FormRequest `StoreMeetingRequest` unresolved; success app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:174 `->with('success', 'Meeting scheduled successfully.');`.
- `ROUTE-0934` / `destroy`: success app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:192 `->with('success', 'Meeting cancelled.');`.
- `ROUTE-0936` / `update`: FormRequest `app/Domain/Governance/Http/Requests/UpdateMeetingRequest.php:14`; fields `title`, `scheduled_at`, `duration_minutes`, `location`, `virtual_link`, `notes`, `chair_id`, `secretary_id`; success app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:182 `->with('success', 'Meeting updated successfully.');`.
- `ROUTE-0938` / `addAgendaItem`: fields `title`, `description`, `presenter_id`, `duration_minutes`, `item_type`, `is_confidential`; success app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:215 `return redirect()->back()->with('success', 'Agenda item added.');`.
- `ROUTE-0939` / `removeAgendaItem`: success app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:247 `return redirect()->back()->with('success', 'Agenda item removed.');`.
- `ROUTE-0940` / `updateAgendaItem`: fields `title`, `description`, `presenter_id`, `duration_minutes`, `order`; success app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:237 `return redirect()->back()->with('success', 'Agenda item updated.');`.
- `ROUTE-0950` / `calendar`: fields `month`, `date`, `meeting_type`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:168 `$meeting = GovernanceMeeting::create([`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:189 `$meeting->delete();`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:179 `$meeting->update($request->validated());`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:210 `$meeting->agendaItems()->create([`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:244 `$item->delete();`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:230 `$item->update($validated);`; responses app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:44 `return Inertia::render('Governance/Meetings/Index', [`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:173 `return redirect()->route('governance.meetings.show', $meeting)`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:191 `return redirect()->route('governance.meetings.index')`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:140 `return Inertia::render('Governance/Meetings/Show', [`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:181 `return redirect()->route('governance.meetings.show', $meeting)`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:215 `return redirect()->back()->with('success', 'Agenda item added.');`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:247 `return redirect()->back()->with('success', 'Agenda item removed.');`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:237 `return redirect()->back()->with('success', 'Agenda item updated.');`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:159 `return Inertia::render('Governance/Meetings/Edit', [`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:103 `return Inertia::render('Governance/Meetings/Calendar', [`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:32 `return Inertia::render('Governance/Meetings/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/meetings` — `governance.meetings.index` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@index` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:38` — middleware `web, auth, permission:governance.meetings.view`
- `POST governance/meetings` — `governance.meetings.store` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@store` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:166` — middleware `web, auth, permission:governance.meetings.view, permission:governance.meetings.manage`
- `DELETE governance/meetings/{meeting}` — `governance.meetings.destroy` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@destroy` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:185` — middleware `web, auth, permission:governance.meetings.view, permission:governance.meetings.manage`
- `GET|HEAD governance/meetings/{meeting}` — `governance.meetings.show` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@show` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:123` — middleware `web, auth, permission:governance.meetings.view`
- `PUT governance/meetings/{meeting}` — `governance.meetings.update` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@update` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:177` — middleware `web, auth, permission:governance.meetings.view, permission:governance.meetings.manage`
- `POST governance/meetings/{meeting}/agenda` — `governance.meetings.agenda.add` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@addAgendaItem` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:195` — middleware `web, auth, permission:governance.meetings.view, permission:governance.meetings.manage`
- `DELETE governance/meetings/{meeting}/agenda/{item}` — `governance.meetings.agenda.remove` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@removeAgendaItem` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:240` — middleware `web, auth, permission:governance.meetings.view, permission:governance.meetings.manage`
- `PUT governance/meetings/{meeting}/agenda/{item}` — `governance.meetings.agenda.update` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@updateAgendaItem` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:218` — middleware `web, auth, permission:governance.meetings.view, permission:governance.meetings.manage`
- `GET|HEAD governance/meetings/{meeting}/edit` — `governance.meetings.edit` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@edit` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:152` — middleware `web, auth, permission:governance.meetings.view, permission:governance.meetings.manage`
- `GET|HEAD governance/meetings/calendar` — `governance.meetings.calendar` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@calendar` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:49` — middleware `web, auth, permission:governance.meetings.view`
- `GET|HEAD governance/meetings/create` — `governance.meetings.create` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@create` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:27` — middleware `web, auth, permission:governance.meetings.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Meetings/Calendar.tsx`, `resources/js/pages/Governance/Meetings/Create.tsx`, `resources/js/pages/Governance/Meetings/Edit.tsx`, `resources/js/pages/Governance/Meetings/Index.tsx`, `resources/js/pages/Governance/Meetings/Show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
