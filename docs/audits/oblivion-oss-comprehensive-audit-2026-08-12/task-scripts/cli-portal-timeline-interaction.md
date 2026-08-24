# CLI-PORTAL-TIMELINE-INTERACTION: Portal Timeline Interaction

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Clients and supported people
- Legacy family: `CLI-PORTAL-TIMELINE-INTERACTION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST portal/clients/{client}/timeline/{timelineEvent}/comments` (`portal.clients.timeline.comments.store`, action `storeComment`). Source category: **created/recorded**; controller `app/Http/Controllers/Portal/PortalTimelineInteractionController.php:24-49`; `body`, `parent_id`.
3. Invoke only the owning control for `POST portal/clients/{client}/timeline/{timelineEvent}/react` (`portal.clients.timeline.react`, action `toggleReaction`). Source category: **updated/revised**; controller `app/Http/Controllers/Portal/PortalTimelineInteractionController.php:87-114`; `emoji`.
4. Invoke only the owning control for `DELETE portal/clients/{client}/timeline/comments/{timelineEventComment}` (`portal.clients.timeline.comments.destroy`, action `destroyComment`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Portal/PortalTimelineInteractionController.php:74-85`; no exact validation fields extracted.
5. Invoke only the owning control for `POST portal/clients/{client}/timeline/comments/{timelineEventComment}/like` (`portal.clients.timeline.comments.like`, action `toggleCommentLike`). Source category: **updated/revised**; controller `app/Http/Controllers/Portal/PortalTimelineInteractionController.php:51-72`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeComment` / `ROUTE-2278` at `app/Http/Controllers/Portal/PortalTimelineInteractionController.php:24`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggleReaction` / `ROUTE-2279` at `app/Http/Controllers/Portal/PortalTimelineInteractionController.php:87`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyComment` / `ROUTE-2280` at `app/Http/Controllers/Portal/PortalTimelineInteractionController.php:74`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggleCommentLike` / `ROUTE-2281` at `app/Http/Controllers/Portal/PortalTimelineInteractionController.php:51`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2278` / `storeComment`: fields `body`, `parent_id`.
- `ROUTE-2279` / `toggleReaction`: fields `emoji`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Portal/PortalTimelineInteractionController.php:41 `TimelineEventComment::create([`; app/Http/Controllers/Portal/PortalTimelineInteractionController.php:104 `$existing->delete();`; app/Http/Controllers/Portal/PortalTimelineInteractionController.php:106 `TimelineEventReaction::create([`; app/Http/Controllers/Portal/PortalTimelineInteractionController.php:82 `$timelineEventComment->delete();`; app/Http/Controllers/Portal/PortalTimelineInteractionController.php:63 `$existing->delete();`; app/Http/Controllers/Portal/PortalTimelineInteractionController.php:65 `TimelineCommentLike::create([`; responses app/Http/Controllers/Portal/PortalTimelineInteractionController.php:48 `return redirect()->back();`; app/Http/Controllers/Portal/PortalTimelineInteractionController.php:113 `return redirect()->back();`; app/Http/Controllers/Portal/PortalTimelineInteractionController.php:84 `return redirect()->back();`; app/Http/Controllers/Portal/PortalTimelineInteractionController.php:71 `return redirect()->back();`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST portal/clients/{client}/timeline/{timelineEvent}/comments` — `portal.clients.timeline.comments.store` — `App\Http\Controllers\Portal\PortalTimelineInteractionController@storeComment` — `app/Http/Controllers/Portal/PortalTimelineInteractionController.php:24` — middleware `web, auth`
- `POST portal/clients/{client}/timeline/{timelineEvent}/react` — `portal.clients.timeline.react` — `App\Http\Controllers\Portal\PortalTimelineInteractionController@toggleReaction` — `app/Http/Controllers/Portal/PortalTimelineInteractionController.php:87` — middleware `web, auth`
- `DELETE portal/clients/{client}/timeline/comments/{timelineEventComment}` — `portal.clients.timeline.comments.destroy` — `App\Http\Controllers\Portal\PortalTimelineInteractionController@destroyComment` — `app/Http/Controllers/Portal/PortalTimelineInteractionController.php:74` — middleware `web, auth`
- `POST portal/clients/{client}/timeline/comments/{timelineEventComment}/like` — `portal.clients.timeline.comments.like` — `App\Http\Controllers\Portal\PortalTimelineInteractionController@toggleCommentLike` — `app/Http/Controllers/Portal/PortalTimelineInteractionController.php:51` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Portal/PortalTimelineInteractionController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
