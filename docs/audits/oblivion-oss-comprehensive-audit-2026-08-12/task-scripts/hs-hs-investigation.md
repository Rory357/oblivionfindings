# HS-HS-INVESTIGATION: Hs Investigation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-HS-INVESTIGATION`
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
2. Invoke only the owning control for `POST health-safety/events/{event}/investigations` (`health-safety.events.investigations.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/HsInvestigationController.php:24-47`; `methodology`.
3. Invoke only the owning control for `POST health-safety/events/{event}/investigations/{investigation}/complete` (`health-safety.events.investigations.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/HealthSafety/HsInvestigationController.php:103-121`; `approved_by_id`.
4. Invoke only the owning control for `POST health-safety/events/{event}/investigations/{investigation}/findings` (`health-safety.events.investigations.findings`, action `recordFindings`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/HsInvestigationController.php:50-70`; `immediate_causes`.
5. Invoke only the owning control for `POST health-safety/events/{event}/investigations/{investigation}/return` (`health-safety.events.investigations.return`, action `returnForRework`). Source category: **rejected/returned**; controller `app/Http/Controllers/HealthSafety/HsInvestigationController.php:87-100`; `review_notes`.
6. Invoke only the owning control for `POST health-safety/events/{event}/investigations/{investigation}/submit` (`health-safety.events.investigations.submit`, action `submit`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/HsInvestigationController.php:73-84`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-1105` at `app/Http/Controllers/HealthSafety/HsInvestigationController.php:24`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-1106` at `app/Http/Controllers/HealthSafety/HsInvestigationController.php:103`; it is not runtime-observed.
- **created/recorded** is applicable only to `recordFindings` / `ROUTE-1107` at `app/Http/Controllers/HealthSafety/HsInvestigationController.php:50`; it is not runtime-observed.
- **rejected/returned** is applicable only to `returnForRework` / `ROUTE-1108` at `app/Http/Controllers/HealthSafety/HsInvestigationController.php:87`; it is not runtime-observed.
- **created/recorded** is applicable only to `submit` / `ROUTE-1110` at `app/Http/Controllers/HealthSafety/HsInvestigationController.php:73`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1105` / `store`: fields `methodology`; success app/Http/Controllers/HealthSafety/HsInvestigationController.php:46 `return back()->with('success', 'Investigation started.');`.
- `ROUTE-1106` / `complete`: fields `approved_by_id`; success app/Http/Controllers/HealthSafety/HsInvestigationController.php:120 `return back()->with('success', 'Investigation completed.');`.
- `ROUTE-1107` / `recordFindings`: fields `immediate_causes`; success app/Http/Controllers/HealthSafety/HsInvestigationController.php:69 `return back()->with('success', 'Findings recorded.');`.
- `ROUTE-1108` / `returnForRework`: fields `review_notes`; success app/Http/Controllers/HealthSafety/HsInvestigationController.php:99 `return back()->with('success', 'Investigation returned for rework.');`.
- `ROUTE-1110` / `submit`: success app/Http/Controllers/HealthSafety/HsInvestigationController.php:83 `return back()->with('success', 'Investigation submitted for review.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/HsInvestigationController.php:35 `$investigation = $this->service->create($event, [`; responses app/Http/Controllers/HealthSafety/HsInvestigationController.php:43 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsInvestigationController.php:46 `return back()->with('success', 'Investigation started.');`; app/Http/Controllers/HealthSafety/HsInvestigationController.php:117 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsInvestigationController.php:120 `return back()->with('success', 'Investigation completed.');`; app/Http/Controllers/HealthSafety/HsInvestigationController.php:66 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsInvestigationController.php:69 `return back()->with('success', 'Findings recorded.');`; app/Http/Controllers/HealthSafety/HsInvestigationController.php:96 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsInvestigationController.php:99 `return back()->with('success', 'Investigation returned for rework.');`; app/Http/Controllers/HealthSafety/HsInvestigationController.php:80 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsInvestigationController.php:83 `return back()->with('success', 'Investigation submitted for review.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/events/{event}/investigations` — `health-safety.events.investigations.store` — `App\Http\Controllers\HealthSafety\HsInvestigationController@store` — `app/Http/Controllers/HealthSafety/HsInvestigationController.php:24` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/events/{event}/investigations/{investigation}/complete` — `health-safety.events.investigations.complete` — `App\Http\Controllers\HealthSafety\HsInvestigationController@complete` — `app/Http/Controllers/HealthSafety/HsInvestigationController.php:103` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/events/{event}/investigations/{investigation}/findings` — `health-safety.events.investigations.findings` — `App\Http\Controllers\HealthSafety\HsInvestigationController@recordFindings` — `app/Http/Controllers/HealthSafety/HsInvestigationController.php:50` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/events/{event}/investigations/{investigation}/return` — `health-safety.events.investigations.return` — `App\Http\Controllers\HealthSafety\HsInvestigationController@returnForRework` — `app/Http/Controllers/HealthSafety/HsInvestigationController.php:87` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/events/{event}/investigations/{investigation}/submit` — `health-safety.events.investigations.submit` — `App\Http\Controllers\HealthSafety\HsInvestigationController@submit` — `app/Http/Controllers/HealthSafety/HsInvestigationController.php:73` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/HsInvestigationController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
