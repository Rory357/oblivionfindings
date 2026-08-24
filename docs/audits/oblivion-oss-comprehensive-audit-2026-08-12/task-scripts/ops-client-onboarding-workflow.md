# OPS-CLIENT-ONBOARDING-WORKFLOW: Client Onboarding Workflow

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:onboarding.create|clients.create|clients.update`, `permission:onboarding.viewAny|onboarding.view|clients.viewAny`, `permission:onboarding.edit|clients.create|clients.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-CLIENT-ONBOARDING-WORKFLOW`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/onboarding` (`operations.onboarding.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:onboarding.create|clients.create|clients.update`, `permission:onboarding.viewAny|onboarding.view|clients.viewAny`, `permission:onboarding.edit|clients.create|clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:onboarding.create|clients.create|clients.update`, `permission:onboarding.viewAny|onboarding.view|clients.viewAny`, `permission:onboarding.edit|clients.create|clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/onboarding` (`operations.onboarding.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/onboarding/{workflow}` (`operations.onboarding.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:64-77`.
3. Use `GET|HEAD operations/onboarding/create` (`operations.onboarding.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:79-93`.
4. Invoke only the owning control for `POST operations/clients/{client}/onboarding-workflow` (`operations.clients.onboarding_workflow.store`, action `storeForClient`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:206-230`; no exact validation fields extracted.
5. Invoke only the owning control for `POST operations/onboarding` (`operations.onboarding.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:95-139`; `client_id`.
6. Invoke only the owning control for `POST operations/onboarding/{workflow}/complete` (`operations.onboarding.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:232-255`; no exact validation fields extracted.
7. Invoke only the owning control for `POST operations/onboarding/{workflow}/steps` (`operations.onboarding.steps.store`, action `storeStep`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:141-178`; `step_name`.
8. Invoke only the owning control for `PATCH operations/onboarding/{workflow}/steps/{step}` (`operations.onboarding.steps.update`, action `updateStep`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:180-204`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeForClient` / `ROUTE-2026` at `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:206`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-2120` at `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:20`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2121` at `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:95`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2122` at `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:64`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-2123` at `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:232`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeStep` / `ROUTE-2124` at `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:141`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateStep` / `ROUTE-2125` at `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:180`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2126` at `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:79`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/onboarding/Create.tsx`, `resources/js/pages/operations/onboarding/Index.tsx`, `resources/js/pages/operations/onboarding/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2026` / `storeForClient`: success app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:229 `return redirect()->back()->with('success', 'Onboarding workflow created successfully.');`.
- `ROUTE-2120` / `index`: fields `q`.
- `ROUTE-2121` / `store`: fields `client_id`; success app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:138 `return redirect()->back()->with('success', 'Onboarding workflow created.');`.
- `ROUTE-2123` / `complete`: success app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:254 `return redirect()->back()->with('success', 'Onboarding workflow completed.');`; failure app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:242 `return back()->withErrors([`.
- `ROUTE-2124` / `storeStep`: fields `step_name`; success app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:177 `return redirect()->back()->with('success', 'Onboarding step added.');`.
- `ROUTE-2125` / `updateStep`: success app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:203 `return redirect()->back()->with('success', 'Step updated.');`.

## Failure and recovery paths

- `complete`: app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:242 `return back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:112 `$workflow = ClientOnboardingWorkflow::create([`; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:131 `$workflow->steps()->create([`; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:247 `$workflow->update([`; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:252 `$workflow->client->update(['status' => 'active']);`; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:169 `$workflowModel->steps()->create([`; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:196 `$step->update([`; responses app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:224 `return redirect()->back()->with('error', 'Client already has an active onboarding workflow.');`; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:229 `return redirect()->back()->with('success', 'Onboarding workflow created successfully.');`; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:54 `return inertia('operations/onboarding/Index', [`; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:138 `return redirect()->back()->with('success', 'Onboarding workflow created.');`; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:74 `return inertia('operations/onboarding/Show', [`; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:242 `return back()->withErrors([`; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:254 `return redirect()->back()->with('success', 'Onboarding workflow completed.');`; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:177 `return redirect()->back()->with('success', 'Onboarding step added.');`; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:203 `return redirect()->back()->with('success', 'Step updated.');`; app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:90 `return inertia('operations/onboarding/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/clients/{client}/onboarding-workflow` — `operations.clients.onboarding_workflow.store` — `App\Http\Controllers\Operations\ClientOnboardingWorkflowController@storeForClient` — `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:206` — middleware `web, auth, permission:onboarding.create|clients.create|clients.update`
- `GET|HEAD operations/onboarding` — `operations.onboarding.index` — `App\Http\Controllers\Operations\ClientOnboardingWorkflowController@index` — `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:20` — middleware `web, auth, permission:onboarding.viewAny|onboarding.view|clients.viewAny`
- `POST operations/onboarding` — `operations.onboarding.store` — `App\Http\Controllers\Operations\ClientOnboardingWorkflowController@store` — `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:95` — middleware `web, auth, permission:onboarding.create|clients.create|clients.update`
- `GET|HEAD operations/onboarding/{workflow}` — `operations.onboarding.show` — `App\Http\Controllers\Operations\ClientOnboardingWorkflowController@show` — `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:64` — middleware `web, auth, permission:onboarding.viewAny|onboarding.view|clients.viewAny`
- `POST operations/onboarding/{workflow}/complete` — `operations.onboarding.complete` — `App\Http\Controllers\Operations\ClientOnboardingWorkflowController@complete` — `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:232` — middleware `web, auth, permission:onboarding.edit|clients.create|clients.update`
- `POST operations/onboarding/{workflow}/steps` — `operations.onboarding.steps.store` — `App\Http\Controllers\Operations\ClientOnboardingWorkflowController@storeStep` — `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:141` — middleware `web, auth, permission:onboarding.edit|clients.create|clients.update`
- `PATCH operations/onboarding/{workflow}/steps/{step}` — `operations.onboarding.steps.update` — `App\Http\Controllers\Operations\ClientOnboardingWorkflowController@updateStep` — `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:180` — middleware `web, auth, permission:onboarding.edit|clients.create|clients.update`
- `GET|HEAD operations/onboarding/create` — `operations.onboarding.create` — `App\Http\Controllers\Operations\ClientOnboardingWorkflowController@create` — `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php:79` — middleware `web, auth, permission:onboarding.create|clients.create|clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/onboarding/Create.tsx`, `resources/js/pages/operations/onboarding/Index.tsx`, `resources/js/pages/operations/onboarding/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
