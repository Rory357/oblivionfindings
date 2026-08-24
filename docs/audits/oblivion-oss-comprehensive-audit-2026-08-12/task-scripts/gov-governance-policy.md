# GOV-GOVERNANCE-POLICY: Governance Policy

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.policies.view`, `permission:governance.policies.manage`
- Owning module: Governance
- Legacy family: `GOV-GOVERNANCE-POLICY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/policies` (`governance.policies.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.policies.view`, `permission:governance.policies.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.policies.view`, `permission:governance.policies.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/policies` (`governance.policies.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/policies/{policy}` (`governance.policies.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:83-99`.
3. Use `GET|HEAD governance/policies/{policy}/edit` (`governance.policies.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:101-108`.
4. Use `GET|HEAD governance/policies/attestations` (`governance.policies.attestations`, action `attestations`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:208-256`.
5. Use `GET|HEAD governance/policies/create` (`governance.policies.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:42-47`.
6. Invoke only the owning control for `POST governance/policies` (`governance.policies.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:49-81`; `title`, `category`, `description`, `content`, `effective_date`, `review_date`, `requires_attestation`, `attestation_frequency`.
7. Invoke only the owning control for `PUT governance/policies/{policy}` (`governance.policies.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:110-154`; `title`, `category`, `description`, `content`, `review_date`, `requires_attestation`.
8. Invoke only the owning control for `POST governance/policies/{policy}/approve` (`governance.policies.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:156-169`; no exact validation fields extracted.
9. Invoke only the owning control for `POST governance/policies/{policy}/attest` (`governance.policies.attest`, action `attest`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:171-200`; `acknowledged`, `notes`.
10. Invoke only the owning control for `POST governance/policies/{policy}/version` (`governance.policies.version`, action `newVersion`). Source category: **mutation outcome source gap (newVersion)**; controller `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:258-276`; `content`, `change_summary`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0972` at `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:15`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0973` at `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:49`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0974` at `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:83`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0975` at `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:110`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-0976` at `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:156`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `attest` / `ROUTE-0977` at `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:171`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-0978` at `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:101`; it is not runtime-observed.
- **mutation outcome source gap (newVersion)** is applicable only to `newVersion` / `ROUTE-0979` at `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:258`; it is not runtime-observed.
- **information presented** is applicable only to `attestations` / `ROUTE-0980` at `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:208`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0981` at `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:42`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Policies/Attestations.tsx`, `resources/js/pages/Governance/Policies/Create.tsx`, `resources/js/pages/Governance/Policies/Edit.tsx`, `resources/js/pages/Governance/Policies/Index.tsx`, `resources/js/pages/Governance/Policies/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0973` / `store`: fields `title`, `category`, `description`, `content`, `effective_date`, `review_date`, `requires_attestation`, `attestation_frequency`; success app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:80 `->with('success', 'Policy created.');`.
- `ROUTE-0975` / `update`: fields `title`, `category`, `description`, `content`, `review_date`, `requires_attestation`; success app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:153 `return redirect()->back()->with('success', 'Policy updated.');`.
- `ROUTE-0976` / `approve`: success app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:168 `return redirect()->back()->with('success', 'Policy approved and published.');`.
- `ROUTE-0977` / `attest`: fields `acknowledged`, `notes`; success app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:199 `return redirect()->back()->with('success', 'Policy attestation recorded.');`.
- `ROUTE-0979` / `newVersion`: fields `content`, `change_summary`; success app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:275 `->with('success', 'New policy draft created.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:64 `$policy = GovernancePolicy::create([`; app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:151 `$policy->update($payload);`; app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:160 `$policy->update([`; app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:180 `PolicyAttestation::updateOrCreate(`; app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:268 `$newPolicy->update([`; responses app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:27 `return Inertia::render('Governance/Policies/Index', [`; app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:79 `return redirect()->route('governance.policies.show', $policy)`; app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:94 `return Inertia::render('Governance/Policies/Show', [`; app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:153 `return redirect()->back()->with('success', 'Policy updated.');`; app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:168 `return redirect()->back()->with('success', 'Policy approved and published.');`; app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:199 `return redirect()->back()->with('success', 'Policy attestation recorded.');`; app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:105 `return Inertia::render('Governance/Policies/Edit', [`; app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:274 `return redirect()->route('governance.policies.show', $newPolicy)`; app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:225 `return [`; app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:245 `return Inertia::render('Governance/Policies/Attestations', [`; app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:46 `return Inertia::render('Governance/Policies/Create');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/policies` — `governance.policies.index` — `App\Domain\Governance\Http\Controllers\GovernancePolicyController@index` — `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:15` — middleware `web, auth, permission:governance.policies.view`
- `POST governance/policies` — `governance.policies.store` — `App\Domain\Governance\Http\Controllers\GovernancePolicyController@store` — `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:49` — middleware `web, auth, permission:governance.policies.view, permission:governance.policies.manage`
- `GET|HEAD governance/policies/{policy}` — `governance.policies.show` — `App\Domain\Governance\Http\Controllers\GovernancePolicyController@show` — `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:83` — middleware `web, auth, permission:governance.policies.view`
- `PUT governance/policies/{policy}` — `governance.policies.update` — `App\Domain\Governance\Http\Controllers\GovernancePolicyController@update` — `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:110` — middleware `web, auth, permission:governance.policies.view, permission:governance.policies.manage`
- `POST governance/policies/{policy}/approve` — `governance.policies.approve` — `App\Domain\Governance\Http\Controllers\GovernancePolicyController@approve` — `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:156` — middleware `web, auth, permission:governance.policies.view, permission:governance.policies.manage`
- `POST governance/policies/{policy}/attest` — `governance.policies.attest` — `App\Domain\Governance\Http\Controllers\GovernancePolicyController@attest` — `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:171` — middleware `web, auth, permission:governance.policies.view, permission:governance.policies.manage`
- `GET|HEAD governance/policies/{policy}/edit` — `governance.policies.edit` — `App\Domain\Governance\Http\Controllers\GovernancePolicyController@edit` — `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:101` — middleware `web, auth, permission:governance.policies.view`
- `POST governance/policies/{policy}/version` — `governance.policies.version` — `App\Domain\Governance\Http\Controllers\GovernancePolicyController@newVersion` — `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:258` — middleware `web, auth, permission:governance.policies.view, permission:governance.policies.manage`
- `GET|HEAD governance/policies/attestations` — `governance.policies.attestations` — `App\Domain\Governance\Http\Controllers\GovernancePolicyController@attestations` — `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:208` — middleware `web, auth, permission:governance.policies.view`
- `GET|HEAD governance/policies/create` — `governance.policies.create` — `App\Domain\Governance\Http\Controllers\GovernancePolicyController@create` — `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:42` — middleware `web, auth, permission:governance.policies.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Policies/Attestations.tsx`, `resources/js/pages/Governance/Policies/Create.tsx`, `resources/js/pages/Governance/Policies/Edit.tsx`, `resources/js/pages/Governance/Policies/Index.tsx`, `resources/js/pages/Governance/Policies/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
