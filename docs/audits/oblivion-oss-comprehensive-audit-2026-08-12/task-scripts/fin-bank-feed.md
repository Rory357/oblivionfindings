# FIN-BANK-FEED: Bank Feed

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.bank.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-BANK-FEED`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/bank-feeds` (`finance.bank-feeds.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.bank.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.bank.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/bank-feeds` (`finance.bank-feeds.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/bank-feeds/{feed}/logs` (`finance.bank-feeds.logs`, action `logs`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/BankFeedController.php:169-202`.
3. Invoke only the owning control for `POST finance/bank-feeds` (`finance.bank-feeds.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/BankFeedController.php:66-102`; `bank_account_id`.
4. Invoke only the owning control for `DELETE finance/bank-feeds/{feed}` (`finance.bank-feeds.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Domain/Finance/Http/Controllers/BankFeedController.php:146-167`; no exact validation fields extracted.
5. Invoke only the owning control for `POST finance/bank-feeds/{feed}/sync` (`finance.bank-feeds.sync`, action `sync`). Source category: **retried/replayed/reconciled**; controller `app/Domain/Finance/Http/Controllers/BankFeedController.php:104-125`; no exact validation fields extracted.
6. Invoke only the owning control for `POST finance/bank-feeds/sync-all` (`finance.bank-feeds.sync-all`, action `syncAll`). Source category: **retried/replayed/reconciled**; controller `app/Domain/Finance/Http/Controllers/BankFeedController.php:127-144`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0481` at `app/Domain/Finance/Http/Controllers/BankFeedController.php:19`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0482` at `app/Domain/Finance/Http/Controllers/BankFeedController.php:66`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0483` at `app/Domain/Finance/Http/Controllers/BankFeedController.php:146`; it is not runtime-observed.
- **information presented** is applicable only to `logs` / `ROUTE-0484` at `app/Domain/Finance/Http/Controllers/BankFeedController.php:169`; it is not runtime-observed.
- **retried/replayed/reconciled** is applicable only to `sync` / `ROUTE-0485` at `app/Domain/Finance/Http/Controllers/BankFeedController.php:104`; it is not runtime-observed.
- **retried/replayed/reconciled** is applicable only to `syncAll` / `ROUTE-0486` at `app/Domain/Finance/Http/Controllers/BankFeedController.php:127`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/bank-feeds/Index.tsx`, `resources/js/pages/finance/bank-feeds/Logs.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0482` / `store`: fields `bank_account_id`; success app/Domain/Finance/Http/Controllers/BankFeedController.php:101 `->with('success', 'Bank feed connection created.');`; failure app/Domain/Finance/Http/Controllers/BankFeedController.php:70 `->withErrors(['provider' => config('finance.bank_feeds.provider_setup_message')]);`; app/Domain/Finance/Http/Controllers/BankFeedController.php:87 `->withErrors(['bank_account_id' => 'A bank feed already exists for this account.']);`.
- `ROUTE-0483` / `destroy`: success app/Domain/Finance/Http/Controllers/BankFeedController.php:166 `->with('success', 'Bank feed disconnected.');`; failure app/Domain/Finance/Http/Controllers/BankFeedController.php:151 `abort(403);`.
- `ROUTE-0484` / `logs`: failure app/Domain/Finance/Http/Controllers/BankFeedController.php:174 `abort(403);`.
- `ROUTE-0485` / `sync`: failure app/Domain/Finance/Http/Controllers/BankFeedController.php:114 `abort(403);`.
- `ROUTE-0486` / `syncAll`: success app/Domain/Finance/Http/Controllers/BankFeedController.php:143 `->with('success', "Synced {$total} feed(s): {$successful} successful, {$failed} failed.");`.

## Failure and recovery paths

- `store`: app/Domain/Finance/Http/Controllers/BankFeedController.php:70 `->withErrors(['provider' => config('finance.bank_feeds.provider_setup_message')]);`; app/Domain/Finance/Http/Controllers/BankFeedController.php:87 `->withErrors(['bank_account_id' => 'A bank feed already exists for this account.']);`.
- `destroy`: app/Domain/Finance/Http/Controllers/BankFeedController.php:151 `abort(403);`.
- `logs`: app/Domain/Finance/Http/Controllers/BankFeedController.php:174 `abort(403);`.
- `sync`: app/Domain/Finance/Http/Controllers/BankFeedController.php:114 `abort(403);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/BankFeedController.php:90 `FinBankFeed::create([`; app/Domain/Finance/Http/Controllers/BankFeedController.php:162 `$feed->update(['is_active' => false]);`; app/Domain/Finance/Http/Controllers/BankFeedController.php:163 `$feed->delete();`; responses app/Domain/Finance/Http/Controllers/BankFeedController.php:55 `return Inertia::render('finance/bank-feeds/Index', [`; app/Domain/Finance/Http/Controllers/BankFeedController.php:69 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/BankFeedController.php:86 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/BankFeedController.php:100 `return redirect()->route('finance.bank-feeds.index')`; app/Domain/Finance/Http/Controllers/BankFeedController.php:165 `return redirect()->route('finance.bank-feeds.index')`; app/Domain/Finance/Http/Controllers/BankFeedController.php:193 `return Inertia::render('finance/bank-feeds/Logs', [`; app/Domain/Finance/Http/Controllers/BankFeedController.php:107 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/BankFeedController.php:123 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/BankFeedController.php:130 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/BankFeedController.php:142 `return redirect()->back()`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/bank-feeds` — `finance.bank-feeds.index` — `App\Domain\Finance\Http\Controllers\BankFeedController@index` — `app/Domain/Finance/Http/Controllers/BankFeedController.php:19` — middleware `web, auth, permission:finance.bank.manage`
- `POST finance/bank-feeds` — `finance.bank-feeds.store` — `App\Domain\Finance\Http\Controllers\BankFeedController@store` — `app/Domain/Finance/Http/Controllers/BankFeedController.php:66` — middleware `web, auth, permission:finance.bank.manage`
- `DELETE finance/bank-feeds/{feed}` — `finance.bank-feeds.destroy` — `App\Domain\Finance\Http\Controllers\BankFeedController@destroy` — `app/Domain/Finance/Http/Controllers/BankFeedController.php:146` — middleware `web, auth, permission:finance.bank.manage`
- `GET|HEAD finance/bank-feeds/{feed}/logs` — `finance.bank-feeds.logs` — `App\Domain\Finance\Http\Controllers\BankFeedController@logs` — `app/Domain/Finance/Http/Controllers/BankFeedController.php:169` — middleware `web, auth, permission:finance.bank.manage`
- `POST finance/bank-feeds/{feed}/sync` — `finance.bank-feeds.sync` — `App\Domain\Finance\Http\Controllers\BankFeedController@sync` — `app/Domain/Finance/Http/Controllers/BankFeedController.php:104` — middleware `web, auth, permission:finance.bank.manage`
- `POST finance/bank-feeds/sync-all` — `finance.bank-feeds.sync-all` — `App\Domain\Finance\Http\Controllers\BankFeedController@syncAll` — `app/Domain/Finance/Http/Controllers/BankFeedController.php:127` — middleware `web, auth, permission:finance.bank.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/BankFeedController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/bank-feeds/Index.tsx`, `resources/js/pages/finance/bank-feeds/Logs.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
