# CAP-SITE-SITE-CHECKLIST-RUN-EXECUTION: Checklist run response completion skip recovery and reschedule

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:checklists.schedule`, `permission:checklists.run`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-CHECKLIST`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/checklists` (`sites.checklists.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:checklists.schedule`, `permission:checklists.run`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:checklists.schedule`, `permission:checklists.run`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/checklists` (`sites.checklists.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PATCH checklists/runs/{run}/assign` (`sites.checklists.reassignRun`, action `reassignRun`). Source category: **mutation outcome source gap (reassignRun)**; controller `app/Http/Controllers/Sites/SiteChecklistController.php:131-142`; `assigned_to_user_id`.
3. Invoke only the owning control for `POST checklists/runs/{run}/complete` (`sites.checklists.completeRun`, action `completeRun`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Sites/SiteChecklistController.php:71-109`; `responses`, `overall_notes`, `signature_name`.
4. Invoke only the owning control for `POST checklists/runs/{run}/responses` (`sites.checklists.response`, action `saveResponse`). Source category: **mutation outcome source gap (saveResponse)**; controller `app/Http/Controllers/Sites/SiteChecklistController.php:39-69`; `responses`, `overall_notes`, `signature_name`.
5. Invoke only the owning control for `POST checklists/runs/{run}/restore` (`sites.checklists.restoreRun`, action `restoreRun`). Source category: **mutation outcome source gap (restoreRun)**; controller `app/Http/Controllers/Sites/SiteChecklistController.php:163-172`; no exact validation fields extracted.
6. Invoke only the owning control for `PATCH checklists/runs/{run}/schedule` (`sites.checklists.rescheduleRun`, action `rescheduleRun`). Source category: **mutation outcome source gap (rescheduleRun)**; controller `app/Http/Controllers/Sites/SiteChecklistController.php:114-125`; `scheduled_date`.
7. Invoke only the owning control for `POST checklists/runs/{run}/skip` (`sites.checklists.skipRun`, action `skipRun`). Source category: **rejected/returned**; controller `app/Http/Controllers/Sites/SiteChecklistController.php:147-158`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (reassignRun)** is applicable only to `reassignRun` / `ROUTE-0116` at `app/Http/Controllers/Sites/SiteChecklistController.php:131`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `completeRun` / `ROUTE-0117` at `app/Http/Controllers/Sites/SiteChecklistController.php:71`; it is not runtime-observed.
- **mutation outcome source gap (saveResponse)** is applicable only to `saveResponse` / `ROUTE-0118` at `app/Http/Controllers/Sites/SiteChecklistController.php:39`; it is not runtime-observed.
- **mutation outcome source gap (restoreRun)** is applicable only to `restoreRun` / `ROUTE-0119` at `app/Http/Controllers/Sites/SiteChecklistController.php:163`; it is not runtime-observed.
- **mutation outcome source gap (rescheduleRun)** is applicable only to `rescheduleRun` / `ROUTE-0120` at `app/Http/Controllers/Sites/SiteChecklistController.php:114`; it is not runtime-observed.
- **rejected/returned** is applicable only to `skipRun` / `ROUTE-0121` at `app/Http/Controllers/Sites/SiteChecklistController.php:147`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0116` / `reassignRun`: fields `assigned_to_user_id`; success app/Http/Controllers/Sites/SiteChecklistController.php:141 `return redirect()->back()->with('success', 'Checklist run reassigned.');`.
- `ROUTE-0117` / `completeRun`: fields `responses`, `overall_notes`, `signature_name`; success app/Http/Controllers/Sites/SiteChecklistController.php:79 `return redirect()->back()->with('success', 'Checklist already completed.');`; app/Http/Controllers/Sites/SiteChecklistController.php:108 `return redirect()->back()->with('success', 'Checklist completed.');`.
- `ROUTE-0118` / `saveResponse`: fields `responses`, `overall_notes`, `signature_name`.
- `ROUTE-0119` / `restoreRun`: success app/Http/Controllers/Sites/SiteChecklistController.php:171 `return redirect()->back()->with('success', 'Checklist run restored.');`.
- `ROUTE-0120` / `rescheduleRun`: fields `scheduled_date`; success app/Http/Controllers/Sites/SiteChecklistController.php:124 `return redirect()->back()->with('success', 'Checklist run rescheduled.');`.
- `ROUTE-0121` / `skipRun`: success app/Http/Controllers/Sites/SiteChecklistController.php:157 `return redirect()->back()->with('success', 'Checklist run skipped.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteChecklistController.php:139 `$run->update(['assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null]);`; app/Http/Controllers/Sites/SiteChecklistController.php:100 `$run->update([`; app/Http/Controllers/Sites/SiteChecklistController.php:62 `$run->update(['status' => 'in_progress', 'started_at' => $run->started_at ?? now()]);`; app/Http/Controllers/Sites/SiteChecklistController.php:168 `$run->update(['status' => 'scheduled']);`; app/Http/Controllers/Sites/SiteChecklistController.php:122 `$run->update(['scheduled_date' => $validated['scheduled_date']]);`; app/Http/Controllers/Sites/SiteChecklistController.php:155 `$run->update(['status' => 'skipped']);`; responses app/Http/Controllers/Sites/SiteChecklistController.php:141 `return redirect()->back()->with('success', 'Checklist run reassigned.');`; app/Http/Controllers/Sites/SiteChecklistController.php:79 `return redirect()->back()->with('success', 'Checklist already completed.');`; app/Http/Controllers/Sites/SiteChecklistController.php:108 `return redirect()->back()->with('success', 'Checklist completed.');`; app/Http/Controllers/Sites/SiteChecklistController.php:68 `return redirect()->back();`; app/Http/Controllers/Sites/SiteChecklistController.php:171 `return redirect()->back()->with('success', 'Checklist run restored.');`; app/Http/Controllers/Sites/SiteChecklistController.php:124 `return redirect()->back()->with('success', 'Checklist run rescheduled.');`; app/Http/Controllers/Sites/SiteChecklistController.php:152 `return redirect()->back()->with('error', 'A completed run cannot be skipped.');`; app/Http/Controllers/Sites/SiteChecklistController.php:157 `return redirect()->back()->with('success', 'Checklist run skipped.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `PATCH checklists/runs/{run}/assign` — `sites.checklists.reassignRun` — `App\Http\Controllers\Sites\SiteChecklistController@reassignRun` — `app/Http/Controllers/Sites/SiteChecklistController.php:131` — middleware `web, auth, verified, permission:checklists.schedule`
- `POST checklists/runs/{run}/complete` — `sites.checklists.completeRun` — `App\Http\Controllers\Sites\SiteChecklistController@completeRun` — `app/Http/Controllers/Sites/SiteChecklistController.php:71` — middleware `web, auth, verified, permission:checklists.run`
- `POST checklists/runs/{run}/responses` — `sites.checklists.response` — `App\Http\Controllers\Sites\SiteChecklistController@saveResponse` — `app/Http/Controllers/Sites/SiteChecklistController.php:39` — middleware `web, auth, verified, permission:checklists.run`
- `POST checklists/runs/{run}/restore` — `sites.checklists.restoreRun` — `App\Http\Controllers\Sites\SiteChecklistController@restoreRun` — `app/Http/Controllers/Sites/SiteChecklistController.php:163` — middleware `web, auth, verified, permission:checklists.schedule`
- `PATCH checklists/runs/{run}/schedule` — `sites.checklists.rescheduleRun` — `App\Http\Controllers\Sites\SiteChecklistController@rescheduleRun` — `app/Http/Controllers/Sites/SiteChecklistController.php:114` — middleware `web, auth, verified, permission:checklists.schedule`
- `POST checklists/runs/{run}/skip` — `sites.checklists.skipRun` — `App\Http\Controllers\Sites\SiteChecklistController@skipRun` — `app/Http/Controllers/Sites/SiteChecklistController.php:147` — middleware `web, auth, verified, permission:checklists.run`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteChecklistController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
