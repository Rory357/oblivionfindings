# HR-APPROVAL: Approval

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.approvals.view`, `permission:hr.approvals.manage`
- Owning module: Human resources
- Legacy family: `HR-APPROVAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/approvals/chains` (`hr.approvals.chains`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.approvals.view`, `permission:hr.approvals.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.approvals.view`, `permission:hr.approvals.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/approvals/chains` (`hr.approvals.chains`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/approvals/pending` (`hr.approvals.pending`, action `pending`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/ApprovalController.php:115-149`.
3. Invoke only the owning control for `POST hr/approvals/{instance}/action` (`hr.approvals.action`, action `action`). Source category: **mutation outcome source gap (action)**; controller `app/Http/Controllers/Hr/ApprovalController.php:155-184`; `action`.
4. Invoke only the owning control for `POST hr/approvals/chains` (`hr.approvals.chains.store`, action `storeChain`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/ApprovalController.php:72-109`; `name`.

## Source-applicable states and transitions

- **mutation outcome source gap (action)** is applicable only to `action` / `ROUTE-1275` at `app/Http/Controllers/Hr/ApprovalController.php:155`; it is not runtime-observed.
- **information presented** is applicable only to `chains` / `ROUTE-1276` at `app/Http/Controllers/Hr/ApprovalController.php:26`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeChain` / `ROUTE-1277` at `app/Http/Controllers/Hr/ApprovalController.php:72`; it is not runtime-observed.
- **information presented** is applicable only to `pending` / `ROUTE-1278` at `app/Http/Controllers/Hr/ApprovalController.php:115`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/approvals/chains.tsx`, `resources/js/pages/hr/approvals/pending.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1275` / `action`: fields `action`; success app/Http/Controllers/Hr/ApprovalController.php:183 `return redirect()->back()->with('success', "Approval instance {$label}.");`.
- `ROUTE-1277` / `storeChain`: fields `name`; success app/Http/Controllers/Hr/ApprovalController.php:108 `return redirect()->route('hr.approvals.chains')->with('success', 'Approval chain created.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/ApprovalController.php:89 `$chain = HrApprovalChain::create([`; app/Http/Controllers/Hr/ApprovalController.php:98 `$chain->steps()->create([`; responses app/Http/Controllers/Hr/ApprovalController.php:174 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/ApprovalController.php:183 `return redirect()->back()->with('success', "Approval instance {$label}.");`; app/Http/Controllers/Hr/ApprovalController.php:60 `return Inertia::render('hr/approvals/chains', [`; app/Http/Controllers/Hr/ApprovalController.php:108 `return redirect()->route('hr.approvals.chains')->with('success', 'Approval chain created.');`; app/Http/Controllers/Hr/ApprovalController.php:143 `return Inertia::render('hr/approvals/pending', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/approvals/{instance}/action` — `hr.approvals.action` — `App\Http\Controllers\Hr\ApprovalController@action` — `app/Http/Controllers/Hr/ApprovalController.php:155` — middleware `web, auth, permission:hr.approvals.view, permission:hr.approvals.manage`
- `GET|HEAD hr/approvals/chains` — `hr.approvals.chains` — `App\Http\Controllers\Hr\ApprovalController@chains` — `app/Http/Controllers/Hr/ApprovalController.php:26` — middleware `web, auth, permission:hr.approvals.view`
- `POST hr/approvals/chains` — `hr.approvals.chains.store` — `App\Http\Controllers\Hr\ApprovalController@storeChain` — `app/Http/Controllers/Hr/ApprovalController.php:72` — middleware `web, auth, permission:hr.approvals.view, permission:hr.approvals.manage`
- `GET|HEAD hr/approvals/pending` — `hr.approvals.pending` — `App\Http\Controllers\Hr\ApprovalController@pending` — `app/Http/Controllers/Hr/ApprovalController.php:115` — middleware `web, auth, permission:hr.approvals.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/ApprovalController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/approvals/chains.tsx`, `resources/js/pages/hr/approvals/pending.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
