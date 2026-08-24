# CAP-GOV-RESOLUTION-VOTING-CONFLICTS: Resolution voting window and conflict declarations

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.resolutions.view`, `permission:governance.resolutions.manage`, `permission:governance.resolutions.vote`
- Owning module: Governance
- Legacy family: `GOV-RESOLUTION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/resolutions` (`governance.resolutions.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.resolutions.view`, `permission:governance.resolutions.manage`, `permission:governance.resolutions.vote`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.resolutions.view`, `permission:governance.resolutions.manage`, `permission:governance.resolutions.vote`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/resolutions` (`governance.resolutions.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST governance/resolutions/{resolution}/close` (`governance.resolutions.close`, action `closeVoting`). Source category: **completed/closed/released**; controller `app/Domain/Governance/Http/Controllers/ResolutionController.php:207-223`; `notes`.
3. Invoke only the owning control for `POST governance/resolutions/{resolution}/conflict` (`governance.resolutions.conflict.declare`, action `declareConflict`). Source category: **mutation outcome source gap (declareConflict)**; controller `app/Domain/Governance/Http/Controllers/ResolutionController.php:157-185`; `type`, `description`, `withdraw_from_voting`, `withdraw_from_discussion`.
4. Invoke only the owning control for `POST governance/resolutions/{resolution}/open` (`governance.resolutions.open`, action `openVoting`). Source category: **mutation outcome source gap (openVoting)**; controller `app/Domain/Governance/Http/Controllers/ResolutionController.php:187-205`; `deadline`.
5. Invoke only the owning control for `POST governance/resolutions/{resolution}/vote` (`governance.resolutions.vote`, action `vote`). Source category: **mutation outcome source gap (vote)**; controller `app/Domain/Governance/Http/Controllers/ResolutionController.php:122-155`; `vote`, `conflict_note`.

## Source-applicable states and transitions

- **completed/closed/released** is applicable only to `closeVoting` / `ROUTE-0995` at `app/Domain/Governance/Http/Controllers/ResolutionController.php:207`; it is not runtime-observed.
- **mutation outcome source gap (declareConflict)** is applicable only to `declareConflict` / `ROUTE-0996` at `app/Domain/Governance/Http/Controllers/ResolutionController.php:157`; it is not runtime-observed.
- **mutation outcome source gap (openVoting)** is applicable only to `openVoting` / `ROUTE-0998` at `app/Domain/Governance/Http/Controllers/ResolutionController.php:187`; it is not runtime-observed.
- **mutation outcome source gap (vote)** is applicable only to `vote` / `ROUTE-0999` at `app/Domain/Governance/Http/Controllers/ResolutionController.php:122`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0995` / `closeVoting`: fields `notes`; success app/Domain/Governance/Http/Controllers/ResolutionController.php:222 `return redirect()->back()->with('success', 'Voting closed. Outcome: '.$resolution->outcome);`.
- `ROUTE-0996` / `declareConflict`: fields `type`, `description`, `withdraw_from_voting`, `withdraw_from_discussion`; success app/Domain/Governance/Http/Controllers/ResolutionController.php:184 `return redirect()->back()->with('success', 'Conflict declared.');`.
- `ROUTE-0998` / `openVoting`: fields `deadline`; success app/Domain/Governance/Http/Controllers/ResolutionController.php:204 `return redirect()->back()->with('success', 'Voting opened.');`.
- `ROUTE-0999` / `vote`: fields `vote`, `conflict_note`; success app/Domain/Governance/Http/Controllers/ResolutionController.php:154 `return redirect()->back()->with('success', 'Vote recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Governance/Http/Controllers/ResolutionController.php:222 `return redirect()->back()->with('success', 'Voting closed. Outcome: '.$resolution->outcome);`; app/Domain/Governance/Http/Controllers/ResolutionController.php:171 `return redirect()->back()->with('error', 'You must be an active board member to declare a conflict.');`; app/Domain/Governance/Http/Controllers/ResolutionController.php:184 `return redirect()->back()->with('success', 'Conflict declared.');`; app/Domain/Governance/Http/Controllers/ResolutionController.php:204 `return redirect()->back()->with('success', 'Voting opened.');`; app/Domain/Governance/Http/Controllers/ResolutionController.php:136 `return redirect()->back()->with('error', 'You must be an active board member to vote.');`; app/Domain/Governance/Http/Controllers/ResolutionController.php:154 `return redirect()->back()->with('success', 'Vote recorded.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST governance/resolutions/{resolution}/close` — `governance.resolutions.close` — `App\Domain\Governance\Http\Controllers\ResolutionController@closeVoting` — `app/Domain/Governance/Http/Controllers/ResolutionController.php:207` — middleware `web, auth, permission:governance.resolutions.view, permission:governance.resolutions.manage`
- `POST governance/resolutions/{resolution}/conflict` — `governance.resolutions.conflict.declare` — `App\Domain\Governance\Http\Controllers\ResolutionController@declareConflict` — `app/Domain/Governance/Http/Controllers/ResolutionController.php:157` — middleware `web, auth, permission:governance.resolutions.view, permission:governance.resolutions.vote`
- `POST governance/resolutions/{resolution}/open` — `governance.resolutions.open` — `App\Domain\Governance\Http\Controllers\ResolutionController@openVoting` — `app/Domain/Governance/Http/Controllers/ResolutionController.php:187` — middleware `web, auth, permission:governance.resolutions.view, permission:governance.resolutions.manage`
- `POST governance/resolutions/{resolution}/vote` — `governance.resolutions.vote` — `App\Domain\Governance\Http\Controllers\ResolutionController@vote` — `app/Domain/Governance/Http/Controllers/ResolutionController.php:122` — middleware `web, auth, permission:governance.resolutions.view, permission:governance.resolutions.vote`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/ResolutionController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
