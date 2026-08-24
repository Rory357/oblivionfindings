# INC-SAFEGUARDING-INVESTIGATION: Safeguarding Investigation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:safeguarding.investigate`
- Owning module: Incidents and safeguarding
- Legacy family: `INC-SAFEGUARDING-INVESTIGATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:safeguarding.investigate`.
- Exact middleware atoms: `web`, `auth`, `permission:safeguarding.investigate`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST safeguarding/{concern}/investigations` (`safeguarding.investigations.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/SafeguardingInvestigationController.php:16-45`; `investigation_type`, `lead_investigator_id`, `started_at`, `target_completion_date`, `terms_of_reference`, `methodology`.
3. Invoke only the owning control for `PUT safeguarding/{concern}/investigations/{investigation}` (`safeguarding.investigations.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/SafeguardingInvestigationController.php:50-84`; `evidence_collected`, `evidence_summary`, `interviews_conducted`, `findings`, `outcome`, `recommendations`, `completed_at`, `report_completed`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2517` at `app/Http/Controllers/SafeguardingInvestigationController.php:16`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2518` at `app/Http/Controllers/SafeguardingInvestigationController.php:50`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2517` / `store`: fields `investigation_type`, `lead_investigator_id`, `started_at`, `target_completion_date`, `terms_of_reference`, `methodology`; success app/Http/Controllers/SafeguardingInvestigationController.php:44 `return back()->with('success', 'Investigation created successfully.');`; failure app/Http/Controllers/SafeguardingInvestigationController.php:24 `return back()->withErrors(['investigation' => 'Triage the concern first.']);`.
- `ROUTE-2518` / `update`: fields `evidence_collected`, `evidence_summary`, `interviews_conducted`, `findings`, `outcome`, `recommendations`, `completed_at`, `report_completed`; success app/Http/Controllers/SafeguardingInvestigationController.php:83 `return back()->with('success', 'Investigation updated successfully.');`.

## Failure and recovery paths

- `store`: app/Http/Controllers/SafeguardingInvestigationController.php:24 `return back()->withErrors(['investigation' => 'Triage the concern first.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/SafeguardingInvestigationController.php:40 `SafeguardingInvestigation::create($validated);`; app/Http/Controllers/SafeguardingInvestigationController.php:42 `$concern->update(['status' => 'investigating']);`; app/Http/Controllers/SafeguardingInvestigationController.php:81 `$investigation->update($validated);`; responses app/Http/Controllers/SafeguardingInvestigationController.php:24 `return back()->withErrors(['investigation' => 'Triage the concern first.']);`; app/Http/Controllers/SafeguardingInvestigationController.php:44 `return back()->with('success', 'Investigation created successfully.');`; app/Http/Controllers/SafeguardingInvestigationController.php:83 `return back()->with('success', 'Investigation updated successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST safeguarding/{concern}/investigations` — `safeguarding.investigations.store` — `App\Http\Controllers\SafeguardingInvestigationController@store` — `app/Http/Controllers/SafeguardingInvestigationController.php:16` — middleware `web, auth, permission:safeguarding.investigate`
- `PUT safeguarding/{concern}/investigations/{investigation}` — `safeguarding.investigations.update` — `App\Http\Controllers\SafeguardingInvestigationController@update` — `app/Http/Controllers/SafeguardingInvestigationController.php:50` — middleware `web, auth, permission:safeguarding.investigate`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/SafeguardingInvestigationController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
