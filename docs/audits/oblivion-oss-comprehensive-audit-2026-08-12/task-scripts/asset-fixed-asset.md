# ASSET-FIXED-ASSET: Fixed Asset

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.assets.view`, `permission:finance.assets.manage`
- Owning module: Assets and equipment
- Legacy family: `ASSET-FIXED-ASSET`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/fixed-assets` (`finance.fixed-assets.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.assets.view`, `permission:finance.assets.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.assets.view`, `permission:finance.assets.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/fixed-assets` (`finance.fixed-assets.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/fixed-assets/{fixedAsset}` (`finance.fixed-assets.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/FixedAssetController.php:143-208`.
3. Invoke only the owning control for `POST finance/fixed-assets` (`finance.fixed-assets.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/FixedAssetController.php:104-136`; `asset_name`.
4. Invoke only the owning control for `PUT finance/fixed-assets/{fixedAsset}` (`finance.fixed-assets.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/FixedAssetController.php:213-243`; `asset_name`.
5. Invoke only the owning control for `POST finance/fixed-assets/{fixedAsset}/dispose` (`finance.fixed-assets.dispose`, action `dispose`). Source category: **cancelled/removed/archived**; controller `app/Domain/Finance/Http/Controllers/FixedAssetController.php:248-266`; `disposed_date`.
6. Invoke only the owning control for `POST finance/fixed-assets/run-depreciation` (`finance.fixed-assets.run-depreciation`, action `runDepreciation`). Source category: **mutation outcome source gap (runDepreciation)**; controller `app/Domain/Finance/Http/Controllers/FixedAssetController.php:271-292`; `depreciation_date`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0565` at `app/Domain/Finance/Http/Controllers/FixedAssetController.php:21`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0566` at `app/Domain/Finance/Http/Controllers/FixedAssetController.php:104`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0567` at `app/Domain/Finance/Http/Controllers/FixedAssetController.php:143`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0568` at `app/Domain/Finance/Http/Controllers/FixedAssetController.php:213`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `dispose` / `ROUTE-0569` at `app/Domain/Finance/Http/Controllers/FixedAssetController.php:248`; it is not runtime-observed.
- **mutation outcome source gap (runDepreciation)** is applicable only to `runDepreciation` / `ROUTE-0572` at `app/Domain/Finance/Http/Controllers/FixedAssetController.php:271`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/fixed-assets/Index.tsx`, `resources/js/pages/finance/fixed-assets/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0566` / `store`: fields `asset_name`; success app/Domain/Finance/Http/Controllers/FixedAssetController.php:135 `->with('success', "Fixed asset \"{$asset->asset_name}\" has been created.");`; failure app/Domain/Finance/Http/Controllers/FixedAssetController.php:131 `->withErrors(['general' => $e->getMessage()]);`.
- `ROUTE-0568` / `update`: fields `asset_name`; success app/Domain/Finance/Http/Controllers/FixedAssetController.php:242 `->with('success', "Fixed asset \"{$asset->asset_name}\" has been updated.");`; failure app/Domain/Finance/Http/Controllers/FixedAssetController.php:238 `->withErrors(['general' => $e->getMessage()]);`.
- `ROUTE-0569` / `dispose`: fields `disposed_date`; success app/Domain/Finance/Http/Controllers/FixedAssetController.php:265 `->with('success', "Fixed asset \"{$asset->asset_name}\" has been disposed.");`; failure app/Domain/Finance/Http/Controllers/FixedAssetController.php:261 `->withErrors(['general' => $e->getMessage()]);`.
- `ROUTE-0572` / `runDepreciation`: fields `depreciation_date`; success app/Domain/Finance/Http/Controllers/FixedAssetController.php:291 `->with('success', "Depreciation run complete. {$count} asset(s) processed.");`; failure app/Domain/Finance/Http/Controllers/FixedAssetController.php:285 `->withErrors(['general' => $e->getMessage()]);`.

## Failure and recovery paths

- `store`: app/Domain/Finance/Http/Controllers/FixedAssetController.php:131 `->withErrors(['general' => $e->getMessage()]);`.
- `update`: app/Domain/Finance/Http/Controllers/FixedAssetController.php:238 `->withErrors(['general' => $e->getMessage()]);`.
- `dispose`: app/Domain/Finance/Http/Controllers/FixedAssetController.php:261 `->withErrors(['general' => $e->getMessage()]);`.
- `runDepreciation`: app/Domain/Finance/Http/Controllers/FixedAssetController.php:285 `->withErrors(['general' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Finance/Http/Controllers/FixedAssetController.php:81 `return Inertia::render('finance/fixed-assets/Index', [`; app/Domain/Finance/Http/Controllers/FixedAssetController.php:129 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/FixedAssetController.php:134 `return redirect()->route('finance.fixed-assets.show', $asset)`; app/Domain/Finance/Http/Controllers/FixedAssetController.php:191 `return Inertia::render('finance/fixed-assets/Show', [`; app/Domain/Finance/Http/Controllers/FixedAssetController.php:236 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/FixedAssetController.php:241 `return redirect()->route('finance.fixed-assets.show', $asset)`; app/Domain/Finance/Http/Controllers/FixedAssetController.php:260 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/FixedAssetController.php:264 `return redirect()->route('finance.fixed-assets.show', $asset)`; app/Domain/Finance/Http/Controllers/FixedAssetController.php:284 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/FixedAssetController.php:290 `return redirect()->route('finance.fixed-assets.index')`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/fixed-assets` — `finance.fixed-assets.index` — `App\Domain\Finance\Http\Controllers\FixedAssetController@index` — `app/Domain/Finance/Http/Controllers/FixedAssetController.php:21` — middleware `web, auth, permission:finance.assets.view`
- `POST finance/fixed-assets` — `finance.fixed-assets.store` — `App\Domain\Finance\Http\Controllers\FixedAssetController@store` — `app/Domain/Finance/Http/Controllers/FixedAssetController.php:104` — middleware `web, auth, permission:finance.assets.manage`
- `GET|HEAD finance/fixed-assets/{fixedAsset}` — `finance.fixed-assets.show` — `App\Domain\Finance\Http\Controllers\FixedAssetController@show` — `app/Domain/Finance/Http/Controllers/FixedAssetController.php:143` — middleware `web, auth, permission:finance.assets.view`
- `PUT finance/fixed-assets/{fixedAsset}` — `finance.fixed-assets.update` — `App\Domain\Finance\Http\Controllers\FixedAssetController@update` — `app/Domain/Finance/Http/Controllers/FixedAssetController.php:213` — middleware `web, auth, permission:finance.assets.manage`
- `POST finance/fixed-assets/{fixedAsset}/dispose` — `finance.fixed-assets.dispose` — `App\Domain\Finance\Http\Controllers\FixedAssetController@dispose` — `app/Domain/Finance/Http/Controllers/FixedAssetController.php:248` — middleware `web, auth, permission:finance.assets.manage`
- `POST finance/fixed-assets/run-depreciation` — `finance.fixed-assets.run-depreciation` — `App\Domain\Finance\Http\Controllers\FixedAssetController@runDepreciation` — `app/Domain/Finance/Http/Controllers/FixedAssetController.php:271` — middleware `web, auth, permission:finance.assets.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/FixedAssetController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/fixed-assets/Index.tsx`, `resources/js/pages/finance/fixed-assets/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
