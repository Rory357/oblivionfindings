# CAP-MED-BREAK-GLASS-GRANT-REVIEW: Break-glass grant review extension and revocation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.breakglass|medications.audit.view`, `permission:medications.audit.view`
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

- Actor satisfying exact route middleware `auth`, `permission:medications.breakglass|medications.audit.view`, `permission:medications.audit.view`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.breakglass|medications.audit.view`, `permission:medications.audit.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `DELETE emar/clients/{client}/break-glass/{access}` (`emar.clients.break_glass.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/BreakGlassController.php:183-203`; no exact validation fields extracted.
3. Invoke only the owning control for `POST emar/clients/{client}/break-glass/{access}/extend` (`emar.clients.break_glass.extend`, action `extend`). Source category: **mutation outcome source gap (extend)**; controller `app/Http/Controllers/BreakGlassController.php:70-96`; no exact validation fields extracted.
4. Invoke only the owning control for `POST emar/clients/{client}/break-glass/{access}/review` (`emar.clients.break_glass.review`, action `review`). Source category: **mutation outcome source gap (review)**; controller `app/Http/Controllers/BreakGlassController.php:98-128`; `review_outcome`.

## Source-applicable states and transitions

- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0340` at `app/Http/Controllers/BreakGlassController.php:183`; it is not runtime-observed.
- **mutation outcome source gap (extend)** is applicable only to `extend` / `ROUTE-0341` at `app/Http/Controllers/BreakGlassController.php:70`; it is not runtime-observed.
- **mutation outcome source gap (review)** is applicable only to `review` / `ROUTE-0342` at `app/Http/Controllers/BreakGlassController.php:98`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0340` / `destroy`: success app/Http/Controllers/BreakGlassController.php:202 `return back()->with('success', 'Break-glass access revoked.');`.
- `ROUTE-0341` / `extend`: success app/Http/Controllers/BreakGlassController.php:95 `return back()->with('success', 'Break-glass access extended.');`.
- `ROUTE-0342` / `review`: fields `review_outcome`; success app/Http/Controllers/BreakGlassController.php:127 `return back()->with('success', 'Break-glass review saved.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/BreakGlassController.php:194 `$access->forceFill(['revoked_by' => $user->id])->save();`; app/Http/Controllers/BreakGlassController.php:195 `$access->delete();`; app/Http/Controllers/BreakGlassController.php:93 `$access->forceFill(['expires_at' => $newExpiry])->save();`; app/Http/Controllers/BreakGlassController.php:125 `])->save();`; responses app/Http/Controllers/BreakGlassController.php:202 `return back()->with('success', 'Break-glass access revoked.');`; app/Http/Controllers/BreakGlassController.php:80 `return back()->with('error', 'This grant has already ended and cannot be extended.');`; app/Http/Controllers/BreakGlassController.php:90 `return back()->with('error', 'Already at the maximum '.round($policy->max_minutes / 60, 1).'-hour duration.');`; app/Http/Controllers/BreakGlassController.php:95 `return back()->with('success', 'Break-glass access extended.');`; app/Http/Controllers/BreakGlassController.php:127 `return back()->with('success', 'Break-glass review saved.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `DELETE emar/clients/{client}/break-glass/{access}` — `emar.clients.break_glass.destroy` — `App\Http\Controllers\BreakGlassController@destroy` — `app/Http/Controllers/BreakGlassController.php:183` — middleware `web, auth, permission:medications.breakglass|medications.audit.view`
- `POST emar/clients/{client}/break-glass/{access}/extend` — `emar.clients.break_glass.extend` — `App\Http\Controllers\BreakGlassController@extend` — `app/Http/Controllers/BreakGlassController.php:70` — middleware `web, auth, permission:medications.breakglass|medications.audit.view`
- `POST emar/clients/{client}/break-glass/{access}/review` — `emar.clients.break_glass.review` — `App\Http\Controllers\BreakGlassController@review` — `app/Http/Controllers/BreakGlassController.php:98` — middleware `web, auth, permission:medications.audit.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/BreakGlassController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
