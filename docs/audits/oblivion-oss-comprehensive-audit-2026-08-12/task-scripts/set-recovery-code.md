# SET-RECOVERY-CODE: Recovery Code

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth:web`
- Owning module: Settings and system access
- Legacy family: `SET-RECOVERY-CODE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `user/two-factor-recovery-codes` (`two-factor.recovery-codes`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth:web`.
- Exact middleware atoms: `web`, `auth:web`, `password.confirm`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD user/two-factor-recovery-codes` (`two-factor.recovery-codes`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST user/two-factor-recovery-codes` (`two-factor.regenerate-recovery-codes`, action `store`). Source category: **created/recorded**; controller `Laravel\Fortify\Http\Controllers\RecoveryCodeController`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-3018` at `Laravel\Fortify\Http\Controllers\RecoveryCodeController`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-3019` at `Laravel\Fortify\Http\Controllers\RecoveryCodeController`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; no exact response extracted. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD user/two-factor-recovery-codes` — `two-factor.recovery-codes` — `Laravel\Fortify\Http\Controllers\RecoveryCodeController@index` — `Laravel\Fortify\Http\Controllers\RecoveryCodeController:line unresolved` — middleware `web, auth:web, password.confirm`
- `POST user/two-factor-recovery-codes` — `two-factor.regenerate-recovery-codes` — `Laravel\Fortify\Http\Controllers\RecoveryCodeController@store` — `Laravel\Fortify\Http\Controllers\RecoveryCodeController:line unresolved` — middleware `web, auth:web, password.confirm`

## Source anchors and limits

- Backend anchor: `Laravel\Fortify\Http\Controllers\RecoveryCodeController`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
