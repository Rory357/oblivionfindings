# CAP-GOV-SPEND-APPROVAL-DECISION: Spend approval decision

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.spend.view`, `permission:governance.spend.approve`
- Owning module: Governance
- Legacy family: `GOV-SPEND-APPROVAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/spend-approvals` (`governance.spend-approvals.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.spend.view`, `permission:governance.spend.approve`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.spend.view`, `permission:governance.spend.approve`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/spend-approvals` (`governance.spend-approvals.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST governance/spend-approvals/{approval}/approve` (`governance.spend-approvals.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:166-191`; `decision_notes`.
3. Invoke only the owning control for `POST governance/spend-approvals/{approval}/reject` (`governance.spend-approvals.reject`, action `reject`). Source category: **rejected/returned**; controller `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:193-211`; `decision_notes`.

## Source-applicable states and transitions

- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-1023` at `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:166`; it is not runtime-observed.
- **rejected/returned** is applicable only to `reject` / `ROUTE-1028` at `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:193`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1023` / `approve`: fields `decision_notes`; success app/Domain/Governance/Http/Controllers/SpendApprovalController.php:190 `return back()->with('success', 'Spend approval approved.');`.
- `ROUTE-1028` / `reject`: fields `decision_notes`; success app/Domain/Governance/Http/Controllers/SpendApprovalController.php:210 `return back()->with('success', 'Spend approval rejected.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/SpendApprovalController.php:176 `$approval->update([`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:201 `$approval->update([`; responses app/Domain/Governance/Http/Controllers/SpendApprovalController.php:190 `return back()->with('success', 'Spend approval approved.');`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:210 `return back()->with('success', 'Spend approval rejected.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST governance/spend-approvals/{approval}/approve` — `governance.spend-approvals.approve` — `App\Domain\Governance\Http\Controllers\SpendApprovalController@approve` — `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:166` — middleware `web, auth, permission:governance.spend.view, permission:governance.spend.approve`
- `POST governance/spend-approvals/{approval}/reject` — `governance.spend-approvals.reject` — `App\Domain\Governance\Http\Controllers\SpendApprovalController@reject` — `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:193` — middleware `web, auth, permission:governance.spend.view, permission:governance.spend.approve`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/SpendApprovalController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
