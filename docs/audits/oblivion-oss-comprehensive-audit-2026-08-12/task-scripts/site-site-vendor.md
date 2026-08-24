# SITE-SITE-VENDOR: Site Vendor

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:vendors.view`, `permission:vendors.manage`, `permission:vendors.view|credentials.view`, `permission:credentials.view`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-VENDOR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/vendors` (`sites.vendors.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:vendors.view`, `permission:vendors.manage`, `permission:vendors.view|credentials.view`, `permission:credentials.view`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:vendors.view`, `permission:vendors.manage`, `permission:vendors.view|credentials.view`, `permission:credentials.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/vendors` (`sites.vendors.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD vendors` (`sites.vendors.global`, action `globalIndex`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteVendorController.php:19-148`.
3. Use `GET|HEAD vendors/audit` (`sites.vendors.audit`, action `globalAudit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteVendorController.php:179-226`.
4. Invoke only the owning control for `POST sites/{site}/vendors` (`sites.vendors.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteVendorController.php:241-271`; `service_type`, `company_name`, `contact_name`, `phone`, `after_hours_phone`, `email`, `account_number`, `notes`, `preferred_contact_method`, `is_preferred`.
5. Invoke only the owning control for `DELETE sites/{site}/vendors/{vendor}` (`sites.vendors.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteVendorController.php:301-318`; no exact validation fields extracted.
6. Invoke only the owning control for `PUT sites/{site}/vendors/{vendor}` (`sites.vendors.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteVendorController.php:273-299`; `service_type`, `company_name`, `contact_name`, `phone`, `after_hours_phone`, `email`, `account_number`, `notes`, `preferred_contact_method`, `is_preferred`, `is_active`.
7. Invoke only the owning control for `PATCH sites/{site}/vendors/{vendor}/flags` (`sites.vendors.flags`, action `toggleVendorFlags`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteVendorController.php:154-172`; `is_preferred`, `is_active`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2888` at `app/Http/Controllers/Sites/SiteVendorController.php:228`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2889` at `app/Http/Controllers/Sites/SiteVendorController.php:241`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2890` at `app/Http/Controllers/Sites/SiteVendorController.php:301`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2891` at `app/Http/Controllers/Sites/SiteVendorController.php:273`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggleVendorFlags` / `ROUTE-2892` at `app/Http/Controllers/Sites/SiteVendorController.php:154`; it is not runtime-observed.
- **information presented** is applicable only to `globalIndex` / `ROUTE-3021` at `app/Http/Controllers/Sites/SiteVendorController.php:19`; it is not runtime-observed.
- **information presented** is applicable only to `globalAudit` / `ROUTE-3022` at `app/Http/Controllers/Sites/SiteVendorController.php:179`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/vendors-credentials/global.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2889` / `store`: fields `service_type`, `company_name`, `contact_name`, `phone`, `after_hours_phone`, `email`, `account_number`, `notes`, `preferred_contact_method`, `is_preferred`; success app/Http/Controllers/Sites/SiteVendorController.php:270 `return back(303)->with('success', 'Vendor added successfully.');`; failure app/Http/Controllers/Sites/SiteVendorController.php:244 `$request->user()->canDo('vendors.manage') || abort(403);`.
- `ROUTE-2890` / `destroy`: success app/Http/Controllers/Sites/SiteVendorController.php:317 `return back(303)->with('success', 'Vendor deleted successfully.');`; failure app/Http/Controllers/Sites/SiteVendorController.php:304 `$request->user()->canDo('vendors.manage') || abort(403);`.
- `ROUTE-2891` / `update`: fields `service_type`, `company_name`, `contact_name`, `phone`, `after_hours_phone`, `email`, `account_number`, `notes`, `preferred_contact_method`, `is_preferred`, `is_active`; success app/Http/Controllers/Sites/SiteVendorController.php:298 `return back(303)->with('success', 'Vendor updated successfully.');`; failure app/Http/Controllers/Sites/SiteVendorController.php:276 `$request->user()->canDo('vendors.manage') || abort(403);`.
- `ROUTE-2892` / `toggleVendorFlags`: fields `is_preferred`, `is_active`; success app/Http/Controllers/Sites/SiteVendorController.php:171 `return back(303)->with('success', 'Vendor updated.');`; failure app/Http/Controllers/Sites/SiteVendorController.php:157 `$request->user()->canDo('vendors.manage') || abort(403);`.

## Failure and recovery paths

- `store`: app/Http/Controllers/Sites/SiteVendorController.php:244 `$request->user()->canDo('vendors.manage') || abort(403);`.
- `destroy`: app/Http/Controllers/Sites/SiteVendorController.php:304 `$request->user()->canDo('vendors.manage') || abort(403);`.
- `update`: app/Http/Controllers/Sites/SiteVendorController.php:276 `$request->user()->canDo('vendors.manage') || abort(403);`.
- `toggleVendorFlags`: app/Http/Controllers/Sites/SiteVendorController.php:157 `$request->user()->canDo('vendors.manage') || abort(403);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteVendorController.php:263 `SiteVendor::create([`; app/Http/Controllers/Sites/SiteVendorController.php:315 `$vendor->delete();`; app/Http/Controllers/Sites/SiteVendorController.php:296 `$vendor->update($validated);`; app/Http/Controllers/Sites/SiteVendorController.php:169 `$vendor->update($validated);`; responses app/Http/Controllers/Sites/SiteVendorController.php:235 `return redirect()->route('sites.vendors.global', [`; app/Http/Controllers/Sites/SiteVendorController.php:270 `return back(303)->with('success', 'Vendor added successfully.');`; app/Http/Controllers/Sites/SiteVendorController.php:309 `return back(303)->with(`; app/Http/Controllers/Sites/SiteVendorController.php:317 `return back(303)->with('success', 'Vendor deleted successfully.');`; app/Http/Controllers/Sites/SiteVendorController.php:298 `return back(303)->with('success', 'Vendor updated successfully.');`; app/Http/Controllers/Sites/SiteVendorController.php:166 `return back(303);`; app/Http/Controllers/Sites/SiteVendorController.php:171 `return back(303)->with('success', 'Vendor updated.');`; app/Http/Controllers/Sites/SiteVendorController.php:123 `return inertia('sites/vendors-credentials/global', [`; app/Http/Controllers/Sites/SiteVendorController.php:208 `return [`; app/Http/Controllers/Sites/SiteVendorController.php:225 `return response()->json(['logs' => $logs]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/vendors` — `sites.vendors.index` — `App\Http\Controllers\Sites\SiteVendorController@index` — `app/Http/Controllers/Sites/SiteVendorController.php:228` — middleware `web, auth, verified, permission:sites.viewAny, permission:vendors.view`
- `POST sites/{site}/vendors` — `sites.vendors.store` — `App\Http\Controllers\Sites\SiteVendorController@store` — `app/Http/Controllers/Sites/SiteVendorController.php:241` — middleware `web, auth, verified, permission:sites.viewAny, permission:vendors.manage`
- `DELETE sites/{site}/vendors/{vendor}` — `sites.vendors.destroy` — `App\Http\Controllers\Sites\SiteVendorController@destroy` — `app/Http/Controllers/Sites/SiteVendorController.php:301` — middleware `web, auth, verified, permission:sites.viewAny, permission:vendors.manage`
- `PUT sites/{site}/vendors/{vendor}` — `sites.vendors.update` — `App\Http\Controllers\Sites\SiteVendorController@update` — `app/Http/Controllers/Sites/SiteVendorController.php:273` — middleware `web, auth, verified, permission:sites.viewAny, permission:vendors.manage`
- `PATCH sites/{site}/vendors/{vendor}/flags` — `sites.vendors.flags` — `App\Http\Controllers\Sites\SiteVendorController@toggleVendorFlags` — `app/Http/Controllers/Sites/SiteVendorController.php:154` — middleware `web, auth, verified, permission:sites.viewAny, permission:vendors.manage`
- `GET|HEAD vendors` — `sites.vendors.global` — `App\Http\Controllers\Sites\SiteVendorController@globalIndex` — `app/Http/Controllers/Sites/SiteVendorController.php:19` — middleware `web, auth, verified, permission:vendors.view|credentials.view`
- `GET|HEAD vendors/audit` — `sites.vendors.audit` — `App\Http\Controllers\Sites\SiteVendorController@globalAudit` — `app/Http/Controllers/Sites/SiteVendorController.php:179` — middleware `web, auth, verified, permission:credentials.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteVendorController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/vendors-credentials/global.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
