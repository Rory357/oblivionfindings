# CAP-MED-BREAK-GLASS-POLICY-FLAGS: Break-glass policy and alert-flag governance

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.audit.view`
- Owning module: eMAR and medications
- Legacy family: `MED-BREAK-GLASS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.audit.view`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.audit.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST emar/break-glass-flags/dismiss` (`emar.break_glass.flag.dismiss`, action `dismissFlag`). Source category: **mutation outcome source gap (dismissFlag)**; controller `app/Http/Controllers/BreakGlassController.php:162-181`; `type`.
3. Invoke only the owning control for `PUT emar/break-glass-policy` (`emar.break_glass.policy.update`, action `updatePolicy`). Source category: **updated/revised**; controller `app/Http/Controllers/BreakGlassController.php:130-154`; `default_minutes`.

## Source-applicable states and transitions

- **mutation outcome source gap (dismissFlag)** is applicable only to `dismissFlag` / `ROUTE-0336` at `app/Http/Controllers/BreakGlassController.php:162`; it is not runtime-observed.
- **updated/revised** is applicable only to `updatePolicy` / `ROUTE-0337` at `app/Http/Controllers/BreakGlassController.php:130`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0336` / `dismissFlag`: fields `type`; success app/Http/Controllers/BreakGlassController.php:180 `return back()->with('success', 'Signal acknowledged.');`.
- `ROUTE-0337` / `updatePolicy`: fields `default_minutes`; success app/Http/Controllers/BreakGlassController.php:153 `return back()->with('success', 'Break-glass policy updated.');`; failure app/Http/Controllers/BreakGlassController.php:146 `throw ValidationException::withMessages([`.

## Failure and recovery paths

- `updatePolicy`: app/Http/Controllers/BreakGlassController.php:146 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/BreakGlassController.php:175 `BreakGlassFlagDismissal::updateOrCreate(`; app/Http/Controllers/BreakGlassController.php:151 `BreakGlassPolicy::updateOrCreate(['organization_id' => $user->organization_id], $data);`; responses app/Http/Controllers/BreakGlassController.php:180 `return back()->with('success', 'Signal acknowledged.');`; app/Http/Controllers/BreakGlassController.php:153 `return back()->with('success', 'Break-glass policy updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST emar/break-glass-flags/dismiss` — `emar.break_glass.flag.dismiss` — `App\Http\Controllers\BreakGlassController@dismissFlag` — `app/Http/Controllers/BreakGlassController.php:162` — middleware `web, auth, permission:medications.audit.view`
- `PUT emar/break-glass-policy` — `emar.break_glass.policy.update` — `App\Http\Controllers\BreakGlassController@updatePolicy` — `app/Http/Controllers/BreakGlassController.php:130` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/BreakGlassController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
