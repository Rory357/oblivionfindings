# CR-CONTROL-ROOM-PLAYBOOK: Control Room Playbook

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.manage`, `permission:controlRoom.viewAny`
- Owning module: Control Room
- Legacy family: `CR-CONTROL-ROOM-PLAYBOOK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `control-room/playbooks` (`control-room.playbooks.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.manage`, `permission:controlRoom.viewAny`.
- Exact middleware atoms: `web`, `auth`, `permission:controlRoom.alerts.manage`, `permission:controlRoom.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD control-room/playbooks` (`control-room.playbooks.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD control-room/playbooks/{playbook}` (`control-room.playbooks.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:73-166`.
3. Invoke only the owning control for `POST control-room/alerts/{alert}/playbook/advance` (`control-room.alerts.playbook.advance`, action `advanceStep`). Source category: **mutation outcome source gap (advanceStep)**; controller `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:458-507`; `notes`.
4. Invoke only the owning control for `POST control-room/alerts/{alert}/playbook/skip` (`control-room.alerts.playbook.skip`, action `skipStep`). Source category: **rejected/returned**; controller `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:512-562`; `reason`.
5. Invoke only the owning control for `POST control-room/alerts/{alert}/playbook/start` (`control-room.alerts.playbook.start`, action `startRun`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:406-453`; `playbook_id`.
6. Invoke only the owning control for `POST control-room/playbooks` (`control-room.playbooks.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:171-254`; `name`.
7. Invoke only the owning control for `PUT control-room/playbooks/{playbook}` (`control-room.playbooks.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:259-359`; `name`.
8. Invoke only the owning control for `POST control-room/playbooks/{playbook}/toggle-active` (`control-room.playbooks.toggle-active`, action `toggleActive`). Source category: **updated/revised**; controller `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:385-401`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (advanceStep)** is applicable only to `advanceStep` / `ROUTE-0230` at `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:458`; it is not runtime-observed.
- **rejected/returned** is applicable only to `skipStep` / `ROUTE-0231` at `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:512`; it is not runtime-observed.
- **created/recorded** is applicable only to `startRun` / `ROUTE-0232` at `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:406`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-0279` at `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:21`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0280` at `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:171`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0281` at `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:73`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0282` at `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:259`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggleActive` / `ROUTE-0283` at `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:385`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/control-room/playbooks/index.tsx`, `resources/js/pages/control-room/playbooks/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0230` / `advanceStep`: fields `notes`; success app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:497 `return back()->with('success', 'Playbook run completed.');`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:506 `return back()->with('success', 'Step completed, advanced to next step.');`.
- `ROUTE-0231` / `skipStep`: fields `reason`; success app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:561 `return back()->with('success', 'Step skipped.');`; failure app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:528 `return back()->withErrors(['step' => 'No active step to skip.']);`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:534 `return back()->withErrors(['step' => 'This step is required and blocking. It cannot be skipped.']);`.
- `ROUTE-0232` / `startRun`: fields `playbook_id`; success app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:452 `return back()->with('success', 'Playbook run started.');`; failure app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:418 `return back()->withErrors(['playbook' => 'Cannot start an inactive playbook.']);`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:425 `return back()->withErrors(['playbook' => 'Alert already has an active playbook run.']);`.
- `ROUTE-0280` / `store`: fields `name`; success app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:253 `->with('success', 'Playbook created.');`.
- `ROUTE-0282` / `update`: fields `name`; success app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:358 `return back()->with('success', 'Playbook updated to version ' . $playbook->fresh()->version . '.');`.
- `ROUTE-0283` / `toggleActive`: success app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:400 `return back()->with('success', $playbook->is_active ? 'Playbook activated.' : 'Playbook deactivated.');`.

## Failure and recovery paths

- `skipStep`: app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:528 `return back()->withErrors(['step' => 'No active step to skip.']);`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:534 `return back()->withErrors(['step' => 'This step is required and blocking. It cannot be skipped.']);`.
- `startRun`: app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:418 `return back()->withErrors(['playbook' => 'Cannot start an inactive playbook.']);`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:425 `return back()->withErrors(['playbook' => 'Alert already has an active playbook run.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:547 `$nextStep->update([`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:551 `$run->update(['current_step' => $nextStep->order]);`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:430 `$run = PlaybookRun::create([`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:441 `$alert->update(['playbook_run_id' => $run->id]);`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:206 `$playbook = Playbook::create([`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:229 `PlaybookStep::create([`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:295 `$playbook->update([`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:317 `$playbook->steps()->whereNotIn('id', $existingStepIds)->delete();`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:323 `->update([`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:336 `PlaybookStep::create([`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:390 `$playbook->update([`; responses app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:497 `return back()->with('success', 'Playbook run completed.');`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:506 `return back()->with('success', 'Step completed, advanced to next step.');`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:528 `return back()->withErrors(['step' => 'No active step to skip.']);`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:534 `return back()->withErrors(['step' => 'This step is required and blocking. It cannot be skipped.']);`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:561 `return back()->with('success', 'Step skipped.');`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:418 `return back()->withErrors(['playbook' => 'Cannot start an inactive playbook.']);`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:425 `return back()->withErrors(['playbook' => 'Alert already has an active playbook run.']);`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:443 `return $run;`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:452 `return back()->with('success', 'Playbook run started.');`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:59 `return Inertia::render('control-room/playbooks/index', [`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:244 `return $playbook;`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:252 `return redirect()->route('control-room.playbooks.show', $playbook)`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:115 `return Inertia::render('control-room/playbooks/show', [`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:358 `return back()->with('success', 'Playbook updated to version ' . $playbook->fresh()->version . '.');`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:400 `return back()->with('success', $playbook->is_active ? 'Playbook activated.' : 'Playbook deactivated.');`; audit calls app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:492 `AuditLogger::log('controlRoom.playbook.runCompleted', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:500 `AuditLogger::log('controlRoom.playbook.advanceStep', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:556 `AuditLogger::log('controlRoom.playbook.skipStep', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:446 `AuditLogger::log('controlRoom.playbook.startRun', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:247 `AuditLogger::log('controlRoom.playbook.create', $playbook, [`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:353 `AuditLogger::log('controlRoom.playbook.update', $playbook, [`; app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:395 `AuditLogger::log('controlRoom.playbook.toggleActive', $playbook, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST control-room/alerts/{alert}/playbook/advance` — `control-room.alerts.playbook.advance` — `App\Http\Controllers\ControlRoom\ControlRoomPlaybookController@advanceStep` — `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:458` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/alerts/{alert}/playbook/skip` — `control-room.alerts.playbook.skip` — `App\Http\Controllers\ControlRoom\ControlRoomPlaybookController@skipStep` — `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:512` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/alerts/{alert}/playbook/start` — `control-room.alerts.playbook.start` — `App\Http\Controllers\ControlRoom\ControlRoomPlaybookController@startRun` — `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:406` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `GET|HEAD control-room/playbooks` — `control-room.playbooks.index` — `App\Http\Controllers\ControlRoom\ControlRoomPlaybookController@index` — `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:21` — middleware `web, auth, permission:controlRoom.viewAny`
- `POST control-room/playbooks` — `control-room.playbooks.store` — `App\Http\Controllers\ControlRoom\ControlRoomPlaybookController@store` — `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:171` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `GET|HEAD control-room/playbooks/{playbook}` — `control-room.playbooks.show` — `App\Http\Controllers\ControlRoom\ControlRoomPlaybookController@show` — `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:73` — middleware `web, auth, permission:controlRoom.viewAny`
- `PUT control-room/playbooks/{playbook}` — `control-room.playbooks.update` — `App\Http\Controllers\ControlRoom\ControlRoomPlaybookController@update` — `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:259` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/playbooks/{playbook}/toggle-active` — `control-room.playbooks.toggle-active` — `App\Http\Controllers\ControlRoom\ControlRoomPlaybookController@toggleActive` — `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:385` — middleware `web, auth, permission:controlRoom.alerts.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php`.
- Exact render/action page relationships: `resources/js/pages/control-room/playbooks/index.tsx`, `resources/js/pages/control-room/playbooks/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
