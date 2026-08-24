# OPS-JOB-BOARD: Job Board

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:job_board.viewAny|job_board.claim|shifts.viewAny|shifts.viewAssigned`, `permission:job_board.approve|shifts.manageAny`, `permission:job_board.claim|shifts.viewAssigned|shifts.manageAny`, `permission:job_board.create|shifts.manageAny`
- Owning module: Operations and rostering
- Legacy family: `OPS-JOB-BOARD`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/job-board` (`operations.job_board.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:job_board.viewAny|job_board.claim|shifts.viewAny|shifts.viewAssigned`, `permission:job_board.approve|shifts.manageAny`, `permission:job_board.claim|shifts.viewAssigned|shifts.manageAny`, `permission:job_board.create|shifts.manageAny`.
- Exact middleware atoms: `web`, `auth`, `permission:job_board.viewAny|job_board.claim|shifts.viewAny|shifts.viewAssigned`, `permission:job_board.approve|shifts.manageAny`, `permission:job_board.claim|shifts.viewAssigned|shifts.manageAny`, `permission:job_board.create|shifts.manageAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/job-board` (`operations.job_board.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/job-board/{position}/approve` (`operations.job_board.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Operations/JobBoardController.php:429-526`; no exact validation fields extracted.
3. Invoke only the owning control for `POST operations/job-board/{position}/claim` (`operations.job_board.claim`, action `claim`). Source category: **mutation outcome source gap (claim)**; controller `app/Http/Controllers/Operations/JobBoardController.php:349-427`; no exact validation fields extracted.
4. Invoke only the owning control for `POST operations/job-board/alerts/toggle` (`operations.job_board.alerts.toggle`, action `toggleAlerts`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/JobBoardController.php:333-347`; no exact validation fields extracted.
5. Invoke only the owning control for `POST operations/shifts/{shift}/open-position` (`operations.job_board.create`, action `createPosition`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/JobBoardController.php:262-328`; `shift_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2093` at `app/Http/Controllers/Operations/JobBoardController.php:25`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-2094` at `app/Http/Controllers/Operations/JobBoardController.php:429`; it is not runtime-observed.
- **mutation outcome source gap (claim)** is applicable only to `claim` / `ROUTE-2095` at `app/Http/Controllers/Operations/JobBoardController.php:349`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggleAlerts` / `ROUTE-2096` at `app/Http/Controllers/Operations/JobBoardController.php:333`; it is not runtime-observed.
- **created/recorded** is applicable only to `createPosition` / `ROUTE-2204` at `app/Http/Controllers/Operations/JobBoardController.php:262`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/job-board/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2093` / `index`: fields `q`.
- `ROUTE-2094` / `approve`: success app/Http/Controllers/Operations/JobBoardController.php:525 `return redirect()->back()->with('success', 'Claim approved and shift assigned.');`; failure app/Http/Controllers/Operations/JobBoardController.php:441 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:448 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:456 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:469 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:491 `throw \Illuminate\Validation\ValidationException::withMessages([`; app/Http/Controllers/Operations/JobBoardController.php:517 `throw $e;`.
- `ROUTE-2095` / `claim`: success app/Http/Controllers/Operations/JobBoardController.php:426 `return redirect()->back()->with('success', 'Position claimed.');`; failure app/Http/Controllers/Operations/JobBoardController.php:360 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:368 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:374 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:380 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:387 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:402 `throw \Illuminate\Validation\ValidationException::withMessages([`; app/Http/Controllers/Operations/JobBoardController.php:421 `throw $e;`.
- `ROUTE-2204` / `createPosition`: fields `shift_id`; success app/Http/Controllers/Operations/JobBoardController.php:327 `return redirect()->back()->with('success', 'Open position published.');`; failure app/Http/Controllers/Operations/JobBoardController.php:285 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:299 `throw ValidationException::withMessages([`; app/Http/Controllers/Operations/JobBoardController.php:318 `return redirect()->back()->withErrors([`.

## Failure and recovery paths

- `approve`: app/Http/Controllers/Operations/JobBoardController.php:441 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:448 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:456 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:469 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:491 `throw \Illuminate\Validation\ValidationException::withMessages([`; app/Http/Controllers/Operations/JobBoardController.php:517 `throw $e;`.
- `claim`: app/Http/Controllers/Operations/JobBoardController.php:360 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:368 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:374 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:380 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:387 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:402 `throw \Illuminate\Validation\ValidationException::withMessages([`; app/Http/Controllers/Operations/JobBoardController.php:421 `throw $e;`.
- `createPosition`: app/Http/Controllers/Operations/JobBoardController.php:285 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:299 `throw ValidationException::withMessages([`; app/Http/Controllers/Operations/JobBoardController.php:318 `return redirect()->back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/JobBoardController.php:496 `$position->update([`; app/Http/Controllers/Operations/JobBoardController.php:502 `$position->shift->update([`; app/Http/Controllers/Operations/JobBoardController.php:511 `->update(['status' => 'cancelled']);`; app/Http/Controllers/Operations/JobBoardController.php:407 `$position->update([`; app/Http/Controllers/Operations/JobBoardController.php:414 `$reservation->update([`; app/Http/Controllers/Operations/JobBoardController.php:339 `$auth->forceFill(['job_board_alerts_enabled' => $enabled])->save();`; app/Http/Controllers/Operations/JobBoardController.php:304 `ShiftOpenPosition::create([`; responses app/Http/Controllers/Operations/JobBoardController.php:216 `return inertia('operations/job-board/Index', [`; app/Http/Controllers/Operations/JobBoardController.php:441 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:448 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:456 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:469 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:525 `return redirect()->back()->with('success', 'Claim approved and shift assigned.');`; app/Http/Controllers/Operations/JobBoardController.php:360 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:368 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:374 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:380 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:387 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:426 `return redirect()->back()->with('success', 'Position claimed.');`; app/Http/Controllers/Operations/JobBoardController.php:341 `return redirect()->back()->with(`; app/Http/Controllers/Operations/JobBoardController.php:285 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:318 `return redirect()->back()->withErrors([`; app/Http/Controllers/Operations/JobBoardController.php:327 `return redirect()->back()->with('success', 'Open position published.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/job-board` — `operations.job_board.index` — `App\Http\Controllers\Operations\JobBoardController@index` — `app/Http/Controllers/Operations/JobBoardController.php:25` — middleware `web, auth, permission:job_board.viewAny|job_board.claim|shifts.viewAny|shifts.viewAssigned`
- `POST operations/job-board/{position}/approve` — `operations.job_board.approve` — `App\Http\Controllers\Operations\JobBoardController@approve` — `app/Http/Controllers/Operations/JobBoardController.php:429` — middleware `web, auth, permission:job_board.approve|shifts.manageAny`
- `POST operations/job-board/{position}/claim` — `operations.job_board.claim` — `App\Http\Controllers\Operations\JobBoardController@claim` — `app/Http/Controllers/Operations/JobBoardController.php:349` — middleware `web, auth, permission:job_board.claim|shifts.viewAssigned|shifts.manageAny`
- `POST operations/job-board/alerts/toggle` — `operations.job_board.alerts.toggle` — `App\Http\Controllers\Operations\JobBoardController@toggleAlerts` — `app/Http/Controllers/Operations/JobBoardController.php:333` — middleware `web, auth, permission:job_board.viewAny|job_board.claim|shifts.viewAny|shifts.viewAssigned`
- `POST operations/shifts/{shift}/open-position` — `operations.job_board.create` — `App\Http\Controllers\Operations\JobBoardController@createPosition` — `app/Http/Controllers/Operations/JobBoardController.php:262` — middleware `web, auth, permission:job_board.create|shifts.manageAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/JobBoardController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/job-board/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
