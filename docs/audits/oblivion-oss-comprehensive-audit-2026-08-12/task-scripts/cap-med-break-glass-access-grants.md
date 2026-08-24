# CAP-MED-BREAK-GLASS-ACCESS-GRANTS: Emergency access grant and removal across client surfaces

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.breakglass`, `permission:medications.breakglass|medications.audit.view`
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

- Actor satisfying exact route middleware `auth`, `permission:medications.breakglass`, `permission:medications.breakglass|medications.audit.view`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.breakglass`, `permission:medications.breakglass|medications.audit.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST clients/{client}/break-glass` (`clients.break_glass.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/BreakGlassController.php:26-68`; `reason`.
3. Invoke only the owning control for `DELETE clients/{client}/break-glass/{access}` (`clients.break_glass.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/BreakGlassController.php:183-203`; no exact validation fields extracted.
4. Invoke only the owning control for `POST operations/clients/{client}/break-glass` (`operations.clients.break_glass.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/BreakGlassController.php:26-68`; `reason`.
5. Invoke only the owning control for `DELETE operations/clients/{client}/break-glass/{access}` (`operations.clients.break_glass.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/BreakGlassController.php:183-203`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-0137` at `app/Http/Controllers/BreakGlassController.php:26`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0138` at `app/Http/Controllers/BreakGlassController.php:183`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1944` at `app/Http/Controllers/BreakGlassController.php:26`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1945` at `app/Http/Controllers/BreakGlassController.php:183`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0137` / `store`: fields `reason`; success app/Http/Controllers/BreakGlassController.php:67 `return back()->with('success', 'Break-glass access granted.');`.
- `ROUTE-0138` / `destroy`: success app/Http/Controllers/BreakGlassController.php:202 `return back()->with('success', 'Break-glass access revoked.');`.
- `ROUTE-1944` / `store`: fields `reason`; success app/Http/Controllers/BreakGlassController.php:67 `return back()->with('success', 'Break-glass access granted.');`.
- `ROUTE-1945` / `destroy`: success app/Http/Controllers/BreakGlassController.php:202 `return back()->with('success', 'Break-glass access revoked.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/BreakGlassController.php:50 `$access = ClientBreakGlassAccess::create([`; app/Http/Controllers/BreakGlassController.php:194 `$access->forceFill(['revoked_by' => $user->id])->save();`; app/Http/Controllers/BreakGlassController.php:195 `$access->delete();`; responses app/Http/Controllers/BreakGlassController.php:67 `return back()->with('success', 'Break-glass access granted.');`; app/Http/Controllers/BreakGlassController.php:202 `return back()->with('success', 'Break-glass access revoked.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST clients/{client}/break-glass` — `clients.break_glass.store` — `App\Http\Controllers\BreakGlassController@store` — `app/Http/Controllers/BreakGlassController.php:26` — middleware `web, auth, permission:medications.breakglass`
- `DELETE clients/{client}/break-glass/{access}` — `clients.break_glass.destroy` — `App\Http\Controllers\BreakGlassController@destroy` — `app/Http/Controllers/BreakGlassController.php:183` — middleware `web, auth, permission:medications.breakglass|medications.audit.view`
- `POST operations/clients/{client}/break-glass` — `operations.clients.break_glass.store` — `App\Http\Controllers\BreakGlassController@store` — `app/Http/Controllers/BreakGlassController.php:26` — middleware `web, auth, permission:medications.breakglass`
- `DELETE operations/clients/{client}/break-glass/{access}` — `operations.clients.break_glass.destroy` — `App\Http\Controllers\BreakGlassController@destroy` — `app/Http/Controllers/BreakGlassController.php:183` — middleware `web, auth, permission:medications.breakglass|medications.audit.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/BreakGlassController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
