# PRIV-DATA-RETENTION-POLICY: Data Retention Policy

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:privacy.manageRetention`
- Owning module: Privacy and compliance
- Legacy family: `PRIV-DATA-RETENTION-POLICY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `privacy/retention` (`privacy.retention.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:privacy.manageRetention`.
- Exact middleware atoms: `web`, `auth`, `permission:privacy.manageRetention`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD privacy/retention` (`privacy.retention.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD privacy/retention/{policy}/edit` (`privacy.retention.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/DataRetentionPolicyController.php:98-105`.
3. Use `GET|HEAD privacy/retention/create` (`privacy.retention.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/DataRetentionPolicyController.php:51-56`.
4. Use `GET|HEAD privacy/retention/review` (`privacy.retention.review`, action `review`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/DataRetentionPolicyController.php:141-150`.
5. Invoke only the owning control for `POST privacy/retention` (`privacy.retention.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/DataRetentionPolicyController.php:61-93`; `model_type`, `policy_name`, `description`, `retention_period_years`, `archive_after_years`, `hard_delete_after_years`, `retention_conditions`, `applies_to_soft_deleted`, `legal_hold_exemption`, `active_case_exemption`, `legal_basis`, `business_justification`, `active`, `next_review_at`.
6. Invoke only the owning control for `PUT privacy/retention/{policy}` (`privacy.retention.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/DataRetentionPolicyController.php:110-136`; `policy_name`, `description`, `retention_period_years`, `archive_after_years`, `hard_delete_after_years`, `retention_conditions`, `applies_to_soft_deleted`, `legal_hold_exemption`, `active_case_exemption`, `legal_basis`, `business_justification`, `active`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2335` at `app/Http/Controllers/DataRetentionPolicyController.php:16`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2336` at `app/Http/Controllers/DataRetentionPolicyController.php:61`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2337` at `app/Http/Controllers/DataRetentionPolicyController.php:110`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2338` at `app/Http/Controllers/DataRetentionPolicyController.php:98`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2339` at `app/Http/Controllers/DataRetentionPolicyController.php:51`; it is not runtime-observed.
- **information presented** is applicable only to `review` / `ROUTE-2340` at `app/Http/Controllers/DataRetentionPolicyController.php:141`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/privacy/retention/edit.tsx`, `resources/js/pages/privacy/retention/review.tsx`, `resources/js/pages/privacy/retention.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2336` / `store`: fields `model_type`, `policy_name`, `description`, `retention_period_years`, `archive_after_years`, `hard_delete_after_years`, `retention_conditions`, `applies_to_soft_deleted`, `legal_hold_exemption`, `active_case_exemption`, `legal_basis`, `business_justification`, `active`, `next_review_at`; success app/Http/Controllers/DataRetentionPolicyController.php:87 `return back()->with('success', 'Retention policy created successfully.');`; app/Http/Controllers/DataRetentionPolicyController.php:92 `->with('success', 'Retention policy created successfully.');`.
- `ROUTE-2337` / `update`: fields `policy_name`, `description`, `retention_period_years`, `archive_after_years`, `hard_delete_after_years`, `retention_conditions`, `applies_to_soft_deleted`, `legal_hold_exemption`, `active_case_exemption`, `legal_basis`, `business_justification`, `active`; success app/Http/Controllers/DataRetentionPolicyController.php:135 `->with('success', 'Retention policy updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/DataRetentionPolicyController.php:84 `DataRetentionPolicy::create($validated);`; app/Http/Controllers/DataRetentionPolicyController.php:131 `$policy->update($validated);`; responses app/Http/Controllers/DataRetentionPolicyController.php:38 `return Inertia::render('privacy/retention', [`; app/Http/Controllers/DataRetentionPolicyController.php:87 `return back()->with('success', 'Retention policy created successfully.');`; app/Http/Controllers/DataRetentionPolicyController.php:90 `return redirect()`; app/Http/Controllers/DataRetentionPolicyController.php:133 `return redirect()`; app/Http/Controllers/DataRetentionPolicyController.php:102 `return Inertia::render('privacy/retention/edit', [`; app/Http/Controllers/DataRetentionPolicyController.php:55 `return redirect('/privacy/dashboard?new=retention');`; app/Http/Controllers/DataRetentionPolicyController.php:147 `return Inertia::render('privacy/retention/review', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD privacy/retention` — `privacy.retention.index` — `App\Http\Controllers\DataRetentionPolicyController@index` — `app/Http/Controllers/DataRetentionPolicyController.php:16` — middleware `web, auth, permission:privacy.manageRetention`
- `POST privacy/retention` — `privacy.retention.store` — `App\Http\Controllers\DataRetentionPolicyController@store` — `app/Http/Controllers/DataRetentionPolicyController.php:61` — middleware `web, auth, permission:privacy.manageRetention`
- `PUT privacy/retention/{policy}` — `privacy.retention.update` — `App\Http\Controllers\DataRetentionPolicyController@update` — `app/Http/Controllers/DataRetentionPolicyController.php:110` — middleware `web, auth, permission:privacy.manageRetention`
- `GET|HEAD privacy/retention/{policy}/edit` — `privacy.retention.edit` — `App\Http\Controllers\DataRetentionPolicyController@edit` — `app/Http/Controllers/DataRetentionPolicyController.php:98` — middleware `web, auth, permission:privacy.manageRetention`
- `GET|HEAD privacy/retention/create` — `privacy.retention.create` — `App\Http\Controllers\DataRetentionPolicyController@create` — `app/Http/Controllers/DataRetentionPolicyController.php:51` — middleware `web, auth, permission:privacy.manageRetention`
- `GET|HEAD privacy/retention/review` — `privacy.retention.review` — `App\Http\Controllers\DataRetentionPolicyController@review` — `app/Http/Controllers/DataRetentionPolicyController.php:141` — middleware `web, auth, permission:privacy.manageRetention`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/DataRetentionPolicyController.php`.
- Exact render/action page relationships: `resources/js/pages/privacy/retention/edit.tsx`, `resources/js/pages/privacy/retention/review.tsx`, `resources/js/pages/privacy/retention.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
