# CAP-SITE-SITE-COMPLIANCE-CHECKS: Site compliance checks and completion

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.update`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-COMPLIANCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/compliance` (`sites.compliance.dashboard`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.update`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/compliance` (`sites.compliance.dashboard`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST sites/{site}/compliance-checks` (`sites.compliance_checks.store`, action `storeCheck`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteComplianceController.php:149-172`; `check_type`, `scheduled_date`, `findings`, `corrective_actions`, `risk_rating`, `follow_up_date`, `follow_up_notes`.
3. Invoke only the owning control for `PUT sites/{site}/compliance-checks/{check}` (`sites.compliance_checks.update`, action `updateCheck`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteComplianceController.php:198-218`; `check_type`, `scheduled_date`, `findings`, `corrective_actions`, `risk_rating`, `follow_up_date`, `follow_up_notes`.
4. Invoke only the owning control for `PATCH sites/{site}/compliance-checks/{check}/complete` (`sites.compliance_checks.complete`, action `completeCheck`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Sites/SiteComplianceController.php:174-196`; `findings`, `corrective_actions`, `risk_rating`, `follow_up_date`, `follow_up_notes`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeCheck` / `ROUTE-2753` at `app/Http/Controllers/Sites/SiteComplianceController.php:149`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateCheck` / `ROUTE-2754` at `app/Http/Controllers/Sites/SiteComplianceController.php:198`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `completeCheck` / `ROUTE-2755` at `app/Http/Controllers/Sites/SiteComplianceController.php:174`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2753` / `storeCheck`: fields `check_type`, `scheduled_date`, `findings`, `corrective_actions`, `risk_rating`, `follow_up_date`, `follow_up_notes`; success app/Http/Controllers/Sites/SiteComplianceController.php:171 `return redirect()->back()->with('success', 'Compliance check scheduled successfully.');`.
- `ROUTE-2754` / `updateCheck`: fields `check_type`, `scheduled_date`, `findings`, `corrective_actions`, `risk_rating`, `follow_up_date`, `follow_up_notes`; success app/Http/Controllers/Sites/SiteComplianceController.php:217 `return redirect()->back()->with('success', 'Compliance check updated successfully.');`.
- `ROUTE-2755` / `completeCheck`: fields `findings`, `corrective_actions`, `risk_rating`, `follow_up_date`, `follow_up_notes`; success app/Http/Controllers/Sites/SiteComplianceController.php:195 `return redirect()->back()->with('success', 'Compliance check completed successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteComplianceController.php:164 `$check = SiteComplianceCheck::create([`; app/Http/Controllers/Sites/SiteComplianceController.php:215 `$check->update($validated);`; app/Http/Controllers/Sites/SiteComplianceController.php:188 `$check->update([`; responses app/Http/Controllers/Sites/SiteComplianceController.php:171 `return redirect()->back()->with('success', 'Compliance check scheduled successfully.');`; app/Http/Controllers/Sites/SiteComplianceController.php:217 `return redirect()->back()->with('success', 'Compliance check updated successfully.');`; app/Http/Controllers/Sites/SiteComplianceController.php:195 `return redirect()->back()->with('success', 'Compliance check completed successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST sites/{site}/compliance-checks` — `sites.compliance_checks.store` — `App\Http\Controllers\Sites\SiteComplianceController@storeCheck` — `app/Http/Controllers/Sites/SiteComplianceController.php:149` — middleware `web, auth, verified, permission:sites.update`
- `PUT sites/{site}/compliance-checks/{check}` — `sites.compliance_checks.update` — `App\Http\Controllers\Sites\SiteComplianceController@updateCheck` — `app/Http/Controllers/Sites/SiteComplianceController.php:198` — middleware `web, auth, verified, permission:sites.update`
- `PATCH sites/{site}/compliance-checks/{check}/complete` — `sites.compliance_checks.complete` — `App\Http\Controllers\Sites\SiteComplianceController@completeCheck` — `app/Http/Controllers/Sites/SiteComplianceController.php:174` — middleware `web, auth, verified, permission:sites.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteComplianceController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
