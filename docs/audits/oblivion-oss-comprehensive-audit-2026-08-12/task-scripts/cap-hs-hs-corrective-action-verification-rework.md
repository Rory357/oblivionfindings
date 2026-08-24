# CAP-HS-HS-CORRECTIVE-ACTION-VERIFICATION-REWORK: Corrective action verification and rework

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-HS-CORRECTIVE-ACTION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST health-safety/events/{event}/corrective-actions/{action}/return` (`health-safety.events.corrective-actions.return`, action `returnForRework`). Source category: **rejected/returned**; controller `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:143-156`; `reason`.
3. Invoke only the owning control for `POST health-safety/events/{event}/corrective-actions/{action}/verify` (`health-safety.events.corrective-actions.verify`, action `verify`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:106-126`; `effectiveness_confirmed`.

## Source-applicable states and transitions

- **rejected/returned** is applicable only to `returnForRework` / `ROUTE-1102` at `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:143`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `verify` / `ROUTE-1104` at `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:106`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1102` / `returnForRework`: fields `reason`; success app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:155 `return back()->with('success', 'Corrective action returned for rework.');`.
- `ROUTE-1104` / `verify`: fields `effectiveness_confirmed`; success app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:125 `return back()->with('success', 'Corrective action verified.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:152 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:155 `return back()->with('success', 'Corrective action returned for rework.');`; app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:122 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:125 `return back()->with('success', 'Corrective action verified.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/events/{event}/corrective-actions/{action}/return` — `health-safety.events.corrective-actions.return` — `App\Http\Controllers\HealthSafety\HsCorrectiveActionController@returnForRework` — `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:143` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/events/{event}/corrective-actions/{action}/verify` — `health-safety.events.corrective-actions.verify` — `App\Http\Controllers\HealthSafety\HsCorrectiveActionController@verify` — `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php:106` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
