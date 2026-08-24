# INC-SAFEGUARDING-ATTACHMENT: Safeguarding Attachment

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Incidents and safeguarding
- Legacy family: `INC-SAFEGUARDING-ATTACHMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `safeguarding/{concern}/attachments/{attachment}/download` (`safeguarding.attachments.download`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD safeguarding/{concern}/attachments/{attachment}/download` (`safeguarding.attachments.download`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST safeguarding/{concern}/attachments` (`safeguarding.attachments.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/SafeguardingAttachmentController.php:21-49`; `file`.
3. Invoke only the owning control for `DELETE safeguarding/{concern}/attachments/{attachment}` (`safeguarding.attachments.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/SafeguardingAttachmentController.php:70-82`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2510` at `app/Http/Controllers/SafeguardingAttachmentController.php:21`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2511` at `app/Http/Controllers/SafeguardingAttachmentController.php:70`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-2512` at `app/Http/Controllers/SafeguardingAttachmentController.php:51`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2510` / `store`: fields `file`; success app/Http/Controllers/SafeguardingAttachmentController.php:48 `return back()->with('success', 'Evidence uploaded.');`.
- `ROUTE-2511` / `destroy`: success app/Http/Controllers/SafeguardingAttachmentController.php:81 `return back()->with('success', 'Evidence removed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/SafeguardingAttachmentController.php:37 `$concern->attachments()->create([`; app/Http/Controllers/SafeguardingAttachmentController.php:77 `Storage::disk($disk)->delete($attachment->path);`; app/Http/Controllers/SafeguardingAttachmentController.php:79 `$attachment->delete();`; responses app/Http/Controllers/SafeguardingAttachmentController.php:48 `return back()->with('success', 'Evidence uploaded.');`; app/Http/Controllers/SafeguardingAttachmentController.php:81 `return back()->with('success', 'Evidence removed.');`; app/Http/Controllers/SafeguardingAttachmentController.php:62 `return $this->streamPrivateAttachment(`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST safeguarding/{concern}/attachments` — `safeguarding.attachments.store` — `App\Http\Controllers\SafeguardingAttachmentController@store` — `app/Http/Controllers/SafeguardingAttachmentController.php:21` — middleware `web, auth`
- `DELETE safeguarding/{concern}/attachments/{attachment}` — `safeguarding.attachments.destroy` — `App\Http\Controllers\SafeguardingAttachmentController@destroy` — `app/Http/Controllers/SafeguardingAttachmentController.php:70` — middleware `web, auth`
- `GET|HEAD safeguarding/{concern}/attachments/{attachment}/download` — `safeguarding.attachments.download` — `App\Http\Controllers\SafeguardingAttachmentController@download` — `app/Http/Controllers/SafeguardingAttachmentController.php:51` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/SafeguardingAttachmentController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
