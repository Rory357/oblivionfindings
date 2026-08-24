# GOV-TE-TIRITI: Te Tiriti

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.te-tiriti.view`, `permission:governance.te-tiriti.manage`
- Owning module: Governance
- Legacy family: `GOV-TE-TIRITI`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/te-tiriti` (`governance.te-tiriti.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.te-tiriti.view`, `permission:governance.te-tiriti.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.te-tiriti.view`, `permission:governance.te-tiriti.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/te-tiriti` (`governance.te-tiriti.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST governance/te-tiriti` (`governance.te-tiriti.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/TeTiritiController.php:43-71`; `principle`, `title`, `description`, `implementation_status`, `evidence`, `evidence_notes`, `actions_taken`, `target_date`, `progress_pct`.
3. Invoke only the owning control for `PUT governance/te-tiriti/{obligation}` (`governance.te-tiriti.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/TeTiritiController.php:73-106`; `title`, `description`, `implementation_status`, `evidence`, `evidence_notes`, `actions_taken`, `target_date`, `progress_pct`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1041` at `app/Domain/Governance/Http/Controllers/TeTiritiController.php:12`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1042` at `app/Domain/Governance/Http/Controllers/TeTiritiController.php:43`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1043` at `app/Domain/Governance/Http/Controllers/TeTiritiController.php:73`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/TeTiriti/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1042` / `store`: fields `principle`, `title`, `description`, `implementation_status`, `evidence`, `evidence_notes`, `actions_taken`, `target_date`, `progress_pct`; success app/Domain/Governance/Http/Controllers/TeTiritiController.php:70 `return redirect()->back()->with('success', 'Te Tiriti obligation added.');`.
- `ROUTE-1043` / `update`: fields `title`, `description`, `implementation_status`, `evidence`, `evidence_notes`, `actions_taken`, `target_date`, `progress_pct`; success app/Domain/Governance/Http/Controllers/TeTiritiController.php:105 `return redirect()->back()->with('success', 'Te Tiriti obligation updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/TeTiritiController.php:58 `TeTiritiObligation::create([`; app/Domain/Governance/Http/Controllers/TeTiritiController.php:103 `$obligation->update($payload);`; responses app/Domain/Governance/Http/Controllers/TeTiritiController.php:31 `return Inertia::render('Governance/TeTiriti/Index', [`; app/Domain/Governance/Http/Controllers/TeTiritiController.php:70 `return redirect()->back()->with('success', 'Te Tiriti obligation added.');`; app/Domain/Governance/Http/Controllers/TeTiritiController.php:105 `return redirect()->back()->with('success', 'Te Tiriti obligation updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/te-tiriti` — `governance.te-tiriti.index` — `App\Domain\Governance\Http\Controllers\TeTiritiController@index` — `app/Domain/Governance/Http/Controllers/TeTiritiController.php:12` — middleware `web, auth, permission:governance.te-tiriti.view`
- `POST governance/te-tiriti` — `governance.te-tiriti.store` — `App\Domain\Governance\Http\Controllers\TeTiritiController@store` — `app/Domain/Governance/Http/Controllers/TeTiritiController.php:43` — middleware `web, auth, permission:governance.te-tiriti.view, permission:governance.te-tiriti.manage`
- `PUT governance/te-tiriti/{obligation}` — `governance.te-tiriti.update` — `App\Domain\Governance\Http\Controllers\TeTiritiController@update` — `app/Domain/Governance/Http/Controllers/TeTiritiController.php:73` — middleware `web, auth, permission:governance.te-tiriti.view, permission:governance.te-tiriti.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/TeTiritiController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/TeTiriti/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
