# CAP-GOV-SPEND-APPROVAL-REQUEST-SUBMISSION: Spend request drafting evidence and submission

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.spend.view`, `permission:governance.spend.request`
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

- Actor satisfying exact route middleware `auth`, `permission:governance.spend.view`, `permission:governance.spend.request`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.spend.view`, `permission:governance.spend.request`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/spend-approvals` (`governance.spend-approvals.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/spend-approvals/{approval}` (`governance.spend-approvals.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:102-118`.
3. Use `GET|HEAD governance/spend-approvals/{approval}/attachments/{attachment}/download` (`governance.spend-approvals.attachments.download`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:336-352`.
4. Use `GET|HEAD governance/spend-approvals/{approval}/edit` (`governance.spend-approvals.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:86-100`.
5. Use `GET|HEAD governance/spend-approvals/create` (`governance.spend-approvals.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:73-84`.
6. Invoke only the owning control for `POST governance/spend-approvals` (`governance.spend-approvals.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:120-138`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT governance/spend-approvals/{approval}` (`governance.spend-approvals.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:140-150`; no exact validation fields extracted.
8. Invoke only the owning control for `POST governance/spend-approvals/{approval}/attachments` (`governance.spend-approvals.attachments.store`, action `attachFiles`). Source category: **mutation outcome source gap (attachFiles)**; controller `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:249-299`; `files`.
9. Invoke only the owning control for `DELETE governance/spend-approvals/{approval}/attachments/{attachment}` (`governance.spend-approvals.attachments.destroy`, action `deleteAttachment`). Source category: **cancelled/removed/archived**; controller `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:301-334`; no exact validation fields extracted.
10. Invoke only the owning control for `POST governance/spend-approvals/{approval}/submit` (`governance.spend-approvals.submit`, action `submit`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:152-164`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1019` at `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:18`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1020` at `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:120`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1021` at `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:102`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1022` at `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:140`; it is not runtime-observed.
- **mutation outcome source gap (attachFiles)** is applicable only to `attachFiles` / `ROUTE-1024` at `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:249`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `deleteAttachment` / `ROUTE-1025` at `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:301`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-1026` at `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:336`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-1027` at `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:86`; it is not runtime-observed.
- **created/recorded** is applicable only to `submit` / `ROUTE-1029` at `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:152`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1030` at `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:73`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/SpendApprovals/Create.tsx`, `resources/js/pages/Governance/SpendApprovals/Edit.tsx`, `resources/js/pages/Governance/SpendApprovals/Index.tsx`, `resources/js/pages/Governance/SpendApprovals/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1020` / `store`: success app/Domain/Governance/Http/Controllers/SpendApprovalController.php:137 `->with('success', 'Spend approval drafted.');`.
- `ROUTE-1022` / `update`: success app/Domain/Governance/Http/Controllers/SpendApprovalController.php:149 `return back()->with('success', 'Spend approval updated.');`.
- `ROUTE-1024` / `attachFiles`: fields `files`; success app/Domain/Governance/Http/Controllers/SpendApprovalController.php:298 `: redirect()->back()->with('success', 'Document(s) attached.');`; failure app/Domain/Governance/Http/Controllers/SpendApprovalController.php:254 `abort(403, 'Only the requester can attach documents to a draft.');`.
- `ROUTE-1025` / `deleteAttachment`: success app/Domain/Governance/Http/Controllers/SpendApprovalController.php:333 `: redirect()->back()->with('success', 'Attachment removed.');`; failure app/Domain/Governance/Http/Controllers/SpendApprovalController.php:304 `abort(403, 'Only the requester can remove documents from a draft.');`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:311 `abort(404, 'Attachment not found.');`.
- `ROUTE-1026` / `downloadAttachment`: failure app/Domain/Governance/Http/Controllers/SpendApprovalController.php:344 `abort(404, 'Attachment not found.');`.
- `ROUTE-1029` / `submit`: success app/Domain/Governance/Http/Controllers/SpendApprovalController.php:163 `return back()->with('success', 'Spend approval submitted for sign-off.');`.

## Failure and recovery paths

- `attachFiles`: app/Domain/Governance/Http/Controllers/SpendApprovalController.php:254 `abort(403, 'Only the requester can attach documents to a draft.');`.
- `deleteAttachment`: app/Domain/Governance/Http/Controllers/SpendApprovalController.php:304 `abort(403, 'Only the requester can remove documents from a draft.');`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:311 `abort(404, 'Attachment not found.');`.
- `downloadAttachment`: app/Domain/Governance/Http/Controllers/SpendApprovalController.php:344 `abort(404, 'Attachment not found.');`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/SpendApprovalController.php:128 `$approval = SpendApproval::create($data);`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:147 `$approval->update($data);`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:287 `$approval->update(['attachments' => $existing]);`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:315 `Storage::disk('local')->delete($target['path']);`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:322 `$approval->update(['attachments' => $remaining]);`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:156 `$approval->update([`; responses app/Domain/Governance/Http/Controllers/SpendApprovalController.php:48 `return Inertia::render('Governance/SpendApprovals/Index', [`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:136 `return redirect()->route('governance.spend-approvals.show', $approval)`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:112 `return Inertia::render('Governance/SpendApprovals/Show', [`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:149 `return back()->with('success', 'Spend approval updated.');`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:296 `return $request->wantsJson()`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:297 `? response()->json(['attachments' => $this->presentAttachments($approval->fresh())])`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:331 `return $request->wantsJson()`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:332 `? response()->json(['attachments' => $this->presentAttachments($approval->fresh())])`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:347 `return Storage::disk('local')->download(`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:90 `return Inertia::render('Governance/SpendApprovals/Edit', [`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:163 `return back()->with('success', 'Spend approval submitted for sign-off.');`; app/Domain/Governance/Http/Controllers/SpendApprovalController.php:75 `return Inertia::render('Governance/SpendApprovals/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/spend-approvals` — `governance.spend-approvals.index` — `App\Domain\Governance\Http\Controllers\SpendApprovalController@index` — `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:18` — middleware `web, auth, permission:governance.spend.view`
- `POST governance/spend-approvals` — `governance.spend-approvals.store` — `App\Domain\Governance\Http\Controllers\SpendApprovalController@store` — `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:120` — middleware `web, auth, permission:governance.spend.view, permission:governance.spend.request`
- `GET|HEAD governance/spend-approvals/{approval}` — `governance.spend-approvals.show` — `App\Domain\Governance\Http\Controllers\SpendApprovalController@show` — `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:102` — middleware `web, auth, permission:governance.spend.view`
- `PUT governance/spend-approvals/{approval}` — `governance.spend-approvals.update` — `App\Domain\Governance\Http\Controllers\SpendApprovalController@update` — `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:140` — middleware `web, auth, permission:governance.spend.view, permission:governance.spend.request`
- `POST governance/spend-approvals/{approval}/attachments` — `governance.spend-approvals.attachments.store` — `App\Domain\Governance\Http\Controllers\SpendApprovalController@attachFiles` — `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:249` — middleware `web, auth, permission:governance.spend.view, permission:governance.spend.request`
- `DELETE governance/spend-approvals/{approval}/attachments/{attachment}` — `governance.spend-approvals.attachments.destroy` — `App\Domain\Governance\Http\Controllers\SpendApprovalController@deleteAttachment` — `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:301` — middleware `web, auth, permission:governance.spend.view, permission:governance.spend.request`
- `GET|HEAD governance/spend-approvals/{approval}/attachments/{attachment}/download` — `governance.spend-approvals.attachments.download` — `App\Domain\Governance\Http\Controllers\SpendApprovalController@downloadAttachment` — `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:336` — middleware `web, auth, permission:governance.spend.view`
- `GET|HEAD governance/spend-approvals/{approval}/edit` — `governance.spend-approvals.edit` — `App\Domain\Governance\Http\Controllers\SpendApprovalController@edit` — `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:86` — middleware `web, auth, permission:governance.spend.view, permission:governance.spend.request`
- `POST governance/spend-approvals/{approval}/submit` — `governance.spend-approvals.submit` — `App\Domain\Governance\Http\Controllers\SpendApprovalController@submit` — `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:152` — middleware `web, auth, permission:governance.spend.view, permission:governance.spend.request`
- `GET|HEAD governance/spend-approvals/create` — `governance.spend-approvals.create` — `App\Domain\Governance\Http\Controllers\SpendApprovalController@create` — `app/Domain/Governance/Http/Controllers/SpendApprovalController.php:73` — middleware `web, auth, permission:governance.spend.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/SpendApprovalController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/SpendApprovals/Create.tsx`, `resources/js/pages/Governance/SpendApprovals/Edit.tsx`, `resources/js/pages/Governance/SpendApprovals/Index.tsx`, `resources/js/pages/Governance/SpendApprovals/Show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
