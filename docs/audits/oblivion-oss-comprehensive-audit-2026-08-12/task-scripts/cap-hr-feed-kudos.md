# CAP-HR-FEED-KUDOS: Kudos reactions and replies

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.recognition.give`, `permission:hr.employees.manage`
- Owning module: Human resources
- Legacy family: `HR-FEED`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/feed` (`hr.feed.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.recognition.give`, `permission:hr.employees.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.recognition.give`, `permission:hr.employees.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/feed` (`hr.feed.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/feed/kudos` (`hr.feed.kudos`, action `sendKudos`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/FeedController.php:123-158`; `to_user_id`.
3. Invoke only the owning control for `DELETE hr/feed/kudos/{kudos}` (`hr.feed.kudos.destroy`, action `destroyKudos`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/FeedController.php:278-294`; no exact validation fields extracted.
4. Invoke only the owning control for `POST hr/feed/kudos/{kudos}/react` (`hr.feed.kudos.react`, action `react`). Source category: **mutation outcome source gap (react)**; controller `app/Http/Controllers/Hr/FeedController.php:164-179`; `emoji`.
5. Invoke only the owning control for `POST hr/feed/kudos/{kudos}/reply` (`hr.feed.kudos.reply`, action `reply`). Source category: **mutation outcome source gap (reply)**; controller `app/Http/Controllers/Hr/FeedController.php:181-197`; `body`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `sendKudos` / `ROUTE-1435` at `app/Http/Controllers/Hr/FeedController.php:123`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyKudos` / `ROUTE-1436` at `app/Http/Controllers/Hr/FeedController.php:278`; it is not runtime-observed.
- **mutation outcome source gap (react)** is applicable only to `react` / `ROUTE-1437` at `app/Http/Controllers/Hr/FeedController.php:164`; it is not runtime-observed.
- **mutation outcome source gap (reply)** is applicable only to `reply` / `ROUTE-1438` at `app/Http/Controllers/Hr/FeedController.php:181`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1435` / `sendKudos`: fields `to_user_id`; success app/Http/Controllers/Hr/FeedController.php:157 `return redirect()->back()->with('success', $count > 1 ? "Kudos sent to {$count} colleagues! 🎉" : 'Kudos sent! 🎉');`.
- `ROUTE-1436` / `destroyKudos`: success app/Http/Controllers/Hr/FeedController.php:293 `return redirect()->back()->with('success', 'Kudos removed.');`.
- `ROUTE-1437` / `react`: fields `emoji`; success app/Http/Controllers/Hr/FeedController.php:178 `return redirect()->back()->with('success', 'Reaction updated.');`.
- `ROUTE-1438` / `reply`: fields `body`; success app/Http/Controllers/Hr/FeedController.php:196 `return redirect()->back()->with('success', 'Reply posted.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/FeedController.php:152 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/FeedController.php:157 `return redirect()->back()->with('success', $count > 1 ? "Kudos sent to {$count} colleagues! 🎉" : 'Kudos sent! 🎉');`; app/Http/Controllers/Hr/FeedController.php:293 `return redirect()->back()->with('success', 'Kudos removed.');`; app/Http/Controllers/Hr/FeedController.php:178 `return redirect()->back()->with('success', 'Reaction updated.');`; app/Http/Controllers/Hr/FeedController.php:196 `return redirect()->back()->with('success', 'Reply posted.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/feed/kudos` — `hr.feed.kudos` — `App\Http\Controllers\Hr\FeedController@sendKudos` — `app/Http/Controllers/Hr/FeedController.php:123` — middleware `web, auth, permission:hr.recognition.give`
- `DELETE hr/feed/kudos/{kudos}` — `hr.feed.kudos.destroy` — `App\Http\Controllers\Hr\FeedController@destroyKudos` — `app/Http/Controllers/Hr/FeedController.php:278` — middleware `web, auth, permission:hr.employees.manage`
- `POST hr/feed/kudos/{kudos}/react` — `hr.feed.kudos.react` — `App\Http\Controllers\Hr\FeedController@react` — `app/Http/Controllers/Hr/FeedController.php:164` — middleware `web, auth, permission:hr.recognition.give`
- `POST hr/feed/kudos/{kudos}/reply` — `hr.feed.kudos.reply` — `App\Http\Controllers\Hr\FeedController@reply` — `app/Http/Controllers/Hr/FeedController.php:181` — middleware `web, auth, permission:hr.recognition.give`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/FeedController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
