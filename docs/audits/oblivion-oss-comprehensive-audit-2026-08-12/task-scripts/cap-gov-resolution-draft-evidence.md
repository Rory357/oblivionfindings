# CAP-GOV-RESOLUTION-DRAFT-EVIDENCE: Resolution drafting and attachments

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.resolutions.view`, `permission:governance.resolutions.manage`
- Owning module: Governance
- Legacy family: `GOV-RESOLUTION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/resolutions` (`governance.resolutions.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.resolutions.view`, `permission:governance.resolutions.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.resolutions.view`, `permission:governance.resolutions.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/resolutions` (`governance.resolutions.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/resolutions/{resolution}` (`governance.resolutions.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/ResolutionController.php:59-85`.
3. Use `GET|HEAD governance/resolutions/{resolution}/attachments/{attachment}/download` (`governance.resolutions.attachments.download`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Domain/Governance/Http/Controllers/ResolutionController.php:353-369`.
4. Use `GET|HEAD governance/resolutions/create` (`governance.resolutions.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/ResolutionController.php:26-36`.
5. Invoke only the owning control for `POST governance/resolutions` (`governance.resolutions.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/ResolutionController.php:87-111`; FormRequest `app/Domain/Governance/Http/Requests/StoreResolutionRequest.php:15`; `title`, `description`, `type`, `voting_deadline`, `meeting_id`.
6. Invoke only the owning control for `PUT governance/resolutions/{resolution}` (`governance.resolutions.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/ResolutionController.php:113-120`; FormRequest `app/Domain/Governance/Http/Requests/UpdateResolutionRequest.php:14`; `title`, `context`, `recommendation`.
7. Invoke only the owning control for `POST governance/resolutions/{resolution}/attachments` (`governance.resolutions.attachments.store`, action `attachFiles`). Source category: **mutation outcome source gap (attachFiles)**; controller `app/Domain/Governance/Http/Controllers/ResolutionController.php:272-318`; `files`.
8. Invoke only the owning control for `DELETE governance/resolutions/{resolution}/attachments/{attachment}` (`governance.resolutions.attachments.destroy`, action `deleteAttachment`). Source category: **cancelled/removed/archived**; controller `app/Domain/Governance/Http/Controllers/ResolutionController.php:320-351`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0988` at `app/Domain/Governance/Http/Controllers/ResolutionController.php:38`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0989` at `app/Domain/Governance/Http/Controllers/ResolutionController.php:87`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0990` at `app/Domain/Governance/Http/Controllers/ResolutionController.php:59`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0991` at `app/Domain/Governance/Http/Controllers/ResolutionController.php:113`; it is not runtime-observed.
- **mutation outcome source gap (attachFiles)** is applicable only to `attachFiles` / `ROUTE-0992` at `app/Domain/Governance/Http/Controllers/ResolutionController.php:272`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `deleteAttachment` / `ROUTE-0993` at `app/Domain/Governance/Http/Controllers/ResolutionController.php:320`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-0994` at `app/Domain/Governance/Http/Controllers/ResolutionController.php:353`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1000` at `app/Domain/Governance/Http/Controllers/ResolutionController.php:26`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Resolutions/Create.tsx`, `resources/js/pages/Governance/Resolutions/Index.tsx`, `resources/js/pages/Governance/Resolutions/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0989` / `store`: FormRequest `app/Domain/Governance/Http/Requests/StoreResolutionRequest.php:15`; fields `title`, `description`, `type`, `voting_deadline`, `meeting_id`; success app/Domain/Governance/Http/Controllers/ResolutionController.php:110 `->with('success', 'Resolution created.');`.
- `ROUTE-0991` / `update`: FormRequest `app/Domain/Governance/Http/Requests/UpdateResolutionRequest.php:14`; fields `title`, `context`, `recommendation`; success app/Domain/Governance/Http/Controllers/ResolutionController.php:119 `return redirect()->back()->with('success', 'Resolution updated.');`.
- `ROUTE-0992` / `attachFiles`: fields `files`; success app/Domain/Governance/Http/Controllers/ResolutionController.php:317 `: redirect()->back()->with('success', 'Attachment(s) added.');`.
- `ROUTE-0993` / `deleteAttachment`: success app/Domain/Governance/Http/Controllers/ResolutionController.php:350 `: redirect()->back()->with('success', 'Attachment removed.');`; failure app/Domain/Governance/Http/Controllers/ResolutionController.php:328 `abort(404, 'Attachment not found.');`.
- `ROUTE-0994` / `downloadAttachment`: failure app/Domain/Governance/Http/Controllers/ResolutionController.php:361 `abort(404, 'Attachment not found.');`.

## Failure and recovery paths

- `deleteAttachment`: app/Domain/Governance/Http/Controllers/ResolutionController.php:328 `abort(404, 'Attachment not found.');`.
- `downloadAttachment`: app/Domain/Governance/Http/Controllers/ResolutionController.php:361 `abort(404, 'Attachment not found.');`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/ResolutionController.php:97 `$resolution = Resolution::create([`; app/Domain/Governance/Http/Controllers/ResolutionController.php:117 `$resolution->update($validated);`; app/Domain/Governance/Http/Controllers/ResolutionController.php:306 `$resolution->update(['attachments' => $existing]);`; app/Domain/Governance/Http/Controllers/ResolutionController.php:332 `Storage::disk('local')->delete($target['path']);`; app/Domain/Governance/Http/Controllers/ResolutionController.php:339 `$resolution->update(['attachments' => $remaining]);`; responses app/Domain/Governance/Http/Controllers/ResolutionController.php:52 `return Inertia::render('Governance/Resolutions/Index', [`; app/Domain/Governance/Http/Controllers/ResolutionController.php:109 `return redirect()->route('governance.resolutions.show', $resolution)`; app/Domain/Governance/Http/Controllers/ResolutionController.php:75 `return Inertia::render('Governance/Resolutions/Show', [`; app/Domain/Governance/Http/Controllers/ResolutionController.php:119 `return redirect()->back()->with('success', 'Resolution updated.');`; app/Domain/Governance/Http/Controllers/ResolutionController.php:315 `return $request->wantsJson()`; app/Domain/Governance/Http/Controllers/ResolutionController.php:316 `? response()->json(['attachments' => $this->presentAttachments($resolution->fresh())])`; app/Domain/Governance/Http/Controllers/ResolutionController.php:348 `return $request->wantsJson()`; app/Domain/Governance/Http/Controllers/ResolutionController.php:349 `? response()->json(['attachments' => $this->presentAttachments($resolution->fresh())])`; app/Domain/Governance/Http/Controllers/ResolutionController.php:364 `return Storage::disk('local')->download(`; app/Domain/Governance/Http/Controllers/ResolutionController.php:32 `return Inertia::render('Governance/Resolutions/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/resolutions` — `governance.resolutions.index` — `App\Domain\Governance\Http\Controllers\ResolutionController@index` — `app/Domain/Governance/Http/Controllers/ResolutionController.php:38` — middleware `web, auth, permission:governance.resolutions.view`
- `POST governance/resolutions` — `governance.resolutions.store` — `App\Domain\Governance\Http\Controllers\ResolutionController@store` — `app/Domain/Governance/Http/Controllers/ResolutionController.php:87` — middleware `web, auth, permission:governance.resolutions.view, permission:governance.resolutions.manage`
- `GET|HEAD governance/resolutions/{resolution}` — `governance.resolutions.show` — `App\Domain\Governance\Http\Controllers\ResolutionController@show` — `app/Domain/Governance/Http/Controllers/ResolutionController.php:59` — middleware `web, auth, permission:governance.resolutions.view`
- `PUT governance/resolutions/{resolution}` — `governance.resolutions.update` — `App\Domain\Governance\Http\Controllers\ResolutionController@update` — `app/Domain/Governance/Http/Controllers/ResolutionController.php:113` — middleware `web, auth, permission:governance.resolutions.view, permission:governance.resolutions.manage`
- `POST governance/resolutions/{resolution}/attachments` — `governance.resolutions.attachments.store` — `App\Domain\Governance\Http\Controllers\ResolutionController@attachFiles` — `app/Domain/Governance/Http/Controllers/ResolutionController.php:272` — middleware `web, auth, permission:governance.resolutions.view, permission:governance.resolutions.manage`
- `DELETE governance/resolutions/{resolution}/attachments/{attachment}` — `governance.resolutions.attachments.destroy` — `App\Domain\Governance\Http\Controllers\ResolutionController@deleteAttachment` — `app/Domain/Governance/Http/Controllers/ResolutionController.php:320` — middleware `web, auth, permission:governance.resolutions.view, permission:governance.resolutions.manage`
- `GET|HEAD governance/resolutions/{resolution}/attachments/{attachment}/download` — `governance.resolutions.attachments.download` — `App\Domain\Governance\Http\Controllers\ResolutionController@downloadAttachment` — `app/Domain/Governance/Http/Controllers/ResolutionController.php:353` — middleware `web, auth, permission:governance.resolutions.view`
- `GET|HEAD governance/resolutions/create` — `governance.resolutions.create` — `App\Domain\Governance\Http\Controllers\ResolutionController@create` — `app/Domain/Governance/Http/Controllers/ResolutionController.php:26` — middleware `web, auth, permission:governance.resolutions.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/ResolutionController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Resolutions/Create.tsx`, `resources/js/pages/Governance/Resolutions/Index.tsx`, `resources/js/pages/Governance/Resolutions/Show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
