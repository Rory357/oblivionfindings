# FIN-MATCH-RULE: Match Rule

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.bank.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-MATCH-RULE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/match-rules` (`finance.match-rules.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.bank.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.bank.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/match-rules` (`finance.match-rules.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST finance/match-rules` (`finance.match-rules.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/MatchRuleController.php:44-70`; `name`.
3. Invoke only the owning control for `DELETE finance/match-rules/{rule}` (`finance.match-rules.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Domain/Finance/Http/Controllers/MatchRuleController.php:102-108`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT finance/match-rules/{rule}` (`finance.match-rules.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/MatchRuleController.php:75-97`; `name`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0620` at `app/Domain/Finance/Http/Controllers/MatchRuleController.php:15`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0621` at `app/Domain/Finance/Http/Controllers/MatchRuleController.php:44`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0622` at `app/Domain/Finance/Http/Controllers/MatchRuleController.php:102`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0623` at `app/Domain/Finance/Http/Controllers/MatchRuleController.php:75`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/match-rules/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0621` / `store`: fields `name`; success app/Domain/Finance/Http/Controllers/MatchRuleController.php:69 `->with('success', 'Match rule created.');`.
- `ROUTE-0622` / `destroy`: success app/Domain/Finance/Http/Controllers/MatchRuleController.php:107 `->with('success', 'Match rule deleted.');`.
- `ROUTE-0623` / `update`: fields `name`; success app/Domain/Finance/Http/Controllers/MatchRuleController.php:96 `->with('success', 'Match rule updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/MatchRuleController.php:57 `FinMatchRule::create([`; app/Domain/Finance/Http/Controllers/MatchRuleController.php:104 `$rule->delete();`; app/Domain/Finance/Http/Controllers/MatchRuleController.php:86 `$rule->update([`; responses app/Domain/Finance/Http/Controllers/MatchRuleController.php:36 `return Inertia::render('finance/match-rules/Index', [`; app/Domain/Finance/Http/Controllers/MatchRuleController.php:68 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/MatchRuleController.php:106 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/MatchRuleController.php:95 `return redirect()->back()`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/match-rules` — `finance.match-rules.index` — `App\Domain\Finance\Http\Controllers\MatchRuleController@index` — `app/Domain/Finance/Http/Controllers/MatchRuleController.php:15` — middleware `web, auth, permission:finance.bank.manage`
- `POST finance/match-rules` — `finance.match-rules.store` — `App\Domain\Finance\Http\Controllers\MatchRuleController@store` — `app/Domain/Finance/Http/Controllers/MatchRuleController.php:44` — middleware `web, auth, permission:finance.bank.manage`
- `DELETE finance/match-rules/{rule}` — `finance.match-rules.destroy` — `App\Domain\Finance\Http\Controllers\MatchRuleController@destroy` — `app/Domain/Finance/Http/Controllers/MatchRuleController.php:102` — middleware `web, auth, permission:finance.bank.manage`
- `PUT finance/match-rules/{rule}` — `finance.match-rules.update` — `App\Domain\Finance\Http\Controllers\MatchRuleController@update` — `app/Domain/Finance/Http/Controllers/MatchRuleController.php:75` — middleware `web, auth, permission:finance.bank.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/MatchRuleController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/match-rules/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
