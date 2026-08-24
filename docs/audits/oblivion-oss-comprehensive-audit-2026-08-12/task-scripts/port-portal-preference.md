# PORT-PORTAL-PREFERENCE: Portal Preference

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Client and family portal
- Legacy family: `PORT-PORTAL-PREFERENCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `portal/preferences` (`portal.preferences`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD portal/preferences` (`portal.preferences`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST portal/preferences` (`portal.preferences.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Portal/PortalPreferenceController.php:46-70`; `preferences`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2288` at `app/Http/Controllers/Portal/PortalPreferenceController.php:22`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2289` at `app/Http/Controllers/Portal/PortalPreferenceController.php:46`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/portal/preferences.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2289` / `update`: fields `preferences`; success app/Http/Controllers/Portal/PortalPreferenceController.php:69 `return redirect()->back()->with('success', 'Notification preferences updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Portal/PortalPreferenceController.php:58 `UserNotificationPreference::updateOrCreate(`; responses app/Http/Controllers/Portal/PortalPreferenceController.php:41 `return inertia('portal/preferences', [`; app/Http/Controllers/Portal/PortalPreferenceController.php:69 `return redirect()->back()->with('success', 'Notification preferences updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD portal/preferences` — `portal.preferences` — `App\Http\Controllers\Portal\PortalPreferenceController@index` — `app/Http/Controllers/Portal/PortalPreferenceController.php:22` — middleware `web, auth`
- `POST portal/preferences` — `portal.preferences.update` — `App\Http\Controllers\Portal\PortalPreferenceController@update` — `app/Http/Controllers/Portal/PortalPreferenceController.php:46` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Portal/PortalPreferenceController.php`.
- Exact render/action page relationships: `resources/js/pages/portal/preferences.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
