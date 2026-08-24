# CAP-SITE-SITE-COMPLIANCE-CERTIFICATIONS: Site certification records

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.update`, `permission:sites.viewAny`
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

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.update`, `permission:sites.viewAny`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.update`, `permission:sites.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/compliance` (`sites.compliance.dashboard`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST sites/{site}/certifications` (`sites.certifications.store`, action `storeCertification`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteComplianceController.php:85-110`; `certification_type`, `name`, `issuing_body`, `reference_number`, `issued_date`, `expiry_date`, `next_review_date`, `notes`, `document_path`.
3. Invoke only the owning control for `DELETE sites/{site}/certifications/{certification}` (`sites.certifications.destroy`, action `destroyCertification`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteComplianceController.php:138-147`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT sites/{site}/certifications/{certification}` (`sites.certifications.update`, action `updateCertification`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteComplianceController.php:112-136`; `certification_type`, `name`, `issuing_body`, `reference_number`, `issued_date`, `expiry_date`, `next_review_date`, `notes`, `document_path`, `reviewed_by`, `reviewed_at`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeCertification` / `ROUTE-2741` at `app/Http/Controllers/Sites/SiteComplianceController.php:85`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyCertification` / `ROUTE-2742` at `app/Http/Controllers/Sites/SiteComplianceController.php:138`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateCertification` / `ROUTE-2743` at `app/Http/Controllers/Sites/SiteComplianceController.php:112`; it is not runtime-observed.
- **information presented** is applicable only to `dashboard` / `ROUTE-2752` at `app/Http/Controllers/Sites/SiteComplianceController.php:16`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/compliance/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2741` / `storeCertification`: fields `certification_type`, `name`, `issuing_body`, `reference_number`, `issued_date`, `expiry_date`, `next_review_date`, `notes`, `document_path`; success app/Http/Controllers/Sites/SiteComplianceController.php:109 `return redirect()->back()->with('success', 'Certification added successfully.');`.
- `ROUTE-2742` / `destroyCertification`: success app/Http/Controllers/Sites/SiteComplianceController.php:146 `return redirect()->back()->with('success', 'Certification removed successfully.');`.
- `ROUTE-2743` / `updateCertification`: fields `certification_type`, `name`, `issuing_body`, `reference_number`, `issued_date`, `expiry_date`, `next_review_date`, `notes`, `document_path`, `reviewed_by`, `reviewed_at`; success app/Http/Controllers/Sites/SiteComplianceController.php:135 `return redirect()->back()->with('success', 'Certification updated successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteComplianceController.php:102 `$certification = SiteCertification::create([`; app/Http/Controllers/Sites/SiteComplianceController.php:144 `$certification->delete();`; app/Http/Controllers/Sites/SiteComplianceController.php:133 `$certification->update($validated);`; responses app/Http/Controllers/Sites/SiteComplianceController.php:109 `return redirect()->back()->with('success', 'Certification added successfully.');`; app/Http/Controllers/Sites/SiteComplianceController.php:146 `return redirect()->back()->with('success', 'Certification removed successfully.');`; app/Http/Controllers/Sites/SiteComplianceController.php:135 `return redirect()->back()->with('success', 'Certification updated successfully.');`; app/Http/Controllers/Sites/SiteComplianceController.php:60 `return $cert->status === 'current'`; app/Http/Controllers/Sites/SiteComplianceController.php:70 `return inertia('sites/compliance/Index', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST sites/{site}/certifications` — `sites.certifications.store` — `App\Http\Controllers\Sites\SiteComplianceController@storeCertification` — `app/Http/Controllers/Sites/SiteComplianceController.php:85` — middleware `web, auth, verified, permission:sites.update`
- `DELETE sites/{site}/certifications/{certification}` — `sites.certifications.destroy` — `App\Http\Controllers\Sites\SiteComplianceController@destroyCertification` — `app/Http/Controllers/Sites/SiteComplianceController.php:138` — middleware `web, auth, verified, permission:sites.update`
- `PUT sites/{site}/certifications/{certification}` — `sites.certifications.update` — `App\Http\Controllers\Sites\SiteComplianceController@updateCertification` — `app/Http/Controllers/Sites/SiteComplianceController.php:112` — middleware `web, auth, verified, permission:sites.update`
- `GET|HEAD sites/{site}/compliance` — `sites.compliance.dashboard` — `App\Http\Controllers\Sites\SiteComplianceController@dashboard` — `app/Http/Controllers/Sites/SiteComplianceController.php:16` — middleware `web, auth, verified, permission:sites.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteComplianceController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/compliance/Index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
