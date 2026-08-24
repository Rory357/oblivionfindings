# CAP-HR-FEED-CONVERSATIONS: Employee feed posts reactions and replies

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.recognition.view`, `permission:hr.recognition.give`, `permission:hr.employees.manage`
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

- Actor satisfying exact route middleware `auth`, `permission:hr.recognition.view`, `permission:hr.recognition.give`, `permission:hr.employees.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.recognition.view`, `permission:hr.recognition.give`, `permission:hr.employees.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/feed` (`hr.feed.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/feed/attachments/{attachment}` (`hr.feed.attachments.show`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/FeedController.php:374-389`.
3. Invoke only the owning control for `POST hr/feed` (`hr.feed.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/FeedController.php:91-117`; `content`.
4. Invoke only the owning control for `DELETE hr/feed/posts/{post}` (`hr.feed.posts.destroy`, action `destroyPost`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/FeedController.php:261-272`; no exact validation fields extracted.
5. Invoke only the owning control for `POST hr/feed/react` (`hr.feed.react`, action `reactFeed`). Source category: **mutation outcome source gap (reactFeed)**; controller `app/Http/Controllers/Hr/FeedController.php:203-225`; `subject_type`.
6. Invoke only the owning control for `POST hr/feed/reply` (`hr.feed.reply`, action `replyFeed`). Source category: **mutation outcome source gap (replyFeed)**; controller `app/Http/Controllers/Hr/FeedController.php:227-249`; `subject_type`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1432` at `app/Http/Controllers/Hr/FeedController.php:37`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1433` at `app/Http/Controllers/Hr/FeedController.php:91`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-1434` at `app/Http/Controllers/Hr/FeedController.php:374`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyPost` / `ROUTE-1439` at `app/Http/Controllers/Hr/FeedController.php:261`; it is not runtime-observed.
- **mutation outcome source gap (reactFeed)** is applicable only to `reactFeed` / `ROUTE-1440` at `app/Http/Controllers/Hr/FeedController.php:203`; it is not runtime-observed.
- **mutation outcome source gap (replyFeed)** is applicable only to `replyFeed` / `ROUTE-1441` at `app/Http/Controllers/Hr/FeedController.php:227`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/feed/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1433` / `store`: fields `content`; success app/Http/Controllers/Hr/FeedController.php:116 `return redirect()->back()->with('success', 'Post published.');`.
- `ROUTE-1439` / `destroyPost`: success app/Http/Controllers/Hr/FeedController.php:271 `return redirect()->back()->with('success', 'Post removed.');`.
- `ROUTE-1440` / `reactFeed`: fields `subject_type`; success app/Http/Controllers/Hr/FeedController.php:224 `return redirect()->back()->with('success', 'Reaction updated.');`.
- `ROUTE-1441` / `replyFeed`: fields `subject_type`; success app/Http/Controllers/Hr/FeedController.php:248 `return redirect()->back()->with('success', 'Reply posted.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/FeedController.php:58 `return Inertia::render('hr/feed/index', [`; app/Http/Controllers/Hr/FeedController.php:113 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/FeedController.php:116 `return redirect()->back()->with('success', 'Post published.');`; app/Http/Controllers/Hr/FeedController.php:382 `return $this->streamPrivateAttachment(`; app/Http/Controllers/Hr/FeedController.php:271 `return redirect()->back()->with('success', 'Post removed.');`; app/Http/Controllers/Hr/FeedController.php:224 `return redirect()->back()->with('success', 'Reaction updated.');`; app/Http/Controllers/Hr/FeedController.php:248 `return redirect()->back()->with('success', 'Reply posted.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/feed` — `hr.feed.index` — `App\Http\Controllers\Hr\FeedController@index` — `app/Http/Controllers/Hr/FeedController.php:37` — middleware `web, auth, permission:hr.recognition.view`
- `POST hr/feed` — `hr.feed.store` — `App\Http\Controllers\Hr\FeedController@store` — `app/Http/Controllers/Hr/FeedController.php:91` — middleware `web, auth, permission:hr.recognition.give`
- `GET|HEAD hr/feed/attachments/{attachment}` — `hr.feed.attachments.show` — `App\Http\Controllers\Hr\FeedController@downloadAttachment` — `app/Http/Controllers/Hr/FeedController.php:374` — middleware `web, auth, permission:hr.recognition.view`
- `DELETE hr/feed/posts/{post}` — `hr.feed.posts.destroy` — `App\Http\Controllers\Hr\FeedController@destroyPost` — `app/Http/Controllers/Hr/FeedController.php:261` — middleware `web, auth, permission:hr.employees.manage`
- `POST hr/feed/react` — `hr.feed.react` — `App\Http\Controllers\Hr\FeedController@reactFeed` — `app/Http/Controllers/Hr/FeedController.php:203` — middleware `web, auth, permission:hr.recognition.give`
- `POST hr/feed/reply` — `hr.feed.reply` — `App\Http\Controllers\Hr\FeedController@replyFeed` — `app/Http/Controllers/Hr/FeedController.php:227` — middleware `web, auth, permission:hr.recognition.give`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/FeedController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/feed/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
