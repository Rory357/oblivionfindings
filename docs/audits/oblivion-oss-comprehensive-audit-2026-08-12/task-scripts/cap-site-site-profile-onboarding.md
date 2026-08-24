# CAP-SITE-SITE-PROFILE-ONBOARDING: Site profile location contact safety and onboarding

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:sites.viewAny`, `permission:sites.create`, `permission:sites.update`, `verified`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites` (`sites.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:sites.viewAny`, `permission:sites.create`, `permission:sites.update`, `verified`.
- Exact middleware atoms: `web`, `auth`, `permission:sites.viewAny`, `permission:sites.create`, `permission:sites.update`, `verified`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites` (`sites.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD sites/{site}` (`sites.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/SiteController.php:272-811`.
3. Use `GET|HEAD sites/{site}/edit` (`sites.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/SiteController.php:1533-1613`.
4. Use `GET|HEAD sites/create` (`sites.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/SiteController.php:1239-1251`.
5. Invoke only the owning control for `POST sites` (`sites.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/SiteController.php:1253-1334`; FormRequest `app/Http/Requests/StoreSiteRequest.php:14`; `name`, `type`, `brand_colour`, `phone`, `email`, `emergency_plan_location`, `medication_storage_location`, `notes`, `address_line_1`, `address_line_2`, `suburb`, `city`, `postcode`, `country`, `region`, `latitude`, `longitude`, `access_instructions`, `is_active`, `is_high_risk`, `is_high_needs`, `risk_notes`, `risk_review_date`, `primary_contact_user_id`, `contacts`, `rooms`, `resources`, `zones`, `assets`, `checklists`, `documents`, `total_capacity`, `coverage`, `credentials`, `geofence`, `geofence.mode`, `geofence.radius_m`, `geofence.breach_type`, `geofence.is_active`, `rent_amount`, `rent_frequency`, `lease_start_date`, `lease_end_date`, `landlord_name`, `landlord_contact`, `weekly_food_budget`.
6. Invoke only the owning control for `PUT sites/{site}` (`sites.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/SiteController.php:1615-1669`; FormRequest `app/Http/Requests/UpdateSiteRequest.php:14`; `name`, `type`, `brand_colour`, `phone`, `email`, `emergency_plan_location`, `medication_storage_location`, `notes`, `address_line_1`, `address_line_2`, `suburb`, `city`, `postcode`, `country`, `region`, `latitude`, `longitude`, `access_instructions`, `is_active`, `is_high_risk`, `is_high_needs`, `risk_notes`, `risk_review_date`, `primary_contact_user_id`, `contacts`, `rooms`, `resources`, `zones`, `assets`, `checklists`, `total_capacity`, `coverage`, `credentials`, `geofence`, `geofence.mode`, `geofence.radius_m`, `geofence.breach_type`, `geofence.is_active`, `rent_amount`, `rent_frequency`, `lease_start_date`, `lease_end_date`, `landlord_name`, `landlord_contact`, `weekly_food_budget`.
7. Invoke only the owning control for `PATCH sites/{site}/contact-info` (`sites.contact-info.update`, action `updateContactInfo`). Source category: **updated/revised**; controller `app/Http/Controllers/SiteController.php:912-929`; `phone`.
8. Invoke only the owning control for `PATCH sites/{site}/location` (`sites.location.update`, action `updateLocation`). Source category: **updated/revised**; controller `app/Http/Controllers/SiteController.php:931-956`; `address_line_1`.
9. Invoke only the owning control for `POST sites/{site}/onboarding/step` (`sites.onboarding.step`, action `storeOnboardingStep`). Source category: **created/recorded**; controller `app/Http/Controllers/SiteController.php:1671-1703`; `step`.
10. Invoke only the owning control for `PATCH sites/{site}/safety` (`sites.safety.update`, action `updateSafety`). Source category: **updated/revised**; controller `app/Http/Controllers/SiteController.php:958-975`; `emergency_plan_location`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2728` at `app/Http/Controllers/SiteController.php:62`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2729` at `app/Http/Controllers/SiteController.php:1253`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2730` at `app/Http/Controllers/SiteController.php:272`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2731` at `app/Http/Controllers/SiteController.php:1615`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateContactInfo` / `ROUTE-2756` at `app/Http/Controllers/SiteController.php:912`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2784` at `app/Http/Controllers/SiteController.php:1533`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateLocation` / `ROUTE-2822` at `app/Http/Controllers/SiteController.php:931`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeOnboardingStep` / `ROUTE-2858` at `app/Http/Controllers/SiteController.php:1671`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateSafety` / `ROUTE-2883` at `app/Http/Controllers/SiteController.php:958`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2901` at `app/Http/Controllers/SiteController.php:1239`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/create.tsx`, `resources/js/pages/sites/edit.tsx`, `resources/js/pages/sites/index.tsx`, `resources/js/pages/sites/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2728` / `index`: failure app/Http/Controllers/SiteController.php:88 `abort(403);`.
- `ROUTE-2729` / `store`: FormRequest `app/Http/Requests/StoreSiteRequest.php:14`; fields `name`, `type`, `brand_colour`, `phone`, `email`, `emergency_plan_location`, `medication_storage_location`, `notes`, `address_line_1`, `address_line_2`, `suburb`, `city`, `postcode`, `country`, `region`, `latitude`, `longitude`, `access_instructions`, `is_active`, `is_high_risk`, `is_high_needs`, `risk_notes`, `risk_review_date`, `primary_contact_user_id`, `contacts`, `rooms`, `resources`, `zones`, `assets`, `checklists`, `documents`, `total_capacity`, `coverage`, `credentials`, `geofence`, `geofence.mode`, `geofence.radius_m`, `geofence.breach_type`, `geofence.is_active`, `rent_amount`, `rent_frequency`, `lease_start_date`, `lease_end_date`, `landlord_name`, `landlord_contact`, `weekly_food_budget`; success app/Http/Controllers/SiteController.php:1328 `->with('success', 'Site created.');`; app/Http/Controllers/SiteController.php:1333 `->with('success', 'Site created.');`.
- `ROUTE-2731` / `update`: FormRequest `app/Http/Requests/UpdateSiteRequest.php:14`; fields `name`, `type`, `brand_colour`, `phone`, `email`, `emergency_plan_location`, `medication_storage_location`, `notes`, `address_line_1`, `address_line_2`, `suburb`, `city`, `postcode`, `country`, `region`, `latitude`, `longitude`, `access_instructions`, `is_active`, `is_high_risk`, `is_high_needs`, `risk_notes`, `risk_review_date`, `primary_contact_user_id`, `contacts`, `rooms`, `resources`, `zones`, `assets`, `checklists`, `total_capacity`, `coverage`, `credentials`, `geofence`, `geofence.mode`, `geofence.radius_m`, `geofence.breach_type`, `geofence.is_active`, `rent_amount`, `rent_frequency`, `lease_start_date`, `lease_end_date`, `landlord_name`, `landlord_contact`, `weekly_food_budget`; success app/Http/Controllers/SiteController.php:1668 `->with('success', 'Site updated.');`.
- `ROUTE-2756` / `updateContactInfo`: fields `phone`; success app/Http/Controllers/SiteController.php:928 `return back()->with('success', 'Contact information updated.');`.
- `ROUTE-2822` / `updateLocation`: fields `address_line_1`; success app/Http/Controllers/SiteController.php:955 `return back()->with('success', 'Location updated.');`.
- `ROUTE-2858` / `storeOnboardingStep`: fields `step`.
- `ROUTE-2883` / `updateSafety`: fields `emergency_plan_location`; success app/Http/Controllers/SiteController.php:974 `return back()->with('success', 'Safety information updated.');`.

## Failure and recovery paths

- `index`: app/Http/Controllers/SiteController.php:88 `abort(403);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/SiteController.php:1297 `$site = Site::create($validated);`; app/Http/Controllers/SiteController.php:1652 `$site->update($validated);`; app/Http/Controllers/SiteController.php:921 `$site->update($data);`; app/Http/Controllers/SiteController.php:948 `$site->update($data);`; app/Http/Controllers/SiteController.php:967 `$site->update($data);`; responses app/Http/Controllers/SiteController.php:235 `return inertia('sites/index', [`; app/Http/Controllers/SiteController.php:1314 `return $site;`; app/Http/Controllers/SiteController.php:1323 `// success pane (linking to the new profile), so return back with the new`; app/Http/Controllers/SiteController.php:1326 `return back()`; app/Http/Controllers/SiteController.php:1331 `return redirect()`; app/Http/Controllers/SiteController.php:416 `return inertia('sites/show', [`; app/Http/Controllers/SiteController.php:467 `return [`; app/Http/Controllers/SiteController.php:524 `return [`; app/Http/Controllers/SiteController.php:1666 `return redirect()`; app/Http/Controllers/SiteController.php:928 `return back()->with('success', 'Contact information updated.');`; app/Http/Controllers/SiteController.php:1564 `return inertia('sites/edit', [`; app/Http/Controllers/SiteController.php:955 `return back()->with('success', 'Location updated.');`; app/Http/Controllers/SiteController.php:1702 `return response()->json(['ok' => true]);`; app/Http/Controllers/SiteController.php:974 `return back()->with('success', 'Safety information updated.');`; app/Http/Controllers/SiteController.php:1245 `return inertia('sites/create', [`; audit calls app/Http/Controllers/SiteController.php:923 `AuditLogger::log('site.contact_info.update', $site, [`; app/Http/Controllers/SiteController.php:950 `AuditLogger::log('site.location.update', $site, [`; app/Http/Controllers/SiteController.php:969 `AuditLogger::log('site.safety.update', $site, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites` — `sites.index` — `App\Http\Controllers\SiteController@index` — `app/Http/Controllers/SiteController.php:62` — middleware `web, auth, permission:sites.viewAny`
- `POST sites` — `sites.store` — `App\Http\Controllers\SiteController@store` — `app/Http/Controllers/SiteController.php:1253` — middleware `web, auth, permission:sites.create`
- `GET|HEAD sites/{site}` — `sites.show` — `App\Http\Controllers\SiteController@show` — `app/Http/Controllers/SiteController.php:272` — middleware `web, auth, permission:sites.viewAny`
- `PUT sites/{site}` — `sites.update` — `App\Http\Controllers\SiteController@update` — `app/Http/Controllers/SiteController.php:1615` — middleware `web, auth, permission:sites.update`
- `PATCH sites/{site}/contact-info` — `sites.contact-info.update` — `App\Http\Controllers\SiteController@updateContactInfo` — `app/Http/Controllers/SiteController.php:912` — middleware `web, auth, permission:sites.update`
- `GET|HEAD sites/{site}/edit` — `sites.edit` — `App\Http\Controllers\SiteController@edit` — `app/Http/Controllers/SiteController.php:1533` — middleware `web, auth, permission:sites.update`
- `PATCH sites/{site}/location` — `sites.location.update` — `App\Http\Controllers\SiteController@updateLocation` — `app/Http/Controllers/SiteController.php:931` — middleware `web, auth, permission:sites.update`
- `POST sites/{site}/onboarding/step` — `sites.onboarding.step` — `App\Http\Controllers\SiteController@storeOnboardingStep` — `app/Http/Controllers/SiteController.php:1671` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.update`
- `PATCH sites/{site}/safety` — `sites.safety.update` — `App\Http\Controllers\SiteController@updateSafety` — `app/Http/Controllers/SiteController.php:958` — middleware `web, auth, permission:sites.update`
- `GET|HEAD sites/create` — `sites.create` — `App\Http\Controllers\SiteController@create` — `app/Http/Controllers/SiteController.php:1239` — middleware `web, auth, permission:sites.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/SiteController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/create.tsx`, `resources/js/pages/sites/edit.tsx`, `resources/js/pages/sites/index.tsx`, `resources/js/pages/sites/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
