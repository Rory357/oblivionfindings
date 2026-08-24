# CR-CONTROL-ROOM-EVIDENCE: Control Room Evidence

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.manage`
- Owning module: Control Room
- Legacy family: `CR-CONTROL-ROOM-EVIDENCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `control-room/alerts/{alert}/evidence` (`control-room.evidence.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:controlRoom.alerts.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD control-room/alerts/{alert}/evidence` (`control-room.evidence.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD control-room/evidence/{pack}/export` (`control-room.evidence.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:192-263`.
3. Use `GET|HEAD control-room/evidence/items/{item}/download` (`control-room.evidence.download-item`, action `downloadItem`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:148-166`.
4. Invoke only the owning control for `POST control-room/alerts/{alert}/evidence` (`control-room.evidence.store-pack`, action `storePack`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:56-79`; `title`.
5. Invoke only the owning control for `POST control-room/evidence/{pack}/complete` (`control-room.evidence.complete-pack`, action `completePack`). Source category: **completed/closed/released**; controller `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:171-187`; no exact validation fields extracted.
6. Invoke only the owning control for `POST control-room/evidence/{pack}/items` (`control-room.evidence.store-item`, action `storeItem`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:86-107`; no exact validation fields extracted.
7. Invoke only the owning control for `DELETE control-room/evidence/items/{item}` (`control-room.evidence.destroy-item`, action `destroyItem`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:112-140`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0226` at `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:20`; it is not runtime-observed.
- **created/recorded** is applicable only to `storePack` / `ROUTE-0227` at `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:56`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `completePack` / `ROUTE-0260` at `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:171`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-0261` at `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:192`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeItem` / `ROUTE-0262` at `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:86`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyItem` / `ROUTE-0263` at `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:112`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadItem` / `ROUTE-0264` at `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:148`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0227` / `storePack`: fields `title`; success app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:78 `return back()->with('success', 'Evidence pack created.');`.
- `ROUTE-0260` / `completePack`: success app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:186 `return back()->with('success', 'Evidence pack marked as complete.');`; failure app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:177 `return back()->withErrors(['pack' => 'Only packs with status "collecting" can be completed.']);`.
- `ROUTE-0261` / `export`: failure app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:198 `return back()->withErrors(['pack' => 'Only completed packs can be exported.']);`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:212 `return back()->withErrors(['export' => 'Failed to create ZIP archive.']);`.
- `ROUTE-0262` / `storeItem`: failure app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:92 `return back()->withErrors(['pack' => 'Cannot add items to a completed or exported pack.']);`.
- `ROUTE-0263` / `destroyItem`: success app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:139 `return back()->with('success', 'Evidence item removed.');`; failure app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:120 `return back()->withErrors(['pack' => 'Cannot remove items from a completed or exported pack.']);`.

## Failure and recovery paths

- `completePack`: app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:177 `return back()->withErrors(['pack' => 'Only packs with status "collecting" can be completed.']);`.
- `export`: app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:198 `return back()->withErrors(['pack' => 'Only completed packs can be exported.']);`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:212 `return back()->withErrors(['export' => 'Failed to create ZIP archive.']);`.
- `storeItem`: app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:92 `return back()->withErrors(['pack' => 'Cannot add items to a completed or exported pack.']);`.
- `destroyItem`: app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:120 `return back()->withErrors(['pack' => 'Cannot remove items from a completed or exported pack.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:65 `$pack = EvidencePack::create([`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:180 `$pack->update(['status' => 'complete']);`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:125 `Storage::disk('local')->delete($item->storage_path);`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:128 `$item->delete();`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:130 `$pack->update([`; responses app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:50 `return response()->json(['packs' => $packs]);`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:78 `return back()->with('success', 'Evidence pack created.');`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:177 `return back()->withErrors(['pack' => 'Only packs with status "collecting" can be completed.']);`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:186 `return back()->with('success', 'Evidence pack marked as complete.');`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:198 `return back()->withErrors(['pack' => 'Only completed packs can be exported.']);`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:212 `return back()->withErrors(['export' => 'Failed to create ZIP archive.']);`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:260 `return response()->download($zipPath, $zipFilename, [`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:92 `return back()->withErrors(['pack' => 'Cannot add items to a completed or exported pack.']);`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:98 `return $this->storeNoteItem($request, $pack, $user);`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:102 `return $this->storeCctvBookmarkItem($request, $pack, $user);`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:106 `return $this->storeFileItem($request, $pack, $user);`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:120 `return back()->withErrors(['pack' => 'Cannot remove items from a completed or exported pack.']);`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:139 `return back()->with('success', 'Evidence item removed.');`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:162 `return Storage::disk('local')->download($item->storage_path, $filename, [`; audit calls app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:73 `AuditLogger::log('controlRoom.evidence.packCreated', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:182 `AuditLogger::log('controlRoom.evidence.packCompleted', $pack->alert, [`; app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:134 `AuditLogger::log('controlRoom.evidence.itemDeleted', $pack->alert, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD control-room/alerts/{alert}/evidence` — `control-room.evidence.index` — `App\Http\Controllers\ControlRoom\ControlRoomEvidenceController@index` — `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:20` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/alerts/{alert}/evidence` — `control-room.evidence.store-pack` — `App\Http\Controllers\ControlRoom\ControlRoomEvidenceController@storePack` — `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:56` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/evidence/{pack}/complete` — `control-room.evidence.complete-pack` — `App\Http\Controllers\ControlRoom\ControlRoomEvidenceController@completePack` — `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:171` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `GET|HEAD control-room/evidence/{pack}/export` — `control-room.evidence.export` — `App\Http\Controllers\ControlRoom\ControlRoomEvidenceController@export` — `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:192` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/evidence/{pack}/items` — `control-room.evidence.store-item` — `App\Http\Controllers\ControlRoom\ControlRoomEvidenceController@storeItem` — `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:86` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `DELETE control-room/evidence/items/{item}` — `control-room.evidence.destroy-item` — `App\Http\Controllers\ControlRoom\ControlRoomEvidenceController@destroyItem` — `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:112` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `GET|HEAD control-room/evidence/items/{item}/download` — `control-room.evidence.download-item` — `App\Http\Controllers\ControlRoom\ControlRoomEvidenceController@downloadItem` — `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:148` — middleware `web, auth, permission:controlRoom.alerts.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
