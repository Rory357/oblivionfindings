# PRIV-COMPLIANCE: Compliance

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.compliance.view`, `permission:governance.compliance.manage`
- Owning module: Privacy and compliance
- Legacy family: `PRIV-COMPLIANCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/compliance` (`governance.compliance.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.compliance.view`, `permission:governance.compliance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.compliance.view`, `permission:governance.compliance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/compliance` (`governance.compliance.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/compliance/{obligation}` (`governance.compliance.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/ComplianceController.php:58-67`.
3. Use `GET|HEAD governance/compliance/{obligation}/edit` (`governance.compliance.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/ComplianceController.php:190-197`.
4. Use `GET|HEAD governance/compliance/calendar` (`governance.compliance.calendar`, action `calendar`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/ComplianceController.php:168-188`.
5. Use `GET|HEAD governance/compliance/create` (`governance.compliance.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/ComplianceController.php:21-28`.
6. Invoke only the owning control for `POST governance/compliance` (`governance.compliance.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/ComplianceController.php:69-102`; FormRequest `app/Domain/Governance/Http/Requests/StoreComplianceObligationRequest.php:14`; `framework`, `obligation_reference`, `title`, `description`, `requirements`, `frequency`, `due_date`, `owner_id`, `priority`.
7. Invoke only the owning control for `PUT governance/compliance/{obligation}` (`governance.compliance.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/ComplianceController.php:104-124`; `title`, `description`, `due_date`, `owner_id`, `notes`.
8. Invoke only the owning control for `POST governance/compliance/{obligation}/complete` (`governance.compliance.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Domain/Governance/Http/Controllers/ComplianceController.php:126-142`; `evidence_ids`.
9. Invoke only the owning control for `POST governance/compliance/{obligation}/evidence` (`governance.compliance.evidence.upload`, action `uploadEvidence`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/ComplianceController.php:144-166`; `evidence_type`, `title`, `description`, `file`, `valid_until`.
10. Invoke only the owning control for `POST governance/compliance/notifiable-incident` (`governance.compliance.notifiable-incident.store`, action `storeNotifiableIncident`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/ComplianceController.php:199-227`; `incident_type`, `notification_authority`, `title`, `description`, `severity`, `occurred_at`, `discovered_at`, `related_incident_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0901` at `app/Domain/Governance/Http/Controllers/ComplianceController.php:30`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0902` at `app/Domain/Governance/Http/Controllers/ComplianceController.php:69`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0903` at `app/Domain/Governance/Http/Controllers/ComplianceController.php:58`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0904` at `app/Domain/Governance/Http/Controllers/ComplianceController.php:104`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-0905` at `app/Domain/Governance/Http/Controllers/ComplianceController.php:126`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-0906` at `app/Domain/Governance/Http/Controllers/ComplianceController.php:190`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadEvidence` / `ROUTE-0907` at `app/Domain/Governance/Http/Controllers/ComplianceController.php:144`; it is not runtime-observed.
- **information presented** is applicable only to `calendar` / `ROUTE-0908` at `app/Domain/Governance/Http/Controllers/ComplianceController.php:168`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0909` at `app/Domain/Governance/Http/Controllers/ComplianceController.php:21`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeNotifiableIncident` / `ROUTE-0910` at `app/Domain/Governance/Http/Controllers/ComplianceController.php:199`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Compliance/Calendar.tsx`, `resources/js/pages/Governance/Compliance/Create.tsx`, `resources/js/pages/Governance/Compliance/Edit.tsx`, `resources/js/pages/Governance/Compliance/Index.tsx`, `resources/js/pages/Governance/Compliance/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0902` / `store`: FormRequest `app/Domain/Governance/Http/Requests/StoreComplianceObligationRequest.php:14`; fields `framework`, `obligation_reference`, `title`, `description`, `requirements`, `frequency`, `due_date`, `owner_id`, `priority`; success app/Domain/Governance/Http/Controllers/ComplianceController.php:97 `return back()->with('success', 'Compliance obligation created.');`; app/Domain/Governance/Http/Controllers/ComplianceController.php:101 `->with('success', 'Compliance obligation created.');`.
- `ROUTE-0904` / `update`: fields `title`, `description`, `due_date`, `owner_id`, `notes`; success app/Domain/Governance/Http/Controllers/ComplianceController.php:123 `return redirect()->back()->with('success', 'Obligation updated.');`.
- `ROUTE-0905` / `complete`: fields `evidence_ids`; success app/Domain/Governance/Http/Controllers/ComplianceController.php:141 `return redirect()->back()->with('success', 'Obligation marked complete.');`.
- `ROUTE-0907` / `uploadEvidence`: fields `evidence_type`, `title`, `description`, `file`, `valid_until`; success app/Domain/Governance/Http/Controllers/ComplianceController.php:165 `return redirect()->back()->with('success', 'Evidence uploaded.');`.
- `ROUTE-0910` / `storeNotifiableIncident`: fields `incident_type`, `notification_authority`, `title`, `description`, `severity`, `occurred_at`, `discovered_at`, `related_incident_id`; success app/Domain/Governance/Http/Controllers/ComplianceController.php:223 `return back()->with('success', $message);`; app/Domain/Governance/Http/Controllers/ComplianceController.php:226 `return redirect()->route('governance.compliance.index')->with('success', $message);`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/ComplianceController.php:121 `$obligation->update($validated);`; app/Domain/Governance/Http/Controllers/ComplianceController.php:214 `$incident = NotifiableIncident::create([`; responses app/Domain/Governance/Http/Controllers/ComplianceController.php:50 `return Inertia::render('Governance/Compliance/Index', [`; app/Domain/Governance/Http/Controllers/ComplianceController.php:97 `return back()->with('success', 'Compliance obligation created.');`; app/Domain/Governance/Http/Controllers/ComplianceController.php:100 `return redirect()->route('governance.compliance.show', $obligation)`; app/Domain/Governance/Http/Controllers/ComplianceController.php:64 `return Inertia::render('Governance/Compliance/Show', [`; app/Domain/Governance/Http/Controllers/ComplianceController.php:123 `return redirect()->back()->with('success', 'Obligation updated.');`; app/Domain/Governance/Http/Controllers/ComplianceController.php:141 `return redirect()->back()->with('success', 'Obligation marked complete.');`; app/Domain/Governance/Http/Controllers/ComplianceController.php:194 `return Inertia::render('Governance/Compliance/Edit', [`; app/Domain/Governance/Http/Controllers/ComplianceController.php:165 `return redirect()->back()->with('success', 'Evidence uploaded.');`; app/Domain/Governance/Http/Controllers/ComplianceController.php:178 `return Inertia::render('Governance/Compliance/Calendar', [`; app/Domain/Governance/Http/Controllers/ComplianceController.php:25 `return Inertia::render('Governance/Compliance/Create', [`; app/Domain/Governance/Http/Controllers/ComplianceController.php:223 `return back()->with('success', $message);`; app/Domain/Governance/Http/Controllers/ComplianceController.php:226 `return redirect()->route('governance.compliance.index')->with('success', $message);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/compliance` — `governance.compliance.index` — `App\Domain\Governance\Http\Controllers\ComplianceController@index` — `app/Domain/Governance/Http/Controllers/ComplianceController.php:30` — middleware `web, auth, permission:governance.compliance.view`
- `POST governance/compliance` — `governance.compliance.store` — `App\Domain\Governance\Http\Controllers\ComplianceController@store` — `app/Domain/Governance/Http/Controllers/ComplianceController.php:69` — middleware `web, auth, permission:governance.compliance.view, permission:governance.compliance.manage`
- `GET|HEAD governance/compliance/{obligation}` — `governance.compliance.show` — `App\Domain\Governance\Http\Controllers\ComplianceController@show` — `app/Domain/Governance/Http/Controllers/ComplianceController.php:58` — middleware `web, auth, permission:governance.compliance.view`
- `PUT governance/compliance/{obligation}` — `governance.compliance.update` — `App\Domain\Governance\Http\Controllers\ComplianceController@update` — `app/Domain/Governance/Http/Controllers/ComplianceController.php:104` — middleware `web, auth, permission:governance.compliance.view, permission:governance.compliance.manage`
- `POST governance/compliance/{obligation}/complete` — `governance.compliance.complete` — `App\Domain\Governance\Http\Controllers\ComplianceController@complete` — `app/Domain/Governance/Http/Controllers/ComplianceController.php:126` — middleware `web, auth, permission:governance.compliance.view, permission:governance.compliance.manage`
- `GET|HEAD governance/compliance/{obligation}/edit` — `governance.compliance.edit` — `App\Domain\Governance\Http\Controllers\ComplianceController@edit` — `app/Domain/Governance/Http/Controllers/ComplianceController.php:190` — middleware `web, auth, permission:governance.compliance.view`
- `POST governance/compliance/{obligation}/evidence` — `governance.compliance.evidence.upload` — `App\Domain\Governance\Http\Controllers\ComplianceController@uploadEvidence` — `app/Domain/Governance/Http/Controllers/ComplianceController.php:144` — middleware `web, auth, permission:governance.compliance.view, permission:governance.compliance.manage`
- `GET|HEAD governance/compliance/calendar` — `governance.compliance.calendar` — `App\Domain\Governance\Http\Controllers\ComplianceController@calendar` — `app/Domain/Governance/Http/Controllers/ComplianceController.php:168` — middleware `web, auth, permission:governance.compliance.view`
- `GET|HEAD governance/compliance/create` — `governance.compliance.create` — `App\Domain\Governance\Http\Controllers\ComplianceController@create` — `app/Domain/Governance/Http/Controllers/ComplianceController.php:21` — middleware `web, auth, permission:governance.compliance.view`
- `POST governance/compliance/notifiable-incident` — `governance.compliance.notifiable-incident.store` — `App\Domain\Governance\Http\Controllers\ComplianceController@storeNotifiableIncident` — `app/Domain/Governance/Http/Controllers/ComplianceController.php:199` — middleware `web, auth, permission:governance.compliance.view, permission:governance.compliance.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/ComplianceController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Compliance/Calendar.tsx`, `resources/js/pages/Governance/Compliance/Create.tsx`, `resources/js/pages/Governance/Compliance/Edit.tsx`, `resources/js/pages/Governance/Compliance/Index.tsx`, `resources/js/pages/Governance/Compliance/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
