# FIN-ACCOUNTING-INTEGRATION: Accounting Integration

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.admin`
- Owning module: Finance and funding
- Legacy family: `FIN-ACCOUNTING-INTEGRATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/integrations` (`finance.integrations.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.admin`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.admin`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/integrations` (`finance.integrations.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/integrations/{integration}/mapping` (`finance.integrations.mapping`, action `mapping`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:178-209`.
3. Invoke only the owning control for `POST finance/integrations` (`finance.integrations.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:72-101`; `provider`.
4. Invoke only the owning control for `DELETE finance/integrations/{integration}` (`finance.integrations.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:164-173`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT finance/integrations/{integration}` (`finance.integrations.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:106-121`; `tenant_id`, `sync_direction`.
6. Invoke only the owning control for `PUT finance/integrations/{integration}/mapping` (`finance.integrations.mapping.update`, action `updateMapping`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:214-232`; `account_mapping`, `tax_mapping`.
7. Invoke only the owning control for `POST finance/integrations/{integration}/sync` (`finance.integrations.sync`, action `sync`). Source category: **retried/replayed/reconciled**; controller `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:126-138`; no exact validation fields extracted.
8. Invoke only the owning control for `POST finance/integrations/{integration}/test` (`finance.integrations.test`, action `testConnection`). Source category: **mutation outcome source gap (testConnection)**; controller `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:143-159`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0586` at `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:23`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0587` at `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:72`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0588` at `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:164`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0589` at `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:106`; it is not runtime-observed.
- **information presented** is applicable only to `mapping` / `ROUTE-0590` at `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:178`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateMapping` / `ROUTE-0591` at `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:214`; it is not runtime-observed.
- **retried/replayed/reconciled** is applicable only to `sync` / `ROUTE-0592` at `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:126`; it is not runtime-observed.
- **mutation outcome source gap (testConnection)** is applicable only to `testConnection` / `ROUTE-0593` at `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:143`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/Integrations/Index.tsx`, `resources/js/pages/finance/Integrations/Mapping.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0587` / `store`: fields `provider`; success app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:100 `->with('success', ucfirst($integration->provider) . ' integration created successfully.');`.
- `ROUTE-0588` / `destroy`: success app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:172 `->with('success', ucfirst($provider) . ' integration disconnected successfully.');`.
- `ROUTE-0589` / `update`: fields `tenant_id`, `sync_direction`; success app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:120 `->with('success', ucfirst($integration->provider) . ' integration updated successfully.');`.
- `ROUTE-0591` / `updateMapping`: fields `account_mapping`, `tax_mapping`; success app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:231 `->with('success', 'Account mapping updated successfully.');`.
- `ROUTE-0592` / `sync`: success app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:137 `->with('success', ucfirst($integration->provider) . ' sync has been queued.');`; failure app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:131 `return back()->withErrors(['integration' => 'Cannot sync an inactive integration.']);`.
- `ROUTE-0593` / `testConnection`: success app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:152 `return back()->with('success', ucfirst($integration->provider) . ' connection test successful.');`; failure app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:155 `return back()->withErrors(['connection' => ucfirst($integration->provider) . ' connection test failed. Please check your credentials.']);`; app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:157 `return back()->withErrors(['connection' => 'Connection test failed: ' . $e->getMessage()]);`.

## Failure and recovery paths

- `sync`: app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:131 `return back()->withErrors(['integration' => 'Cannot sync an inactive integration.']);`.
- `testConnection`: app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:155 `return back()->withErrors(['connection' => ucfirst($integration->provider) . ' connection test failed. Please check your credentials.']);`; app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:157 `return back()->withErrors(['connection' => 'Connection test failed: ' . $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:89 `$integration = FinAccountingIntegration::create([`; app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:169 `$integration->delete();`; app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:117 `$integration->update($validated);`; app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:225 `$integration->update([`; responses app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:64 `return Inertia::render('finance/Integrations/Index', [`; app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:99 `return redirect()->route('finance.integrations.index')`; app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:171 `return redirect()->route('finance.integrations.index')`; app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:119 `return redirect()->route('finance.integrations.index')`; app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:199 `return Inertia::render('finance/Integrations/Mapping', [`; app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:230 `return redirect()->route('finance.integrations.mapping', $integration)`; app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:131 `return back()->withErrors(['integration' => 'Cannot sync an inactive integration.']);`; app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:136 `return redirect()->route('finance.integrations.index')`; app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:152 `return back()->with('success', ucfirst($integration->provider) . ' connection test successful.');`; app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:155 `return back()->withErrors(['connection' => ucfirst($integration->provider) . ' connection test failed. Please check your credentials.']);`; app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:157 `return back()->withErrors(['connection' => 'Connection test failed: ' . $e->getMessage()]);`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:134 `SyncAccountingIntegrationJob::dispatch($integration->id);`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD finance/integrations` — `finance.integrations.index` — `App\Domain\Finance\Http\Controllers\AccountingIntegrationController@index` — `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:23` — middleware `web, auth, permission:finance.admin`
- `POST finance/integrations` — `finance.integrations.store` — `App\Domain\Finance\Http\Controllers\AccountingIntegrationController@store` — `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:72` — middleware `web, auth, permission:finance.admin`
- `DELETE finance/integrations/{integration}` — `finance.integrations.destroy` — `App\Domain\Finance\Http\Controllers\AccountingIntegrationController@destroy` — `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:164` — middleware `web, auth, permission:finance.admin`
- `PUT finance/integrations/{integration}` — `finance.integrations.update` — `App\Domain\Finance\Http\Controllers\AccountingIntegrationController@update` — `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:106` — middleware `web, auth, permission:finance.admin`
- `GET|HEAD finance/integrations/{integration}/mapping` — `finance.integrations.mapping` — `App\Domain\Finance\Http\Controllers\AccountingIntegrationController@mapping` — `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:178` — middleware `web, auth, permission:finance.admin`
- `PUT finance/integrations/{integration}/mapping` — `finance.integrations.mapping.update` — `App\Domain\Finance\Http\Controllers\AccountingIntegrationController@updateMapping` — `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:214` — middleware `web, auth, permission:finance.admin`
- `POST finance/integrations/{integration}/sync` — `finance.integrations.sync` — `App\Domain\Finance\Http\Controllers\AccountingIntegrationController@sync` — `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:126` — middleware `web, auth, permission:finance.admin`
- `POST finance/integrations/{integration}/test` — `finance.integrations.test` — `App\Domain\Finance\Http\Controllers\AccountingIntegrationController@testConnection` — `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php:143` — middleware `web, auth, permission:finance.admin`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/AccountingIntegrationController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/Integrations/Index.tsx`, `resources/js/pages/finance/Integrations/Mapping.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
