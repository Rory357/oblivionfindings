# CAP-CR-CONTROL-ROOM-ALERT-INTAKE-TRIAGE: Alert intake triage and enrichment

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:controlRoom.viewAny`, `permission:controlRoom.alerts.create`, `permission:controlRoom.alerts.manage`
- Owning module: Control Room
- Legacy family: `CR-CONTROL-ROOM-ALERT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `control-room/alerts` (`control-room.alerts.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:controlRoom.viewAny`, `permission:controlRoom.alerts.create`, `permission:controlRoom.alerts.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:controlRoom.viewAny`, `permission:controlRoom.alerts.create`, `permission:controlRoom.alerts.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD control-room/alerts` (`control-room.alerts.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD control-room/alerts/{alert}` (`control-room.alerts.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:425-437`.
3. Invoke only the owning control for `POST control-room/alerts` (`control-room.alerts.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:850-916`; `source`.
4. Invoke only the owning control for `POST control-room/alerts/{alert}/confirm` (`control-room.alerts.confirm`, action `confirm`). Source category: **mutation outcome source gap (confirm)**; controller `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:476-497`; `type`.
5. Invoke only the owning control for `POST control-room/alerts/{alert}/meta` (`control-room.alerts.meta`, action `updateMeta`). Source category: **updated/revised**; controller `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:803-845`; `priority`.
6. Invoke only the owning control for `POST control-room/alerts/{alert}/note` (`control-room.alerts.note`, action `addNote`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:768-798`; `note`.
7. Invoke only the owning control for `POST control-room/alerts/{alert}/triage` (`control-room.alerts.triage`, action `triage`). Source category: **mutation outcome source gap (triage)**; controller `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:524-551`; `notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0214` at `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:33`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0215` at `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:850`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0216` at `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:425`; it is not runtime-observed.
- **mutation outcome source gap (confirm)** is applicable only to `confirm` / `ROUTE-0221` at `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:476`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateMeta` / `ROUTE-0228` at `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:803`; it is not runtime-observed.
- **created/recorded** is applicable only to `addNote` / `ROUTE-0229` at `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:768`; it is not runtime-observed.
- **mutation outcome source gap (triage)** is applicable only to `triage` / `ROUTE-0240` at `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:524`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/control-room/alerts/index.tsx`, `resources/js/pages/control-room/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0215` / `store`: fields `source`; success app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:910 `->with('success', 'Alert created.')`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:915 `->with('success', 'Alert created.');`.
- `ROUTE-0221` / `confirm`: fields `type`; success app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:495 `->with('success', "Confirmed — incident INC-{$incident->id} created.")`; failure app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:491 `return back()->withErrors(['alert' => $e->getMessage()]);`.
- `ROUTE-0228` / `updateMeta`: fields `priority`.
- `ROUTE-0229` / `addNote`: fields `note`; success app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:797 `return back()->with('success', 'Note added.');`.
- `ROUTE-0240` / `triage`: fields `notes`; success app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:550 `return back()->with('success', 'Alert is now being triaged.');`; failure app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:531 `return back()->withErrors(['alert' => "Cannot start triage on an alert in '{$alert->status}' status."]);`.

## Failure and recovery paths

- `confirm`: app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:491 `return back()->withErrors(['alert' => $e->getMessage()]);`.
- `triage`: app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:531 `return back()->withErrors(['alert' => "Cannot start triage on an alert in '{$alert->status}' status."]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:874 `$alert = ControlRoomAlert::create($data);`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:877 `\App\Models\ControlRoom\AlertQueue::create([`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:835 `$alert->update($fieldsToUpdate);`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:787 `$alert->update([`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:538 `$alert->update([`; responses app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:177 `return Inertia::render('control-room/alerts/index', [`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:897 `return response()->json([`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:909 `return back()`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:914 `return redirect()->route('control-room.alerts.show', $alert)`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:436 `return Inertia::render('control-room/show', $detail);`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:491 `return back()->withErrors(['alert' => $e->getMessage()]);`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:494 `return back()`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:844 `return back();`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:797 `return back()->with('success', 'Note added.');`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:531 `return back()->withErrors(['alert' => "Cannot start triage on an alert in '{$alert->status}' status."]);`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:550 `return back()->with('success', 'Alert is now being triaged.');`; audit calls app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:891 `AuditLogger::log('controlRoom.alert.create', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:838 `AuditLogger::log('controlRoom.alert.updateMeta', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:793 `AuditLogger::log('controlRoom.alert.addNote', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:545 `AuditLogger::log('controlRoom.alert.triage', $alert, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD control-room/alerts` — `control-room.alerts.index` — `App\Http\Controllers\ControlRoom\ControlRoomAlertController@index` — `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:33` — middleware `web, auth, permission:controlRoom.viewAny`
- `POST control-room/alerts` — `control-room.alerts.store` — `App\Http\Controllers\ControlRoom\ControlRoomAlertController@store` — `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:850` — middleware `web, auth, permission:controlRoom.alerts.create`
- `GET|HEAD control-room/alerts/{alert}` — `control-room.alerts.show` — `App\Http\Controllers\ControlRoom\ControlRoomAlertController@show` — `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:425` — middleware `web, auth, permission:controlRoom.viewAny`
- `POST control-room/alerts/{alert}/confirm` — `control-room.alerts.confirm` — `App\Http\Controllers\ControlRoom\ControlRoomAlertController@confirm` — `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:476` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/alerts/{alert}/meta` — `control-room.alerts.meta` — `App\Http\Controllers\ControlRoom\ControlRoomAlertController@updateMeta` — `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:803` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/alerts/{alert}/note` — `control-room.alerts.note` — `App\Http\Controllers\ControlRoom\ControlRoomAlertController@addNote` — `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:768` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/alerts/{alert}/triage` — `control-room.alerts.triage` — `App\Http\Controllers\ControlRoom\ControlRoomAlertController@triage` — `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:524` — middleware `web, auth, permission:controlRoom.alerts.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php`.
- Exact render/action page relationships: `resources/js/pages/control-room/alerts/index.tsx`, `resources/js/pages/control-room/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
