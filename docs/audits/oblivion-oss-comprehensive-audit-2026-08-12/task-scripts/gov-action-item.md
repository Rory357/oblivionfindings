# GOV-ACTION-ITEM: Action Item

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.actions.view`
- Owning module: Governance
- Legacy family: `GOV-ACTION-ITEM`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/actions` (`governance.actions.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.actions.view`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.actions.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/actions` (`governance.actions.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/actions/{action}` (`governance.actions.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/ActionItemController.php:46-53`.
3. Invoke only the owning control for `POST governance/actions` (`governance.actions.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/ActionItemController.php:73-84`; FormRequest `app/Domain/Governance/Http/Requests/StoreActionItemRequest.php:15`; `source_type`, `source_id`, `description`, `assigned_to`, `due_date`, `priority`, `evidence_required`.
4. Invoke only the owning control for `POST governance/actions/{action}/block` (`governance.actions.block`, action `block`). Source category: **mutation outcome source gap (block)**; controller `app/Domain/Governance/Http/Controllers/ActionItemController.php:100-111`; `blocked_reason`.
5. Invoke only the owning control for `POST governance/actions/{action}/complete` (`governance.actions.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Domain/Governance/Http/Controllers/ActionItemController.php:55-71`; `completion_notes`, `evidence_files`.
6. Invoke only the owning control for `POST governance/actions/{action}/escalate` (`governance.actions.escalate`, action `escalate`). Source category: **escalated/flagged**; controller `app/Domain/Governance/Http/Controllers/ActionItemController.php:122-133`; `escalation_reason`.
7. Invoke only the owning control for `POST governance/actions/{action}/progress` (`governance.actions.progress`, action `updateProgress`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/ActionItemController.php:86-98`; `progress_pct`, `progress_notes`.
8. Invoke only the owning control for `POST governance/actions/{action}/unblock` (`governance.actions.unblock`, action `unblock`). Source category: **mutation outcome source gap (unblock)**; controller `app/Domain/Governance/Http/Controllers/ActionItemController.php:113-120`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0854` at `app/Domain/Governance/Http/Controllers/ActionItemController.php:13`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0855` at `app/Domain/Governance/Http/Controllers/ActionItemController.php:73`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0856` at `app/Domain/Governance/Http/Controllers/ActionItemController.php:46`; it is not runtime-observed.
- **mutation outcome source gap (block)** is applicable only to `block` / `ROUTE-0857` at `app/Domain/Governance/Http/Controllers/ActionItemController.php:100`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-0858` at `app/Domain/Governance/Http/Controllers/ActionItemController.php:55`; it is not runtime-observed.
- **escalated/flagged** is applicable only to `escalate` / `ROUTE-0859` at `app/Domain/Governance/Http/Controllers/ActionItemController.php:122`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateProgress` / `ROUTE-0860` at `app/Domain/Governance/Http/Controllers/ActionItemController.php:86`; it is not runtime-observed.
- **mutation outcome source gap (unblock)** is applicable only to `unblock` / `ROUTE-0861` at `app/Domain/Governance/Http/Controllers/ActionItemController.php:113`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Actions/Index.tsx`, `resources/js/pages/Governance/Actions/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0855` / `store`: FormRequest `app/Domain/Governance/Http/Requests/StoreActionItemRequest.php:15`; fields `source_type`, `source_id`, `description`, `assigned_to`, `due_date`, `priority`, `evidence_required`; success app/Domain/Governance/Http/Controllers/ActionItemController.php:83 `return redirect()->back()->with('success', 'Action item created.');`.
- `ROUTE-0857` / `block`: fields `blocked_reason`; success app/Domain/Governance/Http/Controllers/ActionItemController.php:110 `return redirect()->back()->with('success', 'Action item marked as blocked.');`.
- `ROUTE-0858` / `complete`: fields `completion_notes`, `evidence_files`; success app/Domain/Governance/Http/Controllers/ActionItemController.php:70 `return redirect()->back()->with('success', 'Action item completed.');`.
- `ROUTE-0859` / `escalate`: fields `escalation_reason`; success app/Domain/Governance/Http/Controllers/ActionItemController.php:132 `return redirect()->back()->with('success', 'Action item escalated.');`.
- `ROUTE-0860` / `updateProgress`: fields `progress_pct`, `progress_notes`; success app/Domain/Governance/Http/Controllers/ActionItemController.php:97 `return redirect()->back()->with('success', 'Progress updated.');`.
- `ROUTE-0861` / `unblock`: success app/Domain/Governance/Http/Controllers/ActionItemController.php:119 `return redirect()->back()->with('success', 'Action item unblocked.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/ActionItemController.php:77 `ActionItem::create([`; app/Domain/Governance/Http/Controllers/ActionItemController.php:67 `$action->update(['evidence_attachments' => $validated['evidence_files']]);`; responses app/Domain/Governance/Http/Controllers/ActionItemController.php:40 `return Inertia::render('Governance/Actions/Index', [`; app/Domain/Governance/Http/Controllers/ActionItemController.php:83 `return redirect()->back()->with('success', 'Action item created.');`; app/Domain/Governance/Http/Controllers/ActionItemController.php:50 `return Inertia::render('Governance/Actions/Show', [`; app/Domain/Governance/Http/Controllers/ActionItemController.php:110 `return redirect()->back()->with('success', 'Action item marked as blocked.');`; app/Domain/Governance/Http/Controllers/ActionItemController.php:70 `return redirect()->back()->with('success', 'Action item completed.');`; app/Domain/Governance/Http/Controllers/ActionItemController.php:132 `return redirect()->back()->with('success', 'Action item escalated.');`; app/Domain/Governance/Http/Controllers/ActionItemController.php:97 `return redirect()->back()->with('success', 'Progress updated.');`; app/Domain/Governance/Http/Controllers/ActionItemController.php:119 `return redirect()->back()->with('success', 'Action item unblocked.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/actions` — `governance.actions.index` — `App\Domain\Governance\Http\Controllers\ActionItemController@index` — `app/Domain/Governance/Http/Controllers/ActionItemController.php:13` — middleware `web, auth, permission:governance.actions.view`
- `POST governance/actions` — `governance.actions.store` — `App\Domain\Governance\Http\Controllers\ActionItemController@store` — `app/Domain/Governance/Http/Controllers/ActionItemController.php:73` — middleware `web, auth, permission:governance.actions.view`
- `GET|HEAD governance/actions/{action}` — `governance.actions.show` — `App\Domain\Governance\Http\Controllers\ActionItemController@show` — `app/Domain/Governance/Http/Controllers/ActionItemController.php:46` — middleware `web, auth, permission:governance.actions.view`
- `POST governance/actions/{action}/block` — `governance.actions.block` — `App\Domain\Governance\Http\Controllers\ActionItemController@block` — `app/Domain/Governance/Http/Controllers/ActionItemController.php:100` — middleware `web, auth, permission:governance.actions.view`
- `POST governance/actions/{action}/complete` — `governance.actions.complete` — `App\Domain\Governance\Http\Controllers\ActionItemController@complete` — `app/Domain/Governance/Http/Controllers/ActionItemController.php:55` — middleware `web, auth, permission:governance.actions.view`
- `POST governance/actions/{action}/escalate` — `governance.actions.escalate` — `App\Domain\Governance\Http\Controllers\ActionItemController@escalate` — `app/Domain/Governance/Http/Controllers/ActionItemController.php:122` — middleware `web, auth, permission:governance.actions.view`
- `POST governance/actions/{action}/progress` — `governance.actions.progress` — `App\Domain\Governance\Http\Controllers\ActionItemController@updateProgress` — `app/Domain/Governance/Http/Controllers/ActionItemController.php:86` — middleware `web, auth, permission:governance.actions.view`
- `POST governance/actions/{action}/unblock` — `governance.actions.unblock` — `App\Domain\Governance\Http\Controllers\ActionItemController@unblock` — `app/Domain/Governance/Http/Controllers/ActionItemController.php:113` — middleware `web, auth, permission:governance.actions.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/ActionItemController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Actions/Index.tsx`, `resources/js/pages/Governance/Actions/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
