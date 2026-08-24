# PRIV-PRIVACY-ATTACHMENT: Privacy Attachment

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Privacy and compliance
- Legacy family: `PRIV-PRIVACY-ATTACHMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `privacy/attachments/{attachment}/download` (`privacy.attachments.download`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD privacy/attachments/{attachment}/download` (`privacy.attachments.download`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST privacy/attachments` (`privacy.attachments.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/PrivacyAttachmentController.php:40-73`; `attachable_type`.
3. Invoke only the owning control for `DELETE privacy/attachments/{attachment}` (`privacy.attachments.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/PrivacyAttachmentController.php:97-112`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2294` at `app/Http/Controllers/PrivacyAttachmentController.php:40`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2295` at `app/Http/Controllers/PrivacyAttachmentController.php:97`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-2296` at `app/Http/Controllers/PrivacyAttachmentController.php:75`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2294` / `store`: fields `attachable_type`; success app/Http/Controllers/PrivacyAttachmentController.php:72 `return back()->with('success', 'Document uploaded.');`.
- `ROUTE-2295` / `destroy`: success app/Http/Controllers/PrivacyAttachmentController.php:111 `return back()->with('success', 'Document removed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/PrivacyAttachmentController.php:61 `$model->attachments()->create([`; app/Http/Controllers/PrivacyAttachmentController.php:107 `Storage::disk($disk)->delete($attachment->path);`; app/Http/Controllers/PrivacyAttachmentController.php:109 `$attachment->delete();`; responses app/Http/Controllers/PrivacyAttachmentController.php:72 `return back()->with('success', 'Document uploaded.');`; app/Http/Controllers/PrivacyAttachmentController.php:111 `return back()->with('success', 'Document removed.');`; app/Http/Controllers/PrivacyAttachmentController.php:89 `return $this->streamPrivateAttachment(`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST privacy/attachments` — `privacy.attachments.store` — `App\Http\Controllers\PrivacyAttachmentController@store` — `app/Http/Controllers/PrivacyAttachmentController.php:40` — middleware `web, auth`
- `DELETE privacy/attachments/{attachment}` — `privacy.attachments.destroy` — `App\Http\Controllers\PrivacyAttachmentController@destroy` — `app/Http/Controllers/PrivacyAttachmentController.php:97` — middleware `web, auth`
- `GET|HEAD privacy/attachments/{attachment}/download` — `privacy.attachments.download` — `App\Http\Controllers\PrivacyAttachmentController@download` — `app/Http/Controllers/PrivacyAttachmentController.php:75` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/PrivacyAttachmentController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
