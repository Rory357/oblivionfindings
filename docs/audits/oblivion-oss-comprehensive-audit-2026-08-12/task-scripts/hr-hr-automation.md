# HR-HR-AUTOMATION: Hr Automation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.settings.manage`
- Owning module: Human resources
- Legacy family: `HR-HR-AUTOMATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/settings/automations` (`hr.settings.automations.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.settings.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.settings.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/settings/automations` (`hr.settings.automations.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/settings/automations` (`hr.settings.automations.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/HrAutomationController.php:145-166`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE hr/settings/automations/{rule}` (`hr.settings.automations.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/HrAutomationController.php:206-216`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT hr/settings/automations/{rule}` (`hr.settings.automations.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/HrAutomationController.php:168-188`; no exact validation fields extracted.
5. Invoke only the owning control for `POST hr/settings/automations/{rule}/toggle-active` (`hr.settings.automations.toggleActive`, action `toggle`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/HrAutomationController.php:190-204`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1736` at `app/Http/Controllers/Hr/HrAutomationController.php:52`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1737` at `app/Http/Controllers/Hr/HrAutomationController.php:145`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1738` at `app/Http/Controllers/Hr/HrAutomationController.php:206`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1739` at `app/Http/Controllers/Hr/HrAutomationController.php:168`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggle` / `ROUTE-1740` at `app/Http/Controllers/Hr/HrAutomationController.php:190`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/settings/automations.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1737` / `store`: success app/Http/Controllers/Hr/HrAutomationController.php:165 `return redirect()->back()->with('success', 'Automation rule created.');`.
- `ROUTE-1738` / `destroy`: success app/Http/Controllers/Hr/HrAutomationController.php:215 `return redirect()->back()->with('success', 'Automation rule deleted.');`.
- `ROUTE-1739` / `update`: success app/Http/Controllers/Hr/HrAutomationController.php:187 `return redirect()->back()->with('success', 'Automation rule updated.');`.
- `ROUTE-1740` / `toggle`: success app/Http/Controllers/Hr/HrAutomationController.php:203 `return redirect()->back()->with('success', $wasActive ? 'Automation rule paused.' : 'Automation rule resumed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/HrAutomationController.php:153 `HrAutomationRule::query()->create([`; app/Http/Controllers/Hr/HrAutomationController.php:213 `$rule->delete();`; app/Http/Controllers/Hr/HrAutomationController.php:177 `$rule->update([`; app/Http/Controllers/Hr/HrAutomationController.php:198 `$rule->update([`; responses app/Http/Controllers/Hr/HrAutomationController.php:116 `return Inertia::render('hr/settings/automations', [`; app/Http/Controllers/Hr/HrAutomationController.php:165 `return redirect()->back()->with('success', 'Automation rule created.');`; app/Http/Controllers/Hr/HrAutomationController.php:215 `return redirect()->back()->with('success', 'Automation rule deleted.');`; app/Http/Controllers/Hr/HrAutomationController.php:187 `return redirect()->back()->with('success', 'Automation rule updated.');`; app/Http/Controllers/Hr/HrAutomationController.php:203 `return redirect()->back()->with('success', $wasActive ? 'Automation rule paused.' : 'Automation rule resumed.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/settings/automations` — `hr.settings.automations.index` — `App\Http\Controllers\Hr\HrAutomationController@index` — `app/Http/Controllers/Hr/HrAutomationController.php:52` — middleware `web, auth, permission:hr.settings.manage`
- `POST hr/settings/automations` — `hr.settings.automations.store` — `App\Http\Controllers\Hr\HrAutomationController@store` — `app/Http/Controllers/Hr/HrAutomationController.php:145` — middleware `web, auth, permission:hr.settings.manage`
- `DELETE hr/settings/automations/{rule}` — `hr.settings.automations.destroy` — `App\Http\Controllers\Hr\HrAutomationController@destroy` — `app/Http/Controllers/Hr/HrAutomationController.php:206` — middleware `web, auth, permission:hr.settings.manage`
- `PUT hr/settings/automations/{rule}` — `hr.settings.automations.update` — `App\Http\Controllers\Hr\HrAutomationController@update` — `app/Http/Controllers/Hr/HrAutomationController.php:168` — middleware `web, auth, permission:hr.settings.manage`
- `POST hr/settings/automations/{rule}/toggle-active` — `hr.settings.automations.toggleActive` — `App\Http\Controllers\Hr\HrAutomationController@toggle` — `app/Http/Controllers/Hr/HrAutomationController.php:190` — middleware `web, auth, permission:hr.settings.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/HrAutomationController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/settings/automations.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
