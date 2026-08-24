# GOV-BOARD-PACK: Board Pack

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.packs.view`, `permission:governance.packs.manage`
- Owning module: Governance
- Legacy family: `GOV-BOARD-PACK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/packs` (`governance.packs.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.packs.view`, `permission:governance.packs.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.packs.view`, `permission:governance.packs.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/packs` (`governance.packs.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/packs/{pack}` (`governance.packs.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/BoardPackController.php:76-91`.
3. Use `GET|HEAD governance/packs/{pack}/attachments/{attachment}/download` (`governance.packs.attachments.download`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Domain/Governance/Http/Controllers/BoardPackController.php:352-384`.
4. Use `GET|HEAD governance/packs/{pack}/download` (`governance.packs.download`, action `download`) only from its authorised surface; inspect the returned file/report. Source: `app/Domain/Governance/Http/Controllers/BoardPackController.php:194-226`.
5. Invoke only the owning control for `POST governance/meetings/{meeting}/packs` (`governance.packs.generate`, action `generate`). Source category: **mutation outcome source gap (generate)**; controller `app/Domain/Governance/Http/Controllers/BoardPackController.php:93-146`; no exact validation fields extracted.
6. Invoke only the owning control for `POST governance/packs/{pack}/attachments` (`governance.packs.attachments.store`, action `attachFiles`). Source category: **mutation outcome source gap (attachFiles)**; controller `app/Domain/Governance/Http/Controllers/BoardPackController.php:264-311`; `files`.
7. Invoke only the owning control for `DELETE governance/packs/{pack}/attachments/{attachment}` (`governance.packs.attachments.destroy`, action `deleteAttachment`). Source category: **cancelled/removed/archived**; controller `app/Domain/Governance/Http/Controllers/BoardPackController.php:316-347`; no exact validation fields extracted.
8. Invoke only the owning control for `POST governance/packs/{pack}/distribute` (`governance.packs.distribute`, action `distribute`). Source category: **mutation outcome source gap (distribute)**; controller `app/Domain/Governance/Http/Controllers/BoardPackController.php:179-192`; `board_member_ids`.
9. Invoke only the owning control for `POST governance/packs/{pack}/read` (`governance.packs.read`, action `markAsRead`). Source category: **mutation outcome source gap (markAsRead)**; controller `app/Domain/Governance/Http/Controllers/BoardPackController.php:237-248`; no exact validation fields extracted.
10. Invoke only the owning control for `POST governance/packs/{pack}/regenerate` (`governance.packs.regenerate`, action `regenerate`). Source category: **mutation outcome source gap (regenerate)**; controller `app/Domain/Governance/Http/Controllers/BoardPackController.php:250-258`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (generate)** is applicable only to `generate` / `ROUTE-0947` at `app/Domain/Governance/Http/Controllers/BoardPackController.php:93`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-0952` at `app/Domain/Governance/Http/Controllers/BoardPackController.php:23`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0953` at `app/Domain/Governance/Http/Controllers/BoardPackController.php:76`; it is not runtime-observed.
- **mutation outcome source gap (attachFiles)** is applicable only to `attachFiles` / `ROUTE-0954` at `app/Domain/Governance/Http/Controllers/BoardPackController.php:264`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `deleteAttachment` / `ROUTE-0955` at `app/Domain/Governance/Http/Controllers/BoardPackController.php:316`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-0956` at `app/Domain/Governance/Http/Controllers/BoardPackController.php:352`; it is not runtime-observed.
- **mutation outcome source gap (distribute)** is applicable only to `distribute` / `ROUTE-0957` at `app/Domain/Governance/Http/Controllers/BoardPackController.php:179`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-0958` at `app/Domain/Governance/Http/Controllers/BoardPackController.php:194`; it is not runtime-observed.
- **mutation outcome source gap (markAsRead)** is applicable only to `markAsRead` / `ROUTE-0959` at `app/Domain/Governance/Http/Controllers/BoardPackController.php:237`; it is not runtime-observed.
- **mutation outcome source gap (regenerate)** is applicable only to `regenerate` / `ROUTE-0960` at `app/Domain/Governance/Http/Controllers/BoardPackController.php:250`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Packs/Index.tsx`, `resources/js/pages/Governance/Packs/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0947` / `generate`: success app/Domain/Governance/Http/Controllers/BoardPackController.php:118 `->with('success', 'Board pack generated.');`; app/Domain/Governance/Http/Controllers/BoardPackController.php:129 `->with('success', 'Board pack generation started. You will be notified when complete.');`.
- `ROUTE-0954` / `attachFiles`: fields `files`; success app/Domain/Governance/Http/Controllers/BoardPackController.php:310 `: redirect()->back()->with('success', 'Document(s) added to the pack.');`.
- `ROUTE-0955` / `deleteAttachment`: success app/Domain/Governance/Http/Controllers/BoardPackController.php:346 `: redirect()->back()->with('success', 'Attachment removed from the pack.');`; failure app/Domain/Governance/Http/Controllers/BoardPackController.php:324 `abort(404, 'Attachment not found.');`.
- `ROUTE-0956` / `downloadAttachment`: failure app/Domain/Governance/Http/Controllers/BoardPackController.php:360 `abort(403, 'Not authorised to access this attachment.');`; app/Domain/Governance/Http/Controllers/BoardPackController.php:367 `abort(404, 'Attachment not found.');`.
- `ROUTE-0957` / `distribute`: fields `board_member_ids`; success app/Domain/Governance/Http/Controllers/BoardPackController.php:191 `return redirect()->back()->with('success', 'Board pack distributed to members.');`.
- `ROUTE-0958` / `download`: failure app/Domain/Governance/Http/Controllers/BoardPackController.php:199 `abort(403, 'Board access required.');`; app/Domain/Governance/Http/Controllers/BoardPackController.php:203 `abort(403, 'Pack not yet distributed.');`; app/Domain/Governance/Http/Controllers/BoardPackController.php:208 `abort(403, 'You are not authorized to access this pack.');`; app/Domain/Governance/Http/Controllers/BoardPackController.php:222 `abort(404, 'Pack file not found.');`.
- `ROUTE-0959` / `markAsRead`: failure app/Domain/Governance/Http/Controllers/BoardPackController.php:242 `abort(403);`.
- `ROUTE-0960` / `regenerate`: success app/Domain/Governance/Http/Controllers/BoardPackController.php:257 `->with('success', 'Board pack regenerated with fresh data.');`.

## Failure and recovery paths

- `deleteAttachment`: app/Domain/Governance/Http/Controllers/BoardPackController.php:324 `abort(404, 'Attachment not found.');`.
- `downloadAttachment`: app/Domain/Governance/Http/Controllers/BoardPackController.php:360 `abort(403, 'Not authorised to access this attachment.');`; app/Domain/Governance/Http/Controllers/BoardPackController.php:367 `abort(404, 'Attachment not found.');`.
- `download`: app/Domain/Governance/Http/Controllers/BoardPackController.php:199 `abort(403, 'Board access required.');`; app/Domain/Governance/Http/Controllers/BoardPackController.php:203 `abort(403, 'Pack not yet distributed.');`; app/Domain/Governance/Http/Controllers/BoardPackController.php:208 `abort(403, 'You are not authorized to access this pack.');`; app/Domain/Governance/Http/Controllers/BoardPackController.php:222 `abort(404, 'Pack file not found.');`.
- `markAsRead`: app/Domain/Governance/Http/Controllers/BoardPackController.php:242 `abort(403);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/BoardPackController.php:299 `$pack->update(['supplementary_attachments' => $existing]);`; app/Domain/Governance/Http/Controllers/BoardPackController.php:328 `Storage::disk('local')->delete($target['path']);`; app/Domain/Governance/Http/Controllers/BoardPackController.php:335 `$pack->update(['supplementary_attachments' => $remaining]);`; responses app/Domain/Governance/Http/Controllers/BoardPackController.php:100 `return $this->regenerateForMeeting($request, $meeting, $existingPack);`; app/Domain/Governance/Http/Controllers/BoardPackController.php:111 `return response()->json([`; app/Domain/Governance/Http/Controllers/BoardPackController.php:117 `return redirect()->route('governance.packs.show', $pack)`; app/Domain/Governance/Http/Controllers/BoardPackController.php:125 `return response()->json(['status' => 'queued']);`; app/Domain/Governance/Http/Controllers/BoardPackController.php:128 `return redirect()->route('governance.meetings.show', $meeting)`; app/Domain/Governance/Http/Controllers/BoardPackController.php:138 `return response()->json([`; app/Domain/Governance/Http/Controllers/BoardPackController.php:144 `return redirect()->back()->with('error', 'Board pack generation failed: ' . $e->getMessage());`; app/Domain/Governance/Http/Controllers/BoardPackController.php:56 `return Inertia::render('Governance/Packs/Index', [`; app/Domain/Governance/Http/Controllers/BoardPackController.php:81 `return Inertia::render('Governance/Packs/Show', [`; app/Domain/Governance/Http/Controllers/BoardPackController.php:308 `return $request->wantsJson()`; app/Domain/Governance/Http/Controllers/BoardPackController.php:309 `? response()->json(['attachments' => $this->presentSupplementaryAttachments($pack->fresh())])`; app/Domain/Governance/Http/Controllers/BoardPackController.php:344 `return $request->wantsJson()`; app/Domain/Governance/Http/Controllers/BoardPackController.php:345 `? response()->json(['attachments' => $this->presentSupplementaryAttachments($pack->fresh())])`; app/Domain/Governance/Http/Controllers/BoardPackController.php:379 `return Storage::disk('local')->download(`; app/Domain/Governance/Http/Controllers/BoardPackController.php:191 `return redirect()->back()->with('success', 'Board pack distributed to members.');`; app/Domain/Governance/Http/Controllers/BoardPackController.php:225 `return Storage::download($pack->file_path, basename($pack->file_path));`; app/Domain/Governance/Http/Controllers/BoardPackController.php:247 `return response()->json(['success' => true]);`; app/Domain/Governance/Http/Controllers/BoardPackController.php:256 `return redirect()->route('governance.packs.show', $newPack)`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Domain/Governance/Http/Controllers/BoardPackController.php:122 `\App\Domain\Governance\Jobs\GenerateBoardPack::dispatch($meeting->id);`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST governance/meetings/{meeting}/packs` — `governance.packs.generate` — `App\Domain\Governance\Http\Controllers\BoardPackController@generate` — `app/Domain/Governance/Http/Controllers/BoardPackController.php:93` — middleware `web, auth, permission:governance.packs.view, permission:governance.packs.manage`
- `GET|HEAD governance/packs` — `governance.packs.index` — `App\Domain\Governance\Http\Controllers\BoardPackController@index` — `app/Domain/Governance/Http/Controllers/BoardPackController.php:23` — middleware `web, auth, permission:governance.packs.view`
- `GET|HEAD governance/packs/{pack}` — `governance.packs.show` — `App\Domain\Governance\Http\Controllers\BoardPackController@show` — `app/Domain/Governance/Http/Controllers/BoardPackController.php:76` — middleware `web, auth, permission:governance.packs.view`
- `POST governance/packs/{pack}/attachments` — `governance.packs.attachments.store` — `App\Domain\Governance\Http\Controllers\BoardPackController@attachFiles` — `app/Domain/Governance/Http/Controllers/BoardPackController.php:264` — middleware `web, auth, permission:governance.packs.view, permission:governance.packs.manage`
- `DELETE governance/packs/{pack}/attachments/{attachment}` — `governance.packs.attachments.destroy` — `App\Domain\Governance\Http\Controllers\BoardPackController@deleteAttachment` — `app/Domain/Governance/Http/Controllers/BoardPackController.php:316` — middleware `web, auth, permission:governance.packs.view, permission:governance.packs.manage`
- `GET|HEAD governance/packs/{pack}/attachments/{attachment}/download` — `governance.packs.attachments.download` — `App\Domain\Governance\Http\Controllers\BoardPackController@downloadAttachment` — `app/Domain/Governance/Http/Controllers/BoardPackController.php:352` — middleware `web, auth, permission:governance.packs.view`
- `POST governance/packs/{pack}/distribute` — `governance.packs.distribute` — `App\Domain\Governance\Http\Controllers\BoardPackController@distribute` — `app/Domain/Governance/Http/Controllers/BoardPackController.php:179` — middleware `web, auth, permission:governance.packs.view, permission:governance.packs.manage`
- `GET|HEAD governance/packs/{pack}/download` — `governance.packs.download` — `App\Domain\Governance\Http\Controllers\BoardPackController@download` — `app/Domain/Governance/Http/Controllers/BoardPackController.php:194` — middleware `web, auth, permission:governance.packs.view`
- `POST governance/packs/{pack}/read` — `governance.packs.read` — `App\Domain\Governance\Http\Controllers\BoardPackController@markAsRead` — `app/Domain/Governance/Http/Controllers/BoardPackController.php:237` — middleware `web, auth, permission:governance.packs.view`
- `POST governance/packs/{pack}/regenerate` — `governance.packs.regenerate` — `App\Domain\Governance\Http\Controllers\BoardPackController@regenerate` — `app/Domain/Governance/Http/Controllers/BoardPackController.php:250` — middleware `web, auth, permission:governance.packs.view, permission:governance.packs.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/BoardPackController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Packs/Index.tsx`, `resources/js/pages/Governance/Packs/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
