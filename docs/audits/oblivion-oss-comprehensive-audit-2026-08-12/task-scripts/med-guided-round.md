# MED-GUIDED-ROUND: Guided Round

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.administer.record|clients.update|medications.orders.manage`
- Owning module: eMAR and medications
- Legacy family: `MED-GUIDED-ROUND`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/rounds/{round}/guided` (`meds.round.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.administer.record|clients.update|medications.orders.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.administer.record|clients.update|medications.orders.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/rounds/{round}/guided` (`meds.round.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST emar/rounds/{round}/guided/complete` (`meds.round.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Emar/GuidedRoundController.php:270-286`; no exact validation fields extracted.
3. Invoke only the owning control for `POST emar/rounds/{round}/guided/items/{medication}` (`meds.round.administer`, action `administer`). Source category: **mutation outcome source gap (administer)**; controller `app/Http/Controllers/Emar/GuidedRoundController.php:63-264`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-0420` at `app/Http/Controllers/Emar/GuidedRoundController.php:38`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-0421` at `app/Http/Controllers/Emar/GuidedRoundController.php:270`; it is not runtime-observed.
- **mutation outcome source gap (administer)** is applicable only to `administer` / `ROUTE-0422` at `app/Http/Controllers/Emar/GuidedRoundController.php:63`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0422` / `administer`: failure app/Http/Controllers/Emar/GuidedRoundController.php:112 `return back()->withErrors([`; app/Http/Controllers/Emar/GuidedRoundController.php:203 `return back()->withErrors([`.

## Failure and recovery paths

- `administer`: app/Http/Controllers/Emar/GuidedRoundController.php:112 `return back()->withErrors([`; app/Http/Controllers/Emar/GuidedRoundController.php:203 `return back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/GuidedRoundController.php:280 `])->save();`; app/Http/Controllers/Emar/GuidedRoundController.php:212 `$admin->save();`; responses app/Http/Controllers/Emar/GuidedRoundController.php:51 `return redirect()->route('emar.rounds', ['date' => $dateStr, 'guided' => $round->id]);`; app/Http/Controllers/Emar/GuidedRoundController.php:285 `return redirect()->route('meds.round.show', $round);`; app/Http/Controllers/Emar/GuidedRoundController.php:89 `return response()->json($cached);`; app/Http/Controllers/Emar/GuidedRoundController.php:92 `return back()->with('status', 'Dose already recorded for this round.');`; app/Http/Controllers/Emar/GuidedRoundController.php:100 `return response()->json(`; app/Http/Controllers/Emar/GuidedRoundController.php:112 `return back()->withErrors([`; app/Http/Controllers/Emar/GuidedRoundController.php:119 `return DB::transaction(function () use ($request, $round, $medication, $data, $backendStatus, $scheduled, $user) {`; app/Http/Controllers/Emar/GuidedRoundController.php:130 `return response()->json(`; app/Http/Controllers/Emar/GuidedRoundController.php:140 `return response()->json(`; app/Http/Controllers/Emar/GuidedRoundController.php:159 `return back()->with('status', 'Dose already recorded for this round.');`; app/Http/Controllers/Emar/GuidedRoundController.php:191 `return response()->json(`; app/Http/Controllers/Emar/GuidedRoundController.php:203 `return back()->withErrors([`; app/Http/Controllers/Emar/GuidedRoundController.php:259 `return response()->json($payload);`; app/Http/Controllers/Emar/GuidedRoundController.php:262 `return back();`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/rounds/{round}/guided` — `meds.round.show` — `App\Http\Controllers\Emar\GuidedRoundController@show` — `app/Http/Controllers/Emar/GuidedRoundController.php:38` — middleware `web, auth, permission:medications.administer.record|clients.update|medications.orders.manage`
- `POST emar/rounds/{round}/guided/complete` — `meds.round.complete` — `App\Http\Controllers\Emar\GuidedRoundController@complete` — `app/Http/Controllers/Emar/GuidedRoundController.php:270` — middleware `web, auth, permission:medications.administer.record|clients.update|medications.orders.manage`
- `POST emar/rounds/{round}/guided/items/{medication}` — `meds.round.administer` — `App\Http\Controllers\Emar\GuidedRoundController@administer` — `app/Http/Controllers/Emar/GuidedRoundController.php:63` — middleware `web, auth, permission:medications.administer.record|clients.update|medications.orders.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/GuidedRoundController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
