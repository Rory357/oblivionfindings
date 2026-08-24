# GOV-BOARD-INTEREST: Board Interest

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.interests.view`, `permission:governance.interests.manage`
- Owning module: Governance
- Legacy family: `GOV-BOARD-INTEREST`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/interests` (`governance.interests.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.interests.view`, `permission:governance.interests.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.interests.view`, `permission:governance.interests.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/interests` (`governance.interests.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/interests/mine` (`governance.interests.mine`, action `myInterests`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/BoardInterestController.php:90-110`.
3. Invoke only the owning control for `POST governance/interests` (`governance.interests.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/BoardInterestController.php:32-69`; `board_member_id`, `interest_type`, `description`, `organization_name`, `nature_of_interest`, `date_from`, `date_to`, `is_active`.
4. Invoke only the owning control for `PUT governance/interests/{interest}` (`governance.interests.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/BoardInterestController.php:71-88`; `description`, `is_active`, `date_to`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0928` at `app/Domain/Governance/Http/Controllers/BoardInterestController.php:13`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0929` at `app/Domain/Governance/Http/Controllers/BoardInterestController.php:32`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0930` at `app/Domain/Governance/Http/Controllers/BoardInterestController.php:71`; it is not runtime-observed.
- **information presented** is applicable only to `myInterests` / `ROUTE-0931` at `app/Domain/Governance/Http/Controllers/BoardInterestController.php:90`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Interests/Index.tsx`, `resources/js/pages/Governance/Interests/MyInterests.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0929` / `store`: fields `board_member_id`, `interest_type`, `description`, `organization_name`, `nature_of_interest`, `date_from`, `date_to`, `is_active`; success app/Domain/Governance/Http/Controllers/BoardInterestController.php:68 `return redirect()->back()->with('success', 'Interest declared.');`.
- `ROUTE-0930` / `update`: fields `description`, `is_active`, `date_to`; success app/Domain/Governance/Http/Controllers/BoardInterestController.php:87 `return redirect()->back()->with('success', 'Interest updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/BoardInterestController.php:55 `BoardMemberInterest::create([`; app/Domain/Governance/Http/Controllers/BoardInterestController.php:81 `$interest->update([`; responses app/Domain/Governance/Http/Controllers/BoardInterestController.php:26 `return Inertia::render('Governance/Interests/Index', [`; app/Domain/Governance/Http/Controllers/BoardInterestController.php:68 `return redirect()->back()->with('success', 'Interest declared.');`; app/Domain/Governance/Http/Controllers/BoardInterestController.php:87 `return redirect()->back()->with('success', 'Interest updated.');`; app/Domain/Governance/Http/Controllers/BoardInterestController.php:105 `return Inertia::render('Governance/Interests/MyInterests', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/interests` — `governance.interests.index` — `App\Domain\Governance\Http\Controllers\BoardInterestController@index` — `app/Domain/Governance/Http/Controllers/BoardInterestController.php:13` — middleware `web, auth, permission:governance.interests.view`
- `POST governance/interests` — `governance.interests.store` — `App\Domain\Governance\Http\Controllers\BoardInterestController@store` — `app/Domain/Governance/Http/Controllers/BoardInterestController.php:32` — middleware `web, auth, permission:governance.interests.manage`
- `PUT governance/interests/{interest}` — `governance.interests.update` — `App\Domain\Governance\Http\Controllers\BoardInterestController@update` — `app/Domain/Governance/Http/Controllers/BoardInterestController.php:71` — middleware `web, auth, permission:governance.interests.manage`
- `GET|HEAD governance/interests/mine` — `governance.interests.mine` — `App\Domain\Governance\Http\Controllers\BoardInterestController@myInterests` — `app/Domain/Governance/Http/Controllers/BoardInterestController.php:90` — middleware `web, auth, permission:governance.interests.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/BoardInterestController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Interests/Index.tsx`, `resources/js/pages/Governance/Interests/MyInterests.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
