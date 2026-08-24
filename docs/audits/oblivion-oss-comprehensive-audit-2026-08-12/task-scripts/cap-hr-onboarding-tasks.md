# CAP-HR-ONBOARDING-TASKS: Onboarding task execution and provisioning

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.onboarding.view`, `permission:hr.onboarding.manage`
- Owning module: Human resources
- Legacy family: `HR-ONBOARDING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/onboarding` (`hr.onboarding.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.onboarding.view`, `permission:hr.onboarding.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.onboarding.view`, `permission:hr.onboarding.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/onboarding` (`hr.onboarding.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/onboarding/{checklist}/tasks` (`hr.onboarding.tasks.store`, action `storeTask`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/OnboardingController.php:376-385`; FormRequest `app/Http/Requests/Hr/StoreOnboardingTaskRequest.php:17`; `title`, `description`, `category`, `due_date`, `is_required`, `sign_off_required`, `assigned_to_user_id`, `assigned_to_role`.
3. Invoke only the owning control for `POST hr/onboarding/{checklist}/tasks/reorder` (`hr.onboarding.tasks.reorder`, action `reorderTasks`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/OnboardingController.php:432-447`; `task_ids`.
4. Invoke only the owning control for `DELETE hr/onboarding/tasks/{task}` (`hr.onboarding.tasks.destroy`, action `destroyTask`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/OnboardingController.php:421-430`; no exact validation fields extracted.
5. Invoke only the owning control for `PATCH hr/onboarding/tasks/{task}` (`hr.onboarding.tasks.update`, action `updateTask`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/OnboardingController.php:366-374`; FormRequest `app/Http/Requests/Hr/UpdateOnboardingTaskRequest.php:17`; `title`, `description`, `category`, `due_date`, `is_required`, `sign_off_required`, `assigned_to_user_id`, `assigned_to_role`.
6. Invoke only the owning control for `POST hr/onboarding/tasks/{task}/complete` (`hr.onboarding.tasks.complete`, action `completeTask`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Hr/OnboardingController.php:320-353`; FormRequest `app/Http/Requests/Hr/CompleteOnboardingTaskRequest.php:18`; `notes`, `signed_off_by`, `evidence`.
7. Invoke only the owning control for `POST hr/onboarding/tasks/{task}/provision-asset` (`hr.onboarding.tasks.provision-asset`, action `provisionAsset`). Source category: **mutation outcome source gap (provisionAsset)**; controller `app/Http/Controllers/Hr/OnboardingController.php:387-419`; FormRequest `app/Http/Requests/Hr/ProvisionOnboardingAssetRequest.php:17`; `asset_id`, `category`, `purpose`, `signed_off_by`.
8. Invoke only the owning control for `POST hr/onboarding/tasks/{task}/uncomplete` (`hr.onboarding.tasks.uncomplete`, action `uncompleteTask`). Source category: **mutation outcome source gap (uncompleteTask)**; controller `app/Http/Controllers/Hr/OnboardingController.php:355-364`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeTask` / `ROUTE-1562` at `app/Http/Controllers/Hr/OnboardingController.php:376`; it is not runtime-observed.
- **updated/revised** is applicable only to `reorderTasks` / `ROUTE-1563` at `app/Http/Controllers/Hr/OnboardingController.php:432`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyTask` / `ROUTE-1574` at `app/Http/Controllers/Hr/OnboardingController.php:421`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateTask` / `ROUTE-1575` at `app/Http/Controllers/Hr/OnboardingController.php:366`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `completeTask` / `ROUTE-1576` at `app/Http/Controllers/Hr/OnboardingController.php:320`; it is not runtime-observed.
- **mutation outcome source gap (provisionAsset)** is applicable only to `provisionAsset` / `ROUTE-1577` at `app/Http/Controllers/Hr/OnboardingController.php:387`; it is not runtime-observed.
- **mutation outcome source gap (uncompleteTask)** is applicable only to `uncompleteTask` / `ROUTE-1578` at `app/Http/Controllers/Hr/OnboardingController.php:355`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1562` / `storeTask`: FormRequest `app/Http/Requests/Hr/StoreOnboardingTaskRequest.php:17`; fields `title`, `description`, `category`, `due_date`, `is_required`, `sign_off_required`, `assigned_to_user_id`, `assigned_to_role`; success app/Http/Controllers/Hr/OnboardingController.php:384 `return redirect()->back()->with('success', 'Task added.');`.
- `ROUTE-1563` / `reorderTasks`: fields `task_ids`; success app/Http/Controllers/Hr/OnboardingController.php:446 `return redirect()->back()->with('success', 'Tasks reordered.');`.
- `ROUTE-1574` / `destroyTask`: success app/Http/Controllers/Hr/OnboardingController.php:429 `return redirect()->back()->with('success', 'Task deleted.');`.
- `ROUTE-1575` / `updateTask`: FormRequest `app/Http/Requests/Hr/UpdateOnboardingTaskRequest.php:17`; fields `title`, `description`, `category`, `due_date`, `is_required`, `sign_off_required`, `assigned_to_user_id`, `assigned_to_role`; success app/Http/Controllers/Hr/OnboardingController.php:373 `return redirect()->back()->with('success', 'Task updated.');`.
- `ROUTE-1576` / `completeTask`: FormRequest `app/Http/Requests/Hr/CompleteOnboardingTaskRequest.php:18`; fields `notes`, `signed_off_by`, `evidence`; success app/Http/Controllers/Hr/OnboardingController.php:352 `return redirect()->back()->with('success', "Task '{$task->title}' completed.");`.
- `ROUTE-1577` / `provisionAsset`: FormRequest `app/Http/Requests/Hr/ProvisionOnboardingAssetRequest.php:17`; fields `asset_id`, `category`, `purpose`, `signed_off_by`; success app/Http/Controllers/Hr/OnboardingController.php:418 `return redirect()->back()->with('success', "Issued {$asset->name} and completed the task.");`.
- `ROUTE-1578` / `uncompleteTask`: success app/Http/Controllers/Hr/OnboardingController.php:363 `return redirect()->back()->with('success', "Task '{$task->title}' reopened.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/OnboardingController.php:384 `return redirect()->back()->with('success', 'Task added.');`; app/Http/Controllers/Hr/OnboardingController.php:446 `return redirect()->back()->with('success', 'Tasks reordered.');`; app/Http/Controllers/Hr/OnboardingController.php:429 `return redirect()->back()->with('success', 'Task deleted.');`; app/Http/Controllers/Hr/OnboardingController.php:373 `return redirect()->back()->with('success', 'Task updated.');`; app/Http/Controllers/Hr/OnboardingController.php:329 `return redirect()->back()->with('error', 'This task requires sign-off. Please specify the sign-off user.');`; app/Http/Controllers/Hr/OnboardingController.php:349 `return redirect()->back()->with('error', $exception->getMessage());`; app/Http/Controllers/Hr/OnboardingController.php:352 `return redirect()->back()->with('success', "Task '{$task->title}' completed.");`; app/Http/Controllers/Hr/OnboardingController.php:395 `return redirect()->back()->with('error', 'This task requires sign-off. Please specify the sign-off user.');`; app/Http/Controllers/Hr/OnboardingController.php:403 `return redirect()->back()->with('error', 'No available asset to auto-assign — add one or pick a specific asset.');`; app/Http/Controllers/Hr/OnboardingController.php:415 `return redirect()->back()->with('error', $exception->getMessage());`; app/Http/Controllers/Hr/OnboardingController.php:418 `return redirect()->back()->with('success', "Issued {$asset->name} and completed the task.");`; app/Http/Controllers/Hr/OnboardingController.php:363 `return redirect()->back()->with('success', "Task '{$task->title}' reopened.");`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/onboarding/{checklist}/tasks` — `hr.onboarding.tasks.store` — `App\Http\Controllers\Hr\OnboardingController@storeTask` — `app/Http/Controllers/Hr/OnboardingController.php:376` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `POST hr/onboarding/{checklist}/tasks/reorder` — `hr.onboarding.tasks.reorder` — `App\Http\Controllers\Hr\OnboardingController@reorderTasks` — `app/Http/Controllers/Hr/OnboardingController.php:432` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `DELETE hr/onboarding/tasks/{task}` — `hr.onboarding.tasks.destroy` — `App\Http\Controllers\Hr\OnboardingController@destroyTask` — `app/Http/Controllers/Hr/OnboardingController.php:421` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `PATCH hr/onboarding/tasks/{task}` — `hr.onboarding.tasks.update` — `App\Http\Controllers\Hr\OnboardingController@updateTask` — `app/Http/Controllers/Hr/OnboardingController.php:366` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `POST hr/onboarding/tasks/{task}/complete` — `hr.onboarding.tasks.complete` — `App\Http\Controllers\Hr\OnboardingController@completeTask` — `app/Http/Controllers/Hr/OnboardingController.php:320` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `POST hr/onboarding/tasks/{task}/provision-asset` — `hr.onboarding.tasks.provision-asset` — `App\Http\Controllers\Hr\OnboardingController@provisionAsset` — `app/Http/Controllers/Hr/OnboardingController.php:387` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `POST hr/onboarding/tasks/{task}/uncomplete` — `hr.onboarding.tasks.uncomplete` — `App\Http\Controllers\Hr\OnboardingController@uncompleteTask` — `app/Http/Controllers/Hr/OnboardingController.php:355` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/OnboardingController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
