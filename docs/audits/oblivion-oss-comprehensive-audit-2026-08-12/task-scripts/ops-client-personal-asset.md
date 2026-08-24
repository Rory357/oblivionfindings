# OPS-CLIENT-PERSONAL-ASSET: Client Personal Asset

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-CLIENT-PERSONAL-ASSET`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST operations/clients/{client}/personal-assets` (`operations.clients.personal-assets.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientPersonalAssetController.php:174-194`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE operations/clients/{client}/personal-assets/{asset}` (`operations.clients.personal-assets.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientPersonalAssetController.php:288-303`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT operations/clients/{client}/personal-assets/{asset}` (`operations.clients.personal-assets.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientPersonalAssetController.php:196-248`; no exact validation fields extracted.
5. Invoke only the owning control for `PATCH operations/clients/{client}/personal-assets/{asset}/status` (`operations.clients.personal-assets.status`, action `updateStatus`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientPersonalAssetController.php:250-286`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2030` at `app/Http/Controllers/ClientPersonalAssetController.php:174`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2031` at `app/Http/Controllers/ClientPersonalAssetController.php:288`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2032` at `app/Http/Controllers/ClientPersonalAssetController.php:196`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateStatus` / `ROUTE-2033` at `app/Http/Controllers/ClientPersonalAssetController.php:250`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2030` / `store`: success app/Http/Controllers/ClientPersonalAssetController.php:193 `return back()->with('success', 'Personal asset added.');`.
- `ROUTE-2031` / `destroy`: success app/Http/Controllers/ClientPersonalAssetController.php:302 `return back()->with('success', 'Personal asset removed.');`.
- `ROUTE-2032` / `update`: success app/Http/Controllers/ClientPersonalAssetController.php:247 `return back()->with('success', 'Personal asset updated.');`; failure app/Http/Controllers/ClientPersonalAssetController.php:238 `throw $exception;`.
- `ROUTE-2033` / `updateStatus`: success app/Http/Controllers/ClientPersonalAssetController.php:285 `return back()->with('success', 'Asset status updated.');`.

## Failure and recovery paths

- `update`: app/Http/Controllers/ClientPersonalAssetController.php:238 `throw $exception;`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientPersonalAssetController.php:189 `$asset = $client->personalAssets()->create($validated);`; app/Http/Controllers/ClientPersonalAssetController.php:295 `Storage::disk('public')->delete($asset->photo_path);`; app/Http/Controllers/ClientPersonalAssetController.php:298 `$asset->delete();`; app/Http/Controllers/ClientPersonalAssetController.php:222 `$asset->update($validated);`; app/Http/Controllers/ClientPersonalAssetController.php:235 `Storage::disk('public')->delete($newPhotoPath);`; app/Http/Controllers/ClientPersonalAssetController.php:242 `Storage::disk('public')->delete($oldPhotoPath);`; app/Http/Controllers/ClientPersonalAssetController.php:271 `$asset->save();`; responses app/Http/Controllers/ClientPersonalAssetController.php:193 `return back()->with('success', 'Personal asset added.');`; app/Http/Controllers/ClientPersonalAssetController.php:302 `return back()->with('success', 'Personal asset removed.');`; app/Http/Controllers/ClientPersonalAssetController.php:247 `return back()->with('success', 'Personal asset updated.');`; app/Http/Controllers/ClientPersonalAssetController.php:285 `return back()->with('success', 'Asset status updated.');`; audit calls app/Http/Controllers/ClientPersonalAssetController.php:191 `AuditLogger::log('clients.personal_asset.create', $client);`; app/Http/Controllers/ClientPersonalAssetController.php:300 `AuditLogger::log('clients.personal_asset.delete', $client);`; app/Http/Controllers/ClientPersonalAssetController.php:245 `AuditLogger::log('clients.personal_asset.update', $client);`; app/Http/Controllers/ClientPersonalAssetController.php:283 `AuditLogger::log('clients.personal_asset.status_change', $client);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/clients/{client}/personal-assets` — `operations.clients.personal-assets.store` — `App\Http\Controllers\ClientPersonalAssetController@store` — `app/Http/Controllers/ClientPersonalAssetController.php:174` — middleware `web, auth, permission:clients.update`
- `DELETE operations/clients/{client}/personal-assets/{asset}` — `operations.clients.personal-assets.destroy` — `App\Http\Controllers\ClientPersonalAssetController@destroy` — `app/Http/Controllers/ClientPersonalAssetController.php:288` — middleware `web, auth, permission:clients.update`
- `PUT operations/clients/{client}/personal-assets/{asset}` — `operations.clients.personal-assets.update` — `App\Http\Controllers\ClientPersonalAssetController@update` — `app/Http/Controllers/ClientPersonalAssetController.php:196` — middleware `web, auth, permission:clients.update`
- `PATCH operations/clients/{client}/personal-assets/{asset}/status` — `operations.clients.personal-assets.status` — `App\Http\Controllers\ClientPersonalAssetController@updateStatus` — `app/Http/Controllers/ClientPersonalAssetController.php:250` — middleware `web, auth, permission:clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientPersonalAssetController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
