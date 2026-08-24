# SITE-SITE-DAMAGE: Site Damage

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-DAMAGE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/damages` (`sites.damages.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/damages` (`sites.damages.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST sites/{site}/damages` (`sites.damages.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteDamageController.php:30-56`; `title`.
3. Invoke only the owning control for `DELETE sites/{site}/damages/{damage}` (`sites.damages.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteDamageController.php:92-100`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT sites/{site}/damages/{damage}` (`sites.damages.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteDamageController.php:58-90`; `title`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2774` at `app/Http/Controllers/Sites/SiteDamageController.php:13`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2775` at `app/Http/Controllers/Sites/SiteDamageController.php:30`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2776` at `app/Http/Controllers/Sites/SiteDamageController.php:92`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2777` at `app/Http/Controllers/Sites/SiteDamageController.php:58`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/damages/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2775` / `store`: fields `title`; success app/Http/Controllers/Sites/SiteDamageController.php:55 `return redirect()->back()->with('success', 'Damage report created.');`.
- `ROUTE-2776` / `destroy`: success app/Http/Controllers/Sites/SiteDamageController.php:99 `return redirect()->back()->with('success', 'Damage report removed.');`.
- `ROUTE-2777` / `update`: fields `title`; success app/Http/Controllers/Sites/SiteDamageController.php:89 `return redirect()->back()->with('success', 'Damage report updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteDamageController.php:53 `SiteDamage::create($data);`; app/Http/Controllers/Sites/SiteDamageController.php:97 `$damage->delete();`; app/Http/Controllers/Sites/SiteDamageController.php:87 `$damage->update($data);`; responses app/Http/Controllers/Sites/SiteDamageController.php:22 `return Inertia::render('sites/damages/index', [`; app/Http/Controllers/Sites/SiteDamageController.php:55 `return redirect()->back()->with('success', 'Damage report created.');`; app/Http/Controllers/Sites/SiteDamageController.php:99 `return redirect()->back()->with('success', 'Damage report removed.');`; app/Http/Controllers/Sites/SiteDamageController.php:89 `return redirect()->back()->with('success', 'Damage report updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/damages` — `sites.damages.index` — `App\Http\Controllers\Sites\SiteDamageController@index` — `app/Http/Controllers/Sites/SiteDamageController.php:13` — middleware `web, auth, verified, permission:sites.viewAny`
- `POST sites/{site}/damages` — `sites.damages.store` — `App\Http\Controllers\Sites\SiteDamageController@store` — `app/Http/Controllers/Sites/SiteDamageController.php:30` — middleware `web, auth, verified, permission:sites.viewAny`
- `DELETE sites/{site}/damages/{damage}` — `sites.damages.destroy` — `App\Http\Controllers\Sites\SiteDamageController@destroy` — `app/Http/Controllers/Sites/SiteDamageController.php:92` — middleware `web, auth, verified, permission:sites.viewAny`
- `PUT sites/{site}/damages/{damage}` — `sites.damages.update` — `App\Http\Controllers\Sites\SiteDamageController@update` — `app/Http/Controllers/Sites/SiteDamageController.php:58` — middleware `web, auth, verified, permission:sites.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteDamageController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/damages/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
