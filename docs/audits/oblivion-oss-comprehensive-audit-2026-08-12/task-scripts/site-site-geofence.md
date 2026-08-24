# SITE-SITE-GEOFENCE: Site Geofence

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:assets.geofences.manage`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-GEOFENCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:assets.geofences.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:assets.geofences.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST sites/{site}/geofence` (`sites.geofence.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteGeofenceController.php:16-35`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE sites/{site}/geofence/{geofence}` (`sites.geofence.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteGeofenceController.php:54-68`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT sites/{site}/geofence/{geofence}` (`sites.geofence.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteGeofenceController.php:37-52`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2792` at `app/Http/Controllers/Sites/SiteGeofenceController.php:16`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2793` at `app/Http/Controllers/Sites/SiteGeofenceController.php:54`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2794` at `app/Http/Controllers/Sites/SiteGeofenceController.php:37`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2792` / `store`: success app/Http/Controllers/Sites/SiteGeofenceController.php:34 `return back()->with('success', 'Site geofence saved.');`.
- `ROUTE-2793` / `destroy`: success app/Http/Controllers/Sites/SiteGeofenceController.php:67 `return back()->with('success', 'Site geofence deleted.');`.
- `ROUTE-2794` / `update`: success app/Http/Controllers/Sites/SiteGeofenceController.php:51 `return back()->with('success', 'Site geofence saved.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteGeofenceController.php:26 `$geofence->save();`; app/Http/Controllers/Sites/SiteGeofenceController.php:27 `$geofence->assignedAssets()->sync($data['asset_ids'] ?? []);`; app/Http/Controllers/Sites/SiteGeofenceController.php:65 `$geofence->delete();`; app/Http/Controllers/Sites/SiteGeofenceController.php:43 `$geofence->update($this->geofenceAttributes($data, $site));`; app/Http/Controllers/Sites/SiteGeofenceController.php:44 `$geofence->assignedAssets()->sync($data['asset_ids'] ?? []);`; responses app/Http/Controllers/Sites/SiteGeofenceController.php:34 `return back()->with('success', 'Site geofence saved.');`; app/Http/Controllers/Sites/SiteGeofenceController.php:67 `return back()->with('success', 'Site geofence deleted.');`; app/Http/Controllers/Sites/SiteGeofenceController.php:51 `return back()->with('success', 'Site geofence saved.');`; audit calls app/Http/Controllers/Sites/SiteGeofenceController.php:29 `AuditLogger::log('site.geofence.save', $geofence, [`; app/Http/Controllers/Sites/SiteGeofenceController.php:60 `AuditLogger::log('site.geofence.delete', $geofence, [`; app/Http/Controllers/Sites/SiteGeofenceController.php:46 `AuditLogger::log('site.geofence.save', $geofence, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST sites/{site}/geofence` — `sites.geofence.store` — `App\Http\Controllers\Sites\SiteGeofenceController@store` — `app/Http/Controllers/Sites/SiteGeofenceController.php:16` — middleware `web, auth, verified, permission:sites.viewAny, permission:assets.geofences.manage`
- `DELETE sites/{site}/geofence/{geofence}` — `sites.geofence.destroy` — `App\Http\Controllers\Sites\SiteGeofenceController@destroy` — `app/Http/Controllers/Sites/SiteGeofenceController.php:54` — middleware `web, auth, verified, permission:sites.viewAny, permission:assets.geofences.manage`
- `PUT sites/{site}/geofence/{geofence}` — `sites.geofence.update` — `App\Http\Controllers\Sites\SiteGeofenceController@update` — `app/Http/Controllers/Sites/SiteGeofenceController.php:37` — middleware `web, auth, verified, permission:sites.viewAny, permission:assets.geofences.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteGeofenceController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
