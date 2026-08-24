# CAP-OPS-CLIENT-PHOTOS-GALLERY: Client photos and gallery

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-CLIENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `clients` (`clients.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD clients` (`clients.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `DELETE clients/{client}/photo` (`clients.photo.destroy`, action `destroyPhoto`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientController.php:3011-3022`; no exact validation fields extracted.
3. Invoke only the owning control for `POST clients/{client}/photo` (`clients.photo.update`, action `updatePhoto`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientController.php:2989-3006`; `photo`.
4. Invoke only the owning control for `POST operations/clients/{client}/gallery-photos` (`operations.clients.gallery-photos.store`, action `storeGalleryPhoto`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientController.php:3027-3059`; `photo`.
5. Invoke only the owning control for `DELETE operations/clients/{client}/gallery-photos/{photo}` (`operations.clients.gallery-photos.destroy`, action `destroyGalleryPhoto`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientController.php:3064-3075`; no exact validation fields extracted.
6. Invoke only the owning control for `DELETE operations/clients/{client}/photo` (`operations.clients.photo.destroy`, action `destroyPhoto`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientController.php:3011-3022`; no exact validation fields extracted.
7. Invoke only the owning control for `POST operations/clients/{client}/photo` (`operations.clients.photo.update`, action `updatePhoto`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientController.php:2989-3006`; `photo`.

## Source-applicable states and transitions

- **cancelled/removed/archived** is applicable only to `destroyPhoto` / `ROUTE-0184` at `app/Http/Controllers/ClientController.php:3011`; it is not runtime-observed.
- **updated/revised** is applicable only to `updatePhoto` / `ROUTE-0185` at `app/Http/Controllers/ClientController.php:2989`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeGalleryPhoto` / `ROUTE-1975` at `app/Http/Controllers/ClientController.php:3027`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyGalleryPhoto` / `ROUTE-1976` at `app/Http/Controllers/ClientController.php:3064`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyPhoto` / `ROUTE-2034` at `app/Http/Controllers/ClientController.php:3011`; it is not runtime-observed.
- **updated/revised** is applicable only to `updatePhoto` / `ROUTE-2035` at `app/Http/Controllers/ClientController.php:2989`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0184` / `destroyPhoto`: success app/Http/Controllers/ClientController.php:3021 `return back()->with('success', 'Client photo removed.');`.
- `ROUTE-0185` / `updatePhoto`: fields `photo`; success app/Http/Controllers/ClientController.php:3005 `return back()->with('success', 'Client photo updated.');`.
- `ROUTE-1975` / `storeGalleryPhoto`: fields `photo`; success app/Http/Controllers/ClientController.php:3058 `return back()->with('success', 'Photo uploaded.');`.
- `ROUTE-1976` / `destroyGalleryPhoto`: success app/Http/Controllers/ClientController.php:3074 `return back()->with('success', 'Photo deleted.');`.
- `ROUTE-2034` / `destroyPhoto`: success app/Http/Controllers/ClientController.php:3021 `return back()->with('success', 'Client photo removed.');`.
- `ROUTE-2035` / `updatePhoto`: fields `photo`; success app/Http/Controllers/ClientController.php:3005 `return back()->with('success', 'Client photo updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientController.php:3016 `Storage::disk('public')->delete($client->profile_photo_path);`; app/Http/Controllers/ClientController.php:3019 `$client->forceFill(['profile_photo_path' => null])->save();`; app/Http/Controllers/ClientController.php:3000 `Storage::disk('public')->delete($client->profile_photo_path);`; app/Http/Controllers/ClientController.php:3003 `$client->forceFill(['profile_photo_path' => $path])->save();`; app/Http/Controllers/ClientController.php:3041 `ClientPhoto::create([`; app/Http/Controllers/ClientController.php:3069 `app(ClientPhotoStorage::class)->delete($photo);`; app/Http/Controllers/ClientController.php:3070 `$photo->delete();`; responses app/Http/Controllers/ClientController.php:3021 `return back()->with('success', 'Client photo removed.');`; app/Http/Controllers/ClientController.php:3005 `return back()->with('success', 'Client photo updated.');`; app/Http/Controllers/ClientController.php:3058 `return back()->with('success', 'Photo uploaded.');`; app/Http/Controllers/ClientController.php:3074 `return back()->with('success', 'Photo deleted.');`; audit calls app/Http/Controllers/ClientController.php:3056 `AuditLogger::log('client.photo.upload', $client);`; app/Http/Controllers/ClientController.php:3072 `AuditLogger::log('client.photo.delete', $client);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `DELETE clients/{client}/photo` — `clients.photo.destroy` — `App\Http\Controllers\ClientController@destroyPhoto` — `app/Http/Controllers/ClientController.php:3011` — middleware `web, auth, permission:clients.update`
- `POST clients/{client}/photo` — `clients.photo.update` — `App\Http\Controllers\ClientController@updatePhoto` — `app/Http/Controllers/ClientController.php:2989` — middleware `web, auth, permission:clients.update`
- `POST operations/clients/{client}/gallery-photos` — `operations.clients.gallery-photos.store` — `App\Http\Controllers\ClientController@storeGalleryPhoto` — `app/Http/Controllers/ClientController.php:3027` — middleware `web, auth, permission:clients.update`
- `DELETE operations/clients/{client}/gallery-photos/{photo}` — `operations.clients.gallery-photos.destroy` — `App\Http\Controllers\ClientController@destroyGalleryPhoto` — `app/Http/Controllers/ClientController.php:3064` — middleware `web, auth, permission:clients.update`
- `DELETE operations/clients/{client}/photo` — `operations.clients.photo.destroy` — `App\Http\Controllers\ClientController@destroyPhoto` — `app/Http/Controllers/ClientController.php:3011` — middleware `web, auth, permission:clients.update`
- `POST operations/clients/{client}/photo` — `operations.clients.photo.update` — `App\Http\Controllers\ClientController@updatePhoto` — `app/Http/Controllers/ClientController.php:2989` — middleware `web, auth, permission:clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
