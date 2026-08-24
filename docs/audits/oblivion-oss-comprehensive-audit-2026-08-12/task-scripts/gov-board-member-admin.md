# GOV-BOARD-MEMBER-ADMIN: Board Member Admin

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.meetings.manage`
- Owning module: Governance
- Legacy family: `GOV-BOARD-MEMBER-ADMIN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/admin/board-members` (`governance.admin.board-members.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.meetings.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.meetings.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/admin/board-members` (`governance.admin.board-members.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST governance/admin/board-members` (`governance.admin.board-members.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:35-70`; `user_id`.
3. Invoke only the owning control for `DELETE governance/admin/board-members/{boardMember}` (`governance.admin.board-members.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:87-95`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT governance/admin/board-members/{boardMember}` (`governance.admin.board-members.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:72-85`; `board_role`, `term_end`, `is_active`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0862` at `app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:14`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0863` at `app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:35`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0864` at `app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:87`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0865` at `app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:72`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Admin/BoardMembers.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0863` / `store`: fields `user_id`; success app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:69 `return redirect()->back()->with('success', 'Board member appointed.');`.
- `ROUTE-0864` / `destroy`: success app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:94 `return redirect()->back()->with('success', 'Board member removed.');`.
- `ROUTE-0865` / `update`: fields `board_role`, `term_end`, `is_active`; success app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:84 `return redirect()->back()->with('success', 'Board member updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:55 `$existing->restore();`; app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:56 `$existing->update([`; app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:62 `BoardMember::create([`; app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:91 `$boardMember->update(['is_active' => false]);`; app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:92 `$boardMember->delete();`; app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:82 `$boardMember->update($validated);`; responses app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:29 `return Inertia::render('Governance/Admin/BoardMembers', [`; app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:69 `return redirect()->back()->with('success', 'Board member appointed.');`; app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:94 `return redirect()->back()->with('success', 'Board member removed.');`; app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:84 `return redirect()->back()->with('success', 'Board member updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/admin/board-members` — `governance.admin.board-members.index` — `App\Domain\Governance\Http\Controllers\BoardMemberAdminController@index` — `app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:14` — middleware `web, auth, permission:governance.meetings.manage`
- `POST governance/admin/board-members` — `governance.admin.board-members.store` — `App\Domain\Governance\Http\Controllers\BoardMemberAdminController@store` — `app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:35` — middleware `web, auth, permission:governance.meetings.manage`
- `DELETE governance/admin/board-members/{boardMember}` — `governance.admin.board-members.destroy` — `App\Domain\Governance\Http\Controllers\BoardMemberAdminController@destroy` — `app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:87` — middleware `web, auth, permission:governance.meetings.manage`
- `PUT governance/admin/board-members/{boardMember}` — `governance.admin.board-members.update` — `App\Domain\Governance\Http\Controllers\BoardMemberAdminController@update` — `app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php:72` — middleware `web, auth, permission:governance.meetings.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Admin/BoardMembers.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
