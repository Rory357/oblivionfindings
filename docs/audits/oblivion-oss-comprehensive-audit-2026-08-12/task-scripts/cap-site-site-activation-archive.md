# CAP-SITE-SITE-ACTIVATION-ARCHIVE: Site activation archive restore and bulk archive

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:sites.update`, `permission:sites.archive`
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

- Actor satisfying exact route middleware `auth`, `permission:sites.update`, `permission:sites.archive`.
- Exact middleware atoms: `web`, `auth`, `permission:sites.update`, `permission:sites.archive`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites` (`sites.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PATCH sites/{site}/active` (`sites.active.update`, action `toggleActive`). Source category: **updated/revised**; controller `app/Http/Controllers/SiteController.php:981-997`; no exact validation fields extracted.
3. Invoke only the owning control for `PATCH sites/{site}/archive` (`sites.archive`, action `archive`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/SiteController.php:999-1015`; no exact validation fields extracted.
4. Invoke only the owning control for `PATCH sites/{site}/unarchive` (`sites.unarchive`, action `unarchive`). Source category: **mutation outcome source gap (unarchive)**; controller `app/Http/Controllers/SiteController.php:1017-1031`; no exact validation fields extracted.
5. Invoke only the owning control for `POST sites/bulk/archive` (`sites.bulk.archive`, action `bulkArchive`). Source category: **mutation outcome source gap (bulkArchive)**; controller `app/Http/Controllers/SiteController.php:1038-1071`; `ids`.

## Source-applicable states and transitions

- **updated/revised** is applicable only to `toggleActive` / `ROUTE-2732` at `app/Http/Controllers/SiteController.php:981`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `archive` / `ROUTE-2733` at `app/Http/Controllers/SiteController.php:999`; it is not runtime-observed.
- **mutation outcome source gap (unarchive)** is applicable only to `unarchive` / `ROUTE-2887` at `app/Http/Controllers/SiteController.php:1017`; it is not runtime-observed.
- **mutation outcome source gap (bulkArchive)** is applicable only to `bulkArchive` / `ROUTE-2897` at `app/Http/Controllers/SiteController.php:1038`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2732` / `toggleActive`: success app/Http/Controllers/SiteController.php:996 `return back()->with('success', $target ? 'Site marked active.' : 'Site marked inactive.');`.
- `ROUTE-2733` / `archive`: success app/Http/Controllers/SiteController.php:1014 `return back()->with('success', 'Site archived.');`.
- `ROUTE-2887` / `unarchive`: success app/Http/Controllers/SiteController.php:1030 `return back()->with('success', 'Site restored.');`.
- `ROUTE-2897` / `bulkArchive`: fields `ids`; success app/Http/Controllers/SiteController.php:1070 `return back()->with('success', $archived === 1 ? '1 site archived.' : "{$archived} sites archived.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/SiteController.php:989 `$site->update(['is_active' => $target]);`; app/Http/Controllers/SiteController.php:1004 `$site->update([`; app/Http/Controllers/SiteController.php:1022 `$site->update([`; app/Http/Controllers/SiteController.php:1056 `$site->update([`; responses app/Http/Controllers/SiteController.php:996 `return back()->with('success', $target ? 'Site marked active.' : 'Site marked inactive.');`; app/Http/Controllers/SiteController.php:1014 `return back()->with('success', 'Site archived.');`; app/Http/Controllers/SiteController.php:1030 `return back()->with('success', 'Site restored.');`; app/Http/Controllers/SiteController.php:1070 `return back()->with('success', $archived === 1 ? '1 site archived.' : "{$archived} sites archived.");`; audit calls app/Http/Controllers/SiteController.php:991 `AuditLogger::log('site.active.update', $site, [`; app/Http/Controllers/SiteController.php:1011 `AuditLogger::log('site.archive', $site, ['site_id' => $site->id]);`; app/Http/Controllers/SiteController.php:1027 `AuditLogger::log('site.unarchive', $site, ['site_id' => $site->id]);`; app/Http/Controllers/SiteController.php:1062 `AuditLogger::log('site.archive', $site, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `PATCH sites/{site}/active` — `sites.active.update` — `App\Http\Controllers\SiteController@toggleActive` — `app/Http/Controllers/SiteController.php:981` — middleware `web, auth, permission:sites.update`
- `PATCH sites/{site}/archive` — `sites.archive` — `App\Http\Controllers\SiteController@archive` — `app/Http/Controllers/SiteController.php:999` — middleware `web, auth, permission:sites.archive`
- `PATCH sites/{site}/unarchive` — `sites.unarchive` — `App\Http\Controllers\SiteController@unarchive` — `app/Http/Controllers/SiteController.php:1017` — middleware `web, auth, permission:sites.archive`
- `POST sites/bulk/archive` — `sites.bulk.archive` — `App\Http\Controllers\SiteController@bulkArchive` — `app/Http/Controllers/SiteController.php:1038` — middleware `web, auth, permission:sites.archive`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/SiteController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
