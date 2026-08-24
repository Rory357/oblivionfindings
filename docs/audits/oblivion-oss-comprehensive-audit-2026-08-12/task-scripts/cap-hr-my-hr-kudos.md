# CAP-HR-MY-HR-KUDOS: My kudos and shoutouts

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Human resources
- Legacy family: `HR-MY-HR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/my/shoutouts` (`hr.my.shoutouts`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/my/shoutouts` (`hr.my.shoutouts`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/my/kudos` (`hr.my.kudos`, action `sendKudos`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/MyHrController.php:66-99`; `to_user_id`.
3. Invoke only the owning control for `POST hr/my/kudos/{kudos}/react` (`hr.my.kudos.react`, action `reactKudos`). Source category: **mutation outcome source gap (reactKudos)**; controller `app/Http/Controllers/Hr/MyHrController.php:124-137`; `emoji`.
4. Invoke only the owning control for `POST hr/my/kudos/{kudos}/reply` (`hr.my.kudos.reply`, action `replyKudos`). Source category: **mutation outcome source gap (replyKudos)**; controller `app/Http/Controllers/Hr/MyHrController.php:144-158`; `body`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `sendKudos` / `ROUTE-1522` at `app/Http/Controllers/Hr/MyHrController.php:66`; it is not runtime-observed.
- **mutation outcome source gap (reactKudos)** is applicable only to `reactKudos` / `ROUTE-1523` at `app/Http/Controllers/Hr/MyHrController.php:124`; it is not runtime-observed.
- **mutation outcome source gap (replyKudos)** is applicable only to `replyKudos` / `ROUTE-1524` at `app/Http/Controllers/Hr/MyHrController.php:144`; it is not runtime-observed.
- **information presented** is applicable only to `shoutouts` / `ROUTE-1541` at `app/Http/Controllers/Hr/MyHrController.php:107`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/my/shoutouts.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1522` / `sendKudos`: fields `to_user_id`; success app/Http/Controllers/Hr/MyHrController.php:98 `return redirect()->back()->with('success', $count > 1 ? "Kudos sent to {$count} colleagues! 🎉" : 'Kudos sent! 🎉');`.
- `ROUTE-1523` / `reactKudos`: fields `emoji`; success app/Http/Controllers/Hr/MyHrController.php:136 `return redirect()->back()->with('success', 'Reaction updated.');`.
- `ROUTE-1524` / `replyKudos`: fields `body`; success app/Http/Controllers/Hr/MyHrController.php:157 `return redirect()->back()->with('success', 'Reply posted.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/MyHrController.php:93 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/MyHrController.php:98 `return redirect()->back()->with('success', $count > 1 ? "Kudos sent to {$count} colleagues! 🎉" : 'Kudos sent! 🎉');`; app/Http/Controllers/Hr/MyHrController.php:136 `return redirect()->back()->with('success', 'Reaction updated.');`; app/Http/Controllers/Hr/MyHrController.php:157 `return redirect()->back()->with('success', 'Reply posted.');`; app/Http/Controllers/Hr/MyHrController.php:112 `return Inertia::render('hr/my/shoutouts', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/my/kudos` — `hr.my.kudos` — `App\Http\Controllers\Hr\MyHrController@sendKudos` — `app/Http/Controllers/Hr/MyHrController.php:66` — middleware `web, auth`
- `POST hr/my/kudos/{kudos}/react` — `hr.my.kudos.react` — `App\Http\Controllers\Hr\MyHrController@reactKudos` — `app/Http/Controllers/Hr/MyHrController.php:124` — middleware `web, auth`
- `POST hr/my/kudos/{kudos}/reply` — `hr.my.kudos.reply` — `App\Http\Controllers\Hr\MyHrController@replyKudos` — `app/Http/Controllers/Hr/MyHrController.php:144` — middleware `web, auth`
- `GET|HEAD hr/my/shoutouts` — `hr.my.shoutouts` — `App\Http\Controllers\Hr\MyHrController@shoutouts` — `app/Http/Controllers/Hr/MyHrController.php:107` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/MyHrController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/my/shoutouts.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
