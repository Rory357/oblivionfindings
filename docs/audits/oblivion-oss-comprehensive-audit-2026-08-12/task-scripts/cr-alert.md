# CR-ALERT: Alert

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.view`, `permission:controlRoom.alerts.manage`, `permission:controlRoom.alerts.assign`
- Owning module: Control Room
- Legacy family: `CR-ALERT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `control-room/integration-alerts` (`control-room.integration-alerts.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.view`, `permission:controlRoom.alerts.manage`, `permission:controlRoom.alerts.assign`.
- Exact middleware atoms: `web`, `auth`, `permission:controlRoom.alerts.view`, `permission:controlRoom.alerts.manage`, `permission:controlRoom.alerts.assign`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD control-room/integration-alerts` (`control-room.integration-alerts.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST control-room/integration-alerts/{alert}/ack` (`control-room.integration-alerts.ack`, action `acknowledge`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/ControlRoom/AlertController.php:172-189`; no exact validation fields extracted.
3. Invoke only the owning control for `POST control-room/integration-alerts/{alert}/assign` (`control-room.integration-alerts.assign`, action `assign`). Source category: **assigned**; controller `app/Http/Controllers/ControlRoom/AlertController.php:194-223`; `user_id`.
4. Invoke only the owning control for `POST control-room/integration-alerts/{alert}/close` (`control-room.integration-alerts.close`, action `close`). Source category: **completed/closed/released**; controller `app/Http/Controllers/ControlRoom/AlertController.php:228-251`; `close_reason`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0268` at `app/Http/Controllers/ControlRoom/AlertController.php:31`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledge` / `ROUTE-0269` at `app/Http/Controllers/ControlRoom/AlertController.php:172`; it is not runtime-observed.
- **assigned** is applicable only to `assign` / `ROUTE-0270` at `app/Http/Controllers/ControlRoom/AlertController.php:194`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `close` / `ROUTE-0271` at `app/Http/Controllers/ControlRoom/AlertController.php:228`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/control-room/alerts/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0269` / `acknowledge`: failure app/Http/Controllers/ControlRoom/AlertController.php:179 `return back()->withErrors(['alert' => "Cannot acknowledge an alert in '{$alert->status}' status."]);`.
- `ROUTE-0270` / `assign`: fields `user_id`; failure app/Http/Controllers/ControlRoom/AlertController.php:201 `return back()->withErrors(['alert' => "Cannot assign an alert in '{$alert->status}' status."]);`.
- `ROUTE-0271` / `close`: fields `close_reason`; failure app/Http/Controllers/ControlRoom/AlertController.php:235 `return back()->withErrors(['alert' => "Cannot resolve an alert in '{$alert->status}' status."]);`.

## Failure and recovery paths

- `acknowledge`: app/Http/Controllers/ControlRoom/AlertController.php:179 `return back()->withErrors(['alert' => "Cannot acknowledge an alert in '{$alert->status}' status."]);`.
- `assign`: app/Http/Controllers/ControlRoom/AlertController.php:201 `return back()->withErrors(['alert' => "Cannot assign an alert in '{$alert->status}' status."]);`.
- `close`: app/Http/Controllers/ControlRoom/AlertController.php:235 `return back()->withErrors(['alert' => "Cannot resolve an alert in '{$alert->status}' status."]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ControlRoom/AlertController.php:182 `$alert->update([`; app/Http/Controllers/ControlRoom/AlertController.php:215 `$alert->update([`; app/Http/Controllers/ControlRoom/AlertController.php:242 `$alert->update([`; responses app/Http/Controllers/ControlRoom/AlertController.php:145 `return Inertia::render('control-room/alerts/index', [`; app/Http/Controllers/ControlRoom/AlertController.php:179 `return back()->withErrors(['alert' => "Cannot acknowledge an alert in '{$alert->status}' status."]);`; app/Http/Controllers/ControlRoom/AlertController.php:188 `return redirect()->back();`; app/Http/Controllers/ControlRoom/AlertController.php:201 `return back()->withErrors(['alert' => "Cannot assign an alert in '{$alert->status}' status."]);`; app/Http/Controllers/ControlRoom/AlertController.php:222 `return redirect()->back();`; app/Http/Controllers/ControlRoom/AlertController.php:235 `return back()->withErrors(['alert' => "Cannot resolve an alert in '{$alert->status}' status."]);`; app/Http/Controllers/ControlRoom/AlertController.php:250 `return redirect()->back();`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD control-room/integration-alerts` — `control-room.integration-alerts.index` — `App\Http\Controllers\ControlRoom\AlertController@index` — `app/Http/Controllers/ControlRoom/AlertController.php:31` — middleware `web, auth, permission:controlRoom.alerts.view`
- `POST control-room/integration-alerts/{alert}/ack` — `control-room.integration-alerts.ack` — `App\Http\Controllers\ControlRoom\AlertController@acknowledge` — `app/Http/Controllers/ControlRoom/AlertController.php:172` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/integration-alerts/{alert}/assign` — `control-room.integration-alerts.assign` — `App\Http\Controllers\ControlRoom\AlertController@assign` — `app/Http/Controllers/ControlRoom/AlertController.php:194` — middleware `web, auth, permission:controlRoom.alerts.assign`
- `POST control-room/integration-alerts/{alert}/close` — `control-room.integration-alerts.close` — `App\Http\Controllers\ControlRoom\AlertController@close` — `app/Http/Controllers/ControlRoom/AlertController.php:228` — middleware `web, auth, permission:controlRoom.alerts.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/AlertController.php`.
- Exact render/action page relationships: `resources/js/pages/control-room/alerts/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
