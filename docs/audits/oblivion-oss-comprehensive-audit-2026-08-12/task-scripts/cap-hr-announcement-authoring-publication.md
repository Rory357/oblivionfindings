# CAP-HR-ANNOUNCEMENT-AUTHORING-PUBLICATION: Announcement authoring and publication

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.announcements.manage`
- Owning module: Human resources
- Legacy family: `HR-ANNOUNCEMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/announcements` (`hr.announcements.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.announcements.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.announcements.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/announcements` (`hr.announcements.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/announcements/{announcement}` (`hr.announcements.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/AnnouncementController.php:251-290`.
3. Use `GET|HEAD hr/announcements/attachments/{attachment}` (`hr.announcements.attachments.show`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/AnnouncementController.php:532-550`.
4. Use `GET|HEAD hr/announcements/create` (`hr.announcements.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/AnnouncementController.php:117-120`.
5. Use `GET|HEAD hr/announcements/export` (`hr.announcements.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/AnnouncementController.php:468-505`.
6. Use `GET|HEAD hr/announcements/preview` (`hr.announcements.preview`, action `preview`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/AnnouncementController.php:391-409`.
7. Invoke only the owning control for `POST hr/announcements` (`hr.announcements.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/AnnouncementController.php:126-163`; no exact validation fields extracted.
8. Invoke only the owning control for `DELETE hr/announcements/{announcement}` (`hr.announcements.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/AnnouncementController.php:202-213`; no exact validation fields extracted.
9. Invoke only the owning control for `PATCH hr/announcements/{announcement}` (`hr.announcements.`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/AnnouncementController.php:165-200`; no exact validation fields extracted.
10. Invoke only the owning control for `PUT hr/announcements/{announcement}` (`hr.announcements.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/AnnouncementController.php:165-200`; no exact validation fields extracted.
11. Invoke only the owning control for `POST hr/announcements/{announcement}/publish` (`hr.announcements.publish`, action `publishNow`). Source category: **mutation outcome source gap (publishNow)**; controller `app/Http/Controllers/Hr/AnnouncementController.php:233-245`; no exact validation fields extracted.
12. Invoke only the owning control for `POST hr/announcements/{id}/restore` (`hr.announcements.restore`, action `restore`). Source category: **mutation outcome source gap (restore)**; controller `app/Http/Controllers/Hr/AnnouncementController.php:215-231`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1256` at `app/Http/Controllers/Hr/AnnouncementController.php:61`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1257` at `app/Http/Controllers/Hr/AnnouncementController.php:126`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1258` at `app/Http/Controllers/Hr/AnnouncementController.php:202`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1259` at `app/Http/Controllers/Hr/AnnouncementController.php:251`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1260` at `app/Http/Controllers/Hr/AnnouncementController.php:165`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1261` at `app/Http/Controllers/Hr/AnnouncementController.php:165`; it is not runtime-observed.
- **mutation outcome source gap (publishNow)** is applicable only to `publishNow` / `ROUTE-1264` at `app/Http/Controllers/Hr/AnnouncementController.php:233`; it is not runtime-observed.
- **mutation outcome source gap (restore)** is applicable only to `restore` / `ROUTE-1268` at `app/Http/Controllers/Hr/AnnouncementController.php:215`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-1269` at `app/Http/Controllers/Hr/AnnouncementController.php:532`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1271` at `app/Http/Controllers/Hr/AnnouncementController.php:117`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1272` at `app/Http/Controllers/Hr/AnnouncementController.php:468`; it is not runtime-observed.
- **information presented** is applicable only to `preview` / `ROUTE-1273` at `app/Http/Controllers/Hr/AnnouncementController.php:391`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/announcements/index.tsx`, `resources/js/pages/hr/announcements/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1257` / `store`: success app/Http/Controllers/Hr/AnnouncementController.php:162 `->with('success', $this->savedMessage($announcement));`.
- `ROUTE-1258` / `destroy`: success app/Http/Controllers/Hr/AnnouncementController.php:212 `return redirect()->back()->with('success', 'Announcement archived.');`.
- `ROUTE-1260` / `update`: success app/Http/Controllers/Hr/AnnouncementController.php:199 `->with('success', 'Announcement updated.');`.
- `ROUTE-1261` / `update`: success app/Http/Controllers/Hr/AnnouncementController.php:199 `->with('success', 'Announcement updated.');`.
- `ROUTE-1264` / `publishNow`: success app/Http/Controllers/Hr/AnnouncementController.php:244 `return redirect()->back()->with('success', 'Announcement published.');`.
- `ROUTE-1268` / `restore`: success app/Http/Controllers/Hr/AnnouncementController.php:230 `return redirect()->back()->with('success', 'Announcement restored.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/AnnouncementController.php:135 `$announcement = HrAnnouncement::create([`; app/Http/Controllers/Hr/AnnouncementController.php:209 `$announcement->update(['status' => 'archived']);`; app/Http/Controllers/Hr/AnnouncementController.php:176 `$announcement->update([`; app/Http/Controllers/Hr/AnnouncementController.php:241 `$announcement->update(['status' => 'published', 'published_at' => now()]);`; app/Http/Controllers/Hr/AnnouncementController.php:224 `$announcement->restore();`; app/Http/Controllers/Hr/AnnouncementController.php:227 `$announcement->update(['status' => $this->statusFromDates($announcement)]);`; responses app/Http/Controllers/Hr/AnnouncementController.php:111 `return Inertia::render('hr/announcements/index', $payload);`; app/Http/Controllers/Hr/AnnouncementController.php:158 `return $announcement;`; app/Http/Controllers/Hr/AnnouncementController.php:161 `return redirect()->back(fallback: route('hr.announcements.index'))`; app/Http/Controllers/Hr/AnnouncementController.php:212 `return redirect()->back()->with('success', 'Announcement archived.');`; app/Http/Controllers/Hr/AnnouncementController.php:277 `return Inertia::render('hr/announcements/show', [`; app/Http/Controllers/Hr/AnnouncementController.php:198 `return redirect()->back(fallback: route('hr.announcements.index'))`; app/Http/Controllers/Hr/AnnouncementController.php:244 `return redirect()->back()->with('success', 'Announcement published.');`; app/Http/Controllers/Hr/AnnouncementController.php:230 `return redirect()->back()->with('success', 'Announcement restored.');`; app/Http/Controllers/Hr/AnnouncementController.php:543 `return $this->streamPrivateAttachment(`; app/Http/Controllers/Hr/AnnouncementController.php:119 `return redirect()->route('hr.announcements.index');`; app/Http/Controllers/Hr/AnnouncementController.php:491 `return [`; app/Http/Controllers/Hr/AnnouncementController.php:504 `return $this->streamCsv('announcements-'.now()->format('Y-m-d'), $headers, $records);`; app/Http/Controllers/Hr/AnnouncementController.php:408 `return response()->json(['count' => $count]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/announcements` — `hr.announcements.index` — `App\Http\Controllers\Hr\AnnouncementController@index` — `app/Http/Controllers/Hr/AnnouncementController.php:61` — middleware `web, auth`
- `POST hr/announcements` — `hr.announcements.store` — `App\Http\Controllers\Hr\AnnouncementController@store` — `app/Http/Controllers/Hr/AnnouncementController.php:126` — middleware `web, auth, permission:hr.announcements.manage`
- `DELETE hr/announcements/{announcement}` — `hr.announcements.destroy` — `App\Http\Controllers\Hr\AnnouncementController@destroy` — `app/Http/Controllers/Hr/AnnouncementController.php:202` — middleware `web, auth, permission:hr.announcements.manage`
- `GET|HEAD hr/announcements/{announcement}` — `hr.announcements.show` — `App\Http\Controllers\Hr\AnnouncementController@show` — `app/Http/Controllers/Hr/AnnouncementController.php:251` — middleware `web, auth`
- `PATCH hr/announcements/{announcement}` — `hr.announcements.` — `App\Http\Controllers\Hr\AnnouncementController@update` — `app/Http/Controllers/Hr/AnnouncementController.php:165` — middleware `web, auth, permission:hr.announcements.manage`
- `PUT hr/announcements/{announcement}` — `hr.announcements.update` — `App\Http\Controllers\Hr\AnnouncementController@update` — `app/Http/Controllers/Hr/AnnouncementController.php:165` — middleware `web, auth, permission:hr.announcements.manage`
- `POST hr/announcements/{announcement}/publish` — `hr.announcements.publish` — `App\Http\Controllers\Hr\AnnouncementController@publishNow` — `app/Http/Controllers/Hr/AnnouncementController.php:233` — middleware `web, auth, permission:hr.announcements.manage`
- `POST hr/announcements/{id}/restore` — `hr.announcements.restore` — `App\Http\Controllers\Hr\AnnouncementController@restore` — `app/Http/Controllers/Hr/AnnouncementController.php:215` — middleware `web, auth, permission:hr.announcements.manage`
- `GET|HEAD hr/announcements/attachments/{attachment}` — `hr.announcements.attachments.show` — `App\Http\Controllers\Hr\AnnouncementController@downloadAttachment` — `app/Http/Controllers/Hr/AnnouncementController.php:532` — middleware `web, auth`
- `GET|HEAD hr/announcements/create` — `hr.announcements.create` — `App\Http\Controllers\Hr\AnnouncementController@create` — `app/Http/Controllers/Hr/AnnouncementController.php:117` — middleware `web, auth, permission:hr.announcements.manage`
- `GET|HEAD hr/announcements/export` — `hr.announcements.export` — `App\Http\Controllers\Hr\AnnouncementController@export` — `app/Http/Controllers/Hr/AnnouncementController.php:468` — middleware `web, auth, permission:hr.announcements.manage`
- `GET|HEAD hr/announcements/preview` — `hr.announcements.preview` — `App\Http\Controllers\Hr\AnnouncementController@preview` — `app/Http/Controllers/Hr/AnnouncementController.php:391` — middleware `web, auth, permission:hr.announcements.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/AnnouncementController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/announcements/index.tsx`, `resources/js/pages/hr/announcements/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
