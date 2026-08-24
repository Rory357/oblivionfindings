# CAP-HR-WELLBEING-ACTION-PLANS: Wellbeing action plans and follow-up notes

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-WELLBEING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/wellbeing` (`hr.wellbeing.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/wellbeing` (`hr.wellbeing.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/wellbeing/action-plans` (`hr.wellbeing.action-plans.store-standalone`, action `storeStandaloneActionPlan`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/WellbeingController.php:841-868`; `owner_user_id`.
3. Invoke only the owning control for `PUT hr/wellbeing/action-plans/{plan}` (`hr.wellbeing.action-plans.update`, action `updateActionPlan`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/WellbeingController.php:643-687`; `title`.
4. Invoke only the owning control for `POST hr/wellbeing/action-plans/{plan}/cancel` (`hr.wellbeing.action-plans.cancel`, action `cancelActionPlan`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/WellbeingController.php:878-885`; `reason`.
5. Invoke only the owning control for `POST hr/wellbeing/action-plans/{plan}/notes` (`hr.wellbeing.action-plans.notes.store`, action `storeActionPlanNote`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/WellbeingController.php:887-899`; `body`.
6. Invoke only the owning control for `POST hr/wellbeing/action-plans/{plan}/reopen` (`hr.wellbeing.action-plans.reopen`, action `reopenActionPlan`). Source category: **mutation outcome source gap (reopenActionPlan)**; controller `app/Http/Controllers/Hr/WellbeingController.php:870-876`; no exact validation fields extracted.
7. Invoke only the owning control for `POST hr/wellbeing/surveys/{survey}/action-plans` (`hr.wellbeing.action-plans.store`, action `storeActionPlan`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/WellbeingController.php:603-641`; `owner_user_id`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeStandaloneActionPlan` / `ROUTE-1808` at `app/Http/Controllers/Hr/WellbeingController.php:841`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateActionPlan` / `ROUTE-1809` at `app/Http/Controllers/Hr/WellbeingController.php:643`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancelActionPlan` / `ROUTE-1810` at `app/Http/Controllers/Hr/WellbeingController.php:878`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeActionPlanNote` / `ROUTE-1811` at `app/Http/Controllers/Hr/WellbeingController.php:887`; it is not runtime-observed.
- **mutation outcome source gap (reopenActionPlan)** is applicable only to `reopenActionPlan` / `ROUTE-1812` at `app/Http/Controllers/Hr/WellbeingController.php:870`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeActionPlan` / `ROUTE-1825` at `app/Http/Controllers/Hr/WellbeingController.php:603`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1808` / `storeStandaloneActionPlan`: fields `owner_user_id`; success app/Http/Controllers/Hr/WellbeingController.php:867 `return redirect()->back()->with('success', 'Action plan created.');`.
- `ROUTE-1809` / `updateActionPlan`: fields `title`; success app/Http/Controllers/Hr/WellbeingController.php:686 `return redirect()->back()->with('success', 'Action plan updated.');`.
- `ROUTE-1810` / `cancelActionPlan`: fields `reason`; success app/Http/Controllers/Hr/WellbeingController.php:884 `return redirect()->back()->with('success', 'Action plan cancelled.');`.
- `ROUTE-1811` / `storeActionPlanNote`: fields `body`; success app/Http/Controllers/Hr/WellbeingController.php:898 `return redirect()->back()->with('success', 'Note added.');`.
- `ROUTE-1812` / `reopenActionPlan`: success app/Http/Controllers/Hr/WellbeingController.php:875 `return redirect()->back()->with('success', 'Action plan reopened.');`.
- `ROUTE-1825` / `storeActionPlan`: fields `owner_user_id`; success app/Http/Controllers/Hr/WellbeingController.php:640 `return redirect()->back()->with('success', 'Action plan created.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/WellbeingController.php:677 `$plan->update($payload);`; app/Http/Controllers/Hr/WellbeingController.php:622 `$plan = HrEngagementActionPlan::create([`; responses app/Http/Controllers/Hr/WellbeingController.php:867 `return redirect()->back()->with('success', 'Action plan created.');`; app/Http/Controllers/Hr/WellbeingController.php:686 `return redirect()->back()->with('success', 'Action plan updated.');`; app/Http/Controllers/Hr/WellbeingController.php:884 `return redirect()->back()->with('success', 'Action plan cancelled.');`; app/Http/Controllers/Hr/WellbeingController.php:898 `return redirect()->back()->with('success', 'Note added.');`; app/Http/Controllers/Hr/WellbeingController.php:875 `return redirect()->back()->with('success', 'Action plan reopened.');`; app/Http/Controllers/Hr/WellbeingController.php:640 `return redirect()->back()->with('success', 'Action plan created.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/wellbeing/action-plans` — `hr.wellbeing.action-plans.store-standalone` — `App\Http\Controllers\Hr\WellbeingController@storeStandaloneActionPlan` — `app/Http/Controllers/Hr/WellbeingController.php:841` — middleware `web, auth, permission:hr.performance.manage`
- `PUT hr/wellbeing/action-plans/{plan}` — `hr.wellbeing.action-plans.update` — `App\Http\Controllers\Hr\WellbeingController@updateActionPlan` — `app/Http/Controllers/Hr/WellbeingController.php:643` — middleware `web, auth`
- `POST hr/wellbeing/action-plans/{plan}/cancel` — `hr.wellbeing.action-plans.cancel` — `App\Http\Controllers\Hr\WellbeingController@cancelActionPlan` — `app/Http/Controllers/Hr/WellbeingController.php:878` — middleware `web, auth, permission:hr.performance.manage`
- `POST hr/wellbeing/action-plans/{plan}/notes` — `hr.wellbeing.action-plans.notes.store` — `App\Http\Controllers\Hr\WellbeingController@storeActionPlanNote` — `app/Http/Controllers/Hr/WellbeingController.php:887` — middleware `web, auth`
- `POST hr/wellbeing/action-plans/{plan}/reopen` — `hr.wellbeing.action-plans.reopen` — `App\Http\Controllers\Hr\WellbeingController@reopenActionPlan` — `app/Http/Controllers/Hr/WellbeingController.php:870` — middleware `web, auth, permission:hr.performance.manage`
- `POST hr/wellbeing/surveys/{survey}/action-plans` — `hr.wellbeing.action-plans.store` — `App\Http\Controllers\Hr\WellbeingController@storeActionPlan` — `app/Http/Controllers/Hr/WellbeingController.php:603` — middleware `web, auth, permission:hr.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/WellbeingController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
