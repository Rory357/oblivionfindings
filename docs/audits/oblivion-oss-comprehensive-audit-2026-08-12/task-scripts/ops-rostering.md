# OPS-ROSTERING: Rostering

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:rostering.viewAny`, `permission:rostering.autoSchedule`, `permission:rostering.publish`
- Owning module: Operations and rostering
- Legacy family: `OPS-ROSTERING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/rostering` (`operations.rostering.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:rostering.viewAny`, `permission:rostering.autoSchedule`, `permission:rostering.publish`.
- Exact middleware atoms: `web`, `auth`, `role_scope:my-day`, `permission:rostering.viewAny`, `permission:rostering.autoSchedule`, `permission:rostering.publish`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/rostering` (`operations.rostering.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/rostering/conflicts` (`operations.rostering.conflicts`, action `conflicts`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/RosteringController.php:877-1059`.
3. Use `GET|HEAD operations/rostering/periods/{period}/diff` (`operations.rostering.periods.diff`, action `viewDiff`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/RosteringController.php:1191-1216`.
4. Use `GET|HEAD operations/rostering/periods/{period}/review` (`operations.rostering.periods.review.show`, action `viewPublishReview`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/RosteringController.php:1124-1170`.
5. Invoke only the owning control for `POST operations/rostering/auto-schedule` (`operations.rostering.auto_schedule`, action `autoSchedule`). Source category: **mutation outcome source gap (autoSchedule)**; controller `app/Http/Controllers/RosteringController.php:1061-1103`; FormRequest `app/Http/Requests/Operations/Rostering/AutoScheduleRosterRequest.php:14`; `week`, `client_id`, `site_id`.
6. Invoke only the owning control for `POST operations/rostering/periods/{period}/publish` (`operations.rostering.periods.publish`, action `confirmPublish`). Source category: **mutation outcome source gap (confirmPublish)**; controller `app/Http/Controllers/RosteringController.php:1172-1189`; no exact validation fields extracted.
7. Invoke only the owning control for `POST operations/rostering/periods/{period}/republish` (`operations.rostering.periods.republish`, action `republish`). Source category: **mutation outcome source gap (republish)**; controller `app/Http/Controllers/RosteringController.php:1218-1235`; no exact validation fields extracted.
8. Invoke only the owning control for `POST operations/rostering/periods/{period}/review` (`operations.rostering.periods.review`, action `reviewForPublish`). Source category: **mutation outcome source gap (reviewForPublish)**; controller `app/Http/Controllers/RosteringController.php:1105-1122`; no exact validation fields extracted.
9. Invoke only the owning control for `POST operations/rostering/periods/{period}/unpublish` (`operations.rostering.periods.unpublish`, action `unpublish`). Source category: **mutation outcome source gap (unpublish)**; controller `app/Http/Controllers/RosteringController.php:1237-1252`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2141` at `app/Http/Controllers/RosteringController.php:46`; it is not runtime-observed.
- **mutation outcome source gap (autoSchedule)** is applicable only to `autoSchedule` / `ROUTE-2142` at `app/Http/Controllers/RosteringController.php:1061`; it is not runtime-observed.
- **information presented** is applicable only to `conflicts` / `ROUTE-2146` at `app/Http/Controllers/RosteringController.php:877`; it is not runtime-observed.
- **information presented** is applicable only to `viewDiff` / `ROUTE-2150` at `app/Http/Controllers/RosteringController.php:1191`; it is not runtime-observed.
- **mutation outcome source gap (confirmPublish)** is applicable only to `confirmPublish` / `ROUTE-2151` at `app/Http/Controllers/RosteringController.php:1172`; it is not runtime-observed.
- **mutation outcome source gap (republish)** is applicable only to `republish` / `ROUTE-2152` at `app/Http/Controllers/RosteringController.php:1218`; it is not runtime-observed.
- **information presented** is applicable only to `viewPublishReview` / `ROUTE-2153` at `app/Http/Controllers/RosteringController.php:1124`; it is not runtime-observed.
- **mutation outcome source gap (reviewForPublish)** is applicable only to `reviewForPublish` / `ROUTE-2154` at `app/Http/Controllers/RosteringController.php:1105`; it is not runtime-observed.
- **mutation outcome source gap (unpublish)** is applicable only to `unpublish` / `ROUTE-2155` at `app/Http/Controllers/RosteringController.php:1237`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/rostering/conflicts.tsx`, `resources/js/pages/operations/rostering/index.tsx`, `resources/js/pages/operations/rostering/publish/Diff.tsx`, `resources/js/pages/operations/rostering/publish/Review.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2141` / `index`: FormRequest `app/Http/Requests/Operations/Rostering/RosteringIndexRequest.php:14`; fields `week`, `staff_id`, `client_id`, `site_id`.
- `ROUTE-2142` / `autoSchedule`: FormRequest `app/Http/Requests/Operations/Rostering/AutoScheduleRosterRequest.php:14`; fields `week`, `client_id`, `site_id`.
- `ROUTE-2146` / `conflicts`: FormRequest `app/Http/Requests/Operations/Rostering/RosteringConflictsRequest.php:14`; fields `week`.
- `ROUTE-2151` / `confirmPublish`: success app/Http/Controllers/RosteringController.php:1187 `->with('success', __('rostering.publish.published_message'))`.
- `ROUTE-2152` / `republish`: success app/Http/Controllers/RosteringController.php:1233 `->with('success', __('rostering.publish.republished_message', ['version' => $published->version]))`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/RosteringController.php:256 `return false;`; app/Http/Controllers/RosteringController.php:259 `return in_array($ts->status, ['draft', 'submitted', 'returned'], true);`; app/Http/Controllers/RosteringController.php:395 `return null;`; app/Http/Controllers/RosteringController.php:398 `return [`; app/Http/Controllers/RosteringController.php:436 `return [`; app/Http/Controllers/RosteringController.php:468 `return [`; app/Http/Controllers/RosteringController.php:547 `return inertia('operations/rostering/index', [`; app/Http/Controllers/RosteringController.php:622 `return [`; app/Http/Controllers/RosteringController.php:1076 `return redirect()`; app/Http/Controllers/RosteringController.php:1086 `return redirect()`; app/Http/Controllers/RosteringController.php:1096 `return redirect()`; app/Http/Controllers/RosteringController.php:1011 `return null;`; app/Http/Controllers/RosteringController.php:1014 `return [`; app/Http/Controllers/RosteringController.php:1031 `return [`; app/Http/Controllers/RosteringController.php:1047 `return inertia('operations/rostering/conflicts', [`; app/Http/Controllers/RosteringController.php:1201 `return inertia('operations/rostering/publish/Diff', [`; app/Http/Controllers/RosteringController.php:1182 `return redirect()`; app/Http/Controllers/RosteringController.php:1228 `return redirect()`; app/Http/Controllers/RosteringController.php:1143 `return inertia('operations/rostering/publish/Review', [`; app/Http/Controllers/RosteringController.php:1114 `return redirect()`; app/Http/Controllers/RosteringController.php:1246 `return redirect()`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/rostering` — `operations.rostering.index` — `App\Http\Controllers\RosteringController@index` — `app/Http/Controllers/RosteringController.php:46` — middleware `web, auth, role_scope:my-day, permission:rostering.viewAny`
- `POST operations/rostering/auto-schedule` — `operations.rostering.auto_schedule` — `App\Http\Controllers\RosteringController@autoSchedule` — `app/Http/Controllers/RosteringController.php:1061` — middleware `web, auth, permission:rostering.autoSchedule`
- `GET|HEAD operations/rostering/conflicts` — `operations.rostering.conflicts` — `App\Http\Controllers\RosteringController@conflicts` — `app/Http/Controllers/RosteringController.php:877` — middleware `web, auth, role_scope:my-day, permission:rostering.viewAny`
- `GET|HEAD operations/rostering/periods/{period}/diff` — `operations.rostering.periods.diff` — `App\Http\Controllers\RosteringController@viewDiff` — `app/Http/Controllers/RosteringController.php:1191` — middleware `web, auth, permission:rostering.publish`
- `POST operations/rostering/periods/{period}/publish` — `operations.rostering.periods.publish` — `App\Http\Controllers\RosteringController@confirmPublish` — `app/Http/Controllers/RosteringController.php:1172` — middleware `web, auth, permission:rostering.publish`
- `POST operations/rostering/periods/{period}/republish` — `operations.rostering.periods.republish` — `App\Http\Controllers\RosteringController@republish` — `app/Http/Controllers/RosteringController.php:1218` — middleware `web, auth, permission:rostering.publish`
- `GET|HEAD operations/rostering/periods/{period}/review` — `operations.rostering.periods.review.show` — `App\Http\Controllers\RosteringController@viewPublishReview` — `app/Http/Controllers/RosteringController.php:1124` — middleware `web, auth, permission:rostering.publish`
- `POST operations/rostering/periods/{period}/review` — `operations.rostering.periods.review` — `App\Http\Controllers\RosteringController@reviewForPublish` — `app/Http/Controllers/RosteringController.php:1105` — middleware `web, auth, permission:rostering.publish`
- `POST operations/rostering/periods/{period}/unpublish` — `operations.rostering.periods.unpublish` — `App\Http\Controllers\RosteringController@unpublish` — `app/Http/Controllers/RosteringController.php:1237` — middleware `web, auth, permission:rostering.publish`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/RosteringController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/rostering/conflicts.tsx`, `resources/js/pages/operations/rostering/index.tsx`, `resources/js/pages/operations/rostering/publish/Diff.tsx`, `resources/js/pages/operations/rostering/publish/Review.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
