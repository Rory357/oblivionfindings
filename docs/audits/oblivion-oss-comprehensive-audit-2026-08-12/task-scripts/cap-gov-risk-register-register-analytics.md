# CAP-GOV-RISK-REGISTER-REGISTER-ANALYTICS: Risk register event linkage and analytics

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.risks.view`, `permission:governance.risks.manage`
- Owning module: Governance
- Legacy family: `GOV-RISK-REGISTER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/risks` (`governance.risks.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.risks.view`, `permission:governance.risks.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.risks.view`, `permission:governance.risks.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/risks` (`governance.risks.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/risks/{risk}` (`governance.risks.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:63-98`.
3. Use `GET|HEAD governance/risks/{risk}/edit` (`governance.risks.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:251-256`.
4. Use `GET|HEAD governance/risks/committee/{committee}` (`governance.risks.committee`, action `committeeView`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:277-299`.
5. Use `GET|HEAD governance/risks/create` (`governance.risks.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:24-29`.
6. Use `GET|HEAD governance/risks/heatmap` (`governance.risks.heatmap`, action `heatmap`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:258-264`.
7. Use `GET|HEAD governance/risks/trends` (`governance.risks.trends`, action `trends`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:266-275`.
8. Invoke only the owning control for `POST governance/risks` (`governance.risks.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:100-143`; FormRequest `app/Domain/Governance/Http/Requests/StoreRiskRegisterRequest.php:15`; `category`, `title`, `description`, `likelihood_score`, `impact_score`, `control_effectiveness`, `risk_owner_id`, `mitigation_strategy`, `review_frequency`.
9. Invoke only the owning control for `PUT governance/risks/{risk}` (`governance.risks.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:145-159`; FormRequest `app/Domain/Governance/Http/Requests/UpdateRiskRegisterRequest.php:14`; `title`, `description`, `likelihood_score`, `impact_score`, `control_effectiveness`, `risk_owner_id`, `mitigation_strategy`.
10. Invoke only the owning control for `POST governance/risks/{risk}/link-event` (`governance.risks.events.link`, action `linkEvent`). Source category: **mutation outcome source gap (linkEvent)**; controller `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:231-249`; `event_type`, `event_id`, `event_reference`, `event_severity`, `event_occurred_at`, `link_rationale`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1001` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:31`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1002` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:100`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1003` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:63`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1004` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:145`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-1007` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:251`; it is not runtime-observed.
- **mutation outcome source gap (linkEvent)** is applicable only to `linkEvent` / `ROUTE-1008` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:231`; it is not runtime-observed.
- **information presented** is applicable only to `committeeView` / `ROUTE-1013` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:277`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1014` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:24`; it is not runtime-observed.
- **information presented** is applicable only to `heatmap` / `ROUTE-1015` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:258`; it is not runtime-observed.
- **information presented** is applicable only to `trends` / `ROUTE-1016` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:266`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Risks/Committee.tsx`, `resources/js/pages/Governance/Risks/Create.tsx`, `resources/js/pages/Governance/Risks/Edit.tsx`, `resources/js/pages/Governance/Risks/Heatmap.tsx`, `resources/js/pages/Governance/Risks/Index.tsx`, `resources/js/pages/Governance/Risks/Show.tsx`, `resources/js/pages/Governance/Risks/Trends.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1002` / `store`: FormRequest `app/Domain/Governance/Http/Requests/StoreRiskRegisterRequest.php:15`; fields `category`, `title`, `description`, `likelihood_score`, `impact_score`, `control_effectiveness`, `risk_owner_id`, `mitigation_strategy`, `review_frequency`; success app/Domain/Governance/Http/Controllers/RiskRegisterController.php:142 `->with('success', 'Risk registered successfully.');`.
- `ROUTE-1004` / `update`: FormRequest `app/Domain/Governance/Http/Requests/UpdateRiskRegisterRequest.php:14`; fields `title`, `description`, `likelihood_score`, `impact_score`, `control_effectiveness`, `risk_owner_id`, `mitigation_strategy`; success app/Domain/Governance/Http/Controllers/RiskRegisterController.php:158 `return redirect()->back()->with('success', 'Risk updated.');`.
- `ROUTE-1008` / `linkEvent`: fields `event_type`, `event_id`, `event_reference`, `event_severity`, `event_occurred_at`, `link_rationale`; success app/Domain/Governance/Http/Controllers/RiskRegisterController.php:248 `return redirect()->back()->with('success', 'Event linked to risk.');`.
- `ROUTE-1013` / `committeeView`: failure app/Domain/Governance/Http/Controllers/RiskRegisterController.php:281 `abort(404);`.

## Failure and recovery paths

- `committeeView`: app/Domain/Governance/Http/Controllers/RiskRegisterController.php:281 `abort(404);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/RiskRegisterController.php:116 `$risk = RiskRegisterEntry::create([`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:149 `$risk->update($validated);`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:242 `$risk->events()->create([`; responses app/Domain/Governance/Http/Controllers/RiskRegisterController.php:55 `return Inertia::render('Governance/Risks/Index', [`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:141 `return redirect()->route('governance.risks.show', $risk)`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:75 `return [`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:89 `return Inertia::render('Governance/Risks/Show', [`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:158 `return redirect()->back()->with('success', 'Risk updated.');`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:253 `return Inertia::render('Governance/Risks/Edit', [`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:248 `return redirect()->back()->with('success', 'Event linked to risk.');`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:295 `return Inertia::render('Governance/Risks/Committee', [`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:26 `return Inertia::render('Governance/Risks/Create', [`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:260 `return Inertia::render('Governance/Risks/Heatmap', [`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:272 `return Inertia::render('Governance/Risks/Trends', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/risks` — `governance.risks.index` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@index` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:31` — middleware `web, auth, permission:governance.risks.view`
- `POST governance/risks` — `governance.risks.store` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@store` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:100` — middleware `web, auth, permission:governance.risks.view, permission:governance.risks.manage`
- `GET|HEAD governance/risks/{risk}` — `governance.risks.show` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@show` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:63` — middleware `web, auth, permission:governance.risks.view`
- `PUT governance/risks/{risk}` — `governance.risks.update` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@update` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:145` — middleware `web, auth, permission:governance.risks.view, permission:governance.risks.manage`
- `GET|HEAD governance/risks/{risk}/edit` — `governance.risks.edit` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@edit` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:251` — middleware `web, auth, permission:governance.risks.view`
- `POST governance/risks/{risk}/link-event` — `governance.risks.events.link` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@linkEvent` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:231` — middleware `web, auth, permission:governance.risks.view, permission:governance.risks.manage`
- `GET|HEAD governance/risks/committee/{committee}` — `governance.risks.committee` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@committeeView` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:277` — middleware `web, auth, permission:governance.risks.view`
- `GET|HEAD governance/risks/create` — `governance.risks.create` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@create` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:24` — middleware `web, auth, permission:governance.risks.view`
- `GET|HEAD governance/risks/heatmap` — `governance.risks.heatmap` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@heatmap` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:258` — middleware `web, auth, permission:governance.risks.view`
- `GET|HEAD governance/risks/trends` — `governance.risks.trends` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@trends` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:266` — middleware `web, auth, permission:governance.risks.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/RiskRegisterController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Risks/Committee.tsx`, `resources/js/pages/Governance/Risks/Create.tsx`, `resources/js/pages/Governance/Risks/Edit.tsx`, `resources/js/pages/Governance/Risks/Heatmap.tsx`, `resources/js/pages/Governance/Risks/Index.tsx`, `resources/js/pages/Governance/Risks/Show.tsx`, `resources/js/pages/Governance/Risks/Trends.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
