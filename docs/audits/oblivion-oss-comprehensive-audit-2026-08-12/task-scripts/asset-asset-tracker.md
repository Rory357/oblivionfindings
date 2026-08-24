# ASSET-ASSET-TRACKER: Asset Tracker

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:assets.trackers.manage`
- Owning module: Assets and equipment
- Legacy family: `ASSET-ASSET-TRACKER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:assets.trackers.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:assets.trackers.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST assets/{asset}/trackers/{tracker}/unpair` (`assets.trackers.unpair`, action `unpair`). Source category: **mutation outcome source gap (unpair)**; controller `app/Http/Controllers/AssetTrackerController.php:97-127`; no exact validation fields extracted.
3. Invoke only the owning control for `POST assets/{asset}/trackers/pair` (`assets.trackers.pair`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/AssetTrackerController.php:29-95`; `vendor`.

## Source-applicable states and transitions

- **mutation outcome source gap (unpair)** is applicable only to `unpair` / `ROUTE-0061` at `app/Http/Controllers/AssetTrackerController.php:97`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0062` at `app/Http/Controllers/AssetTrackerController.php:29`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0061` / `unpair`: success app/Http/Controllers/AssetTrackerController.php:126 `return back()->with('success', 'Tracker unpaired.');`; failure app/Http/Controllers/AssetTrackerController.php:103 `abort(404);`.
- `ROUTE-0062` / `store`: fields `vendor`; success app/Http/Controllers/AssetTrackerController.php:94 `return back()->with('success', 'Tracker paired.');`; failure app/Http/Controllers/AssetTrackerController.php:49 `return back()->withErrors(['device_uid' => 'Tracker is already paired to another asset.']);`.

## Failure and recovery paths

- `unpair`: app/Http/Controllers/AssetTrackerController.php:103 `abort(404);`.
- `store`: app/Http/Controllers/AssetTrackerController.php:49 `return back()->withErrors(['device_uid' => 'Tracker is already paired to another asset.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/AssetTrackerController.php:106 `$tracker->update([`; app/Http/Controllers/AssetTrackerController.php:119 `$deviceLink->update(['unlinked_at' => now()]);`; app/Http/Controllers/AssetTrackerController.php:65 `$tracker->save();`; app/Http/Controllers/AssetTrackerController.php:74 `$activeLink->update(['unlinked_at' => now()]);`; app/Http/Controllers/AssetTrackerController.php:79 `DeviceAssetLink::create([`; responses app/Http/Controllers/AssetTrackerController.php:126 `return back()->with('success', 'Tracker unpaired.');`; app/Http/Controllers/AssetTrackerController.php:49 `return back()->withErrors(['device_uid' => 'Tracker is already paired to another asset.']);`; app/Http/Controllers/AssetTrackerController.php:94 `return back()->with('success', 'Tracker paired.');`; audit calls app/Http/Controllers/AssetTrackerController.php:122 `AuditLogger::log('assets.tracker.unpaired', $asset, [`; app/Http/Controllers/AssetTrackerController.php:87 `AuditLogger::log('assets.tracker.paired', $asset, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST assets/{asset}/trackers/{tracker}/unpair` — `assets.trackers.unpair` — `App\Http\Controllers\AssetTrackerController@unpair` — `app/Http/Controllers/AssetTrackerController.php:97` — middleware `web, auth, permission:assets.trackers.manage`
- `POST assets/{asset}/trackers/pair` — `assets.trackers.pair` — `App\Http\Controllers\AssetTrackerController@store` — `app/Http/Controllers/AssetTrackerController.php:29` — middleware `web, auth, permission:assets.trackers.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/AssetTrackerController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
