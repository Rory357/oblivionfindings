# CAP-HR-PIP-MILESTONES-EVIDENCE: PIP milestones and evidence

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-PIP`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/performance/pips/milestones/{milestone}/evidence` (`hr.pips.milestones.evidence.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/performance/pips/milestones/{milestone}/evidence` (`hr.pips.milestones.evidence.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/performance/pips/{pip}/milestones` (`hr.pips.milestones.store`, action `storeMilestone`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/PipController.php:256-278`; `title`.
3. Invoke only the owning control for `DELETE hr/performance/pips/milestones/{milestone}` (`hr.pips.milestones.destroy`, action `destroyMilestone`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/PipController.php:283-291`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT hr/performance/pips/milestones/{milestone}` (`hr.pips.milestones.update`, action `updateMilestone`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/PipController.php:358-376`; no exact validation fields extracted.
5. Invoke only the owning control for `POST hr/performance/pips/milestones/{milestone}/evidence` (`hr.pips.milestones.evidence.store`, action `uploadMilestoneEvidence`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/PipController.php:318-335`; `file`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeMilestone` / `ROUTE-1631` at `app/Http/Controllers/Hr/PipController.php:256`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyMilestone` / `ROUTE-1633` at `app/Http/Controllers/Hr/PipController.php:283`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateMilestone` / `ROUTE-1634` at `app/Http/Controllers/Hr/PipController.php:358`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadMilestoneEvidence` / `ROUTE-1635` at `app/Http/Controllers/Hr/PipController.php:340`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadMilestoneEvidence` / `ROUTE-1636` at `app/Http/Controllers/Hr/PipController.php:318`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1631` / `storeMilestone`: fields `title`; success app/Http/Controllers/Hr/PipController.php:277 `return redirect()->back()->with('success', 'Milestone added.');`.
- `ROUTE-1633` / `destroyMilestone`: success app/Http/Controllers/Hr/PipController.php:290 `return redirect()->back()->with('success', 'Milestone removed.');`.
- `ROUTE-1634` / `updateMilestone`: success app/Http/Controllers/Hr/PipController.php:375 `return redirect()->back()->with('success', 'Milestone updated.');`.
- `ROUTE-1636` / `uploadMilestoneEvidence`: fields `file`; success app/Http/Controllers/Hr/PipController.php:334 `return redirect()->back()->with('success', 'Evidence uploaded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/PipController.php:269 `$pip->milestones()->create([`; app/Http/Controllers/Hr/PipController.php:288 `$milestone->delete();`; app/Http/Controllers/Hr/PipController.php:368 `$milestone->update([`; app/Http/Controllers/Hr/PipController.php:328 `Storage::disk('private')->delete($milestone->evidence_path);`; app/Http/Controllers/Hr/PipController.php:332 `$milestone->update(['evidence_path' => $path]);`; responses app/Http/Controllers/Hr/PipController.php:277 `return redirect()->back()->with('success', 'Milestone added.');`; app/Http/Controllers/Hr/PipController.php:290 `return redirect()->back()->with('success', 'Milestone removed.');`; app/Http/Controllers/Hr/PipController.php:375 `return redirect()->back()->with('success', 'Milestone updated.');`; app/Http/Controllers/Hr/PipController.php:346 `return $this->streamPrivateAttachment(`; app/Http/Controllers/Hr/PipController.php:334 `return redirect()->back()->with('success', 'Evidence uploaded.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/performance/pips/{pip}/milestones` — `hr.pips.milestones.store` — `App\Http\Controllers\Hr\PipController@storeMilestone` — `app/Http/Controllers/Hr/PipController.php:256` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `DELETE hr/performance/pips/milestones/{milestone}` — `hr.pips.milestones.destroy` — `App\Http\Controllers\Hr\PipController@destroyMilestone` — `app/Http/Controllers/Hr/PipController.php:283` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `PUT hr/performance/pips/milestones/{milestone}` — `hr.pips.milestones.update` — `App\Http\Controllers\Hr\PipController@updateMilestone` — `app/Http/Controllers/Hr/PipController.php:358` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/performance/pips/milestones/{milestone}/evidence` — `hr.pips.milestones.evidence.show` — `App\Http\Controllers\Hr\PipController@downloadMilestoneEvidence` — `app/Http/Controllers/Hr/PipController.php:340` — middleware `web, auth, permission:hr.performance.view`
- `POST hr/performance/pips/milestones/{milestone}/evidence` — `hr.pips.milestones.evidence.store` — `App\Http\Controllers\Hr\PipController@uploadMilestoneEvidence` — `app/Http/Controllers/Hr/PipController.php:318` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/PipController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
