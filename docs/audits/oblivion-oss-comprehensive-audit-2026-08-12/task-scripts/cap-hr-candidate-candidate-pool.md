# CAP-HR-CANDIDATE-CANDIDATE-POOL: Candidate profile pool tags and documents

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.recruitment.view`, `permission:hr.recruitment.manage`
- Owning module: Human resources
- Legacy family: `HR-CANDIDATE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/recruitment/candidates/{candidate}` (`hr.candidates.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.recruitment.view`, `permission:hr.recruitment.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.recruitment.view`, `permission:hr.recruitment.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/recruitment/candidates/{candidate}` (`hr.candidates.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/recruitment/candidates/create` (`hr.candidates.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/CandidateController.php:50-71`.
3. Use `GET|HEAD hr/recruitment/documents/{document}/download` (`hr.candidate.documents.download`, action `downloadDocument`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/CandidateController.php:1986-1997`.
4. Invoke only the owning control for `POST hr/recruitment/candidates` (`hr.candidates.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CandidateController.php:73-159`; `first_name`.
5. Invoke only the owning control for `PUT hr/recruitment/candidates/{candidate}` (`hr.candidates.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/CandidateController.php:415-446`; `first_name`.
6. Invoke only the owning control for `POST hr/recruitment/candidates/{candidate}/documents` (`hr.candidate.documents.store`, action `storeDocument`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CandidateController.php:1937-1984`; `file`.
7. Invoke only the owning control for `POST hr/recruitment/candidates/{candidate}/pool` (`hr.pool.add`, action `addToPool`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CandidateController.php:829-853`; `reason`.
8. Invoke only the owning control for `POST hr/recruitment/candidates/{candidate}/reactivate` (`hr.pool.reactivate`, action `reactivatePool`). Source category: **mutation outcome source gap (reactivatePool)**; controller `app/Http/Controllers/Hr/CandidateController.php:855-896`; `requisition_id`.
9. Invoke only the owning control for `POST hr/recruitment/candidates/{candidate}/tags` (`hr.candidates.tags.update`, action `updateTags`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/CandidateController.php:449-468`; `tags`.
10. Invoke only the owning control for `DELETE hr/recruitment/documents/{document}` (`hr.candidate.documents.destroy`, action `destroyDocument`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/CandidateController.php:1999-2010`; no exact validation fields extracted.
11. Invoke only the owning control for `POST hr/recruitment/tags/delete` (`hr.tags.delete`, action `deleteTag`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/CandidateController.php:512-529`; `tag`.
12. Invoke only the owning control for `POST hr/recruitment/tags/rename` (`hr.tags.rename`, action `renameTag`). Source category: **mutation outcome source gap (renameTag)**; controller `app/Http/Controllers/Hr/CandidateController.php:488-509`; `from`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-1675` at `app/Http/Controllers/Hr/CandidateController.php:73`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1676` at `app/Http/Controllers/Hr/CandidateController.php:165`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1677` at `app/Http/Controllers/Hr/CandidateController.php:415`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeDocument` / `ROUTE-1678` at `app/Http/Controllers/Hr/CandidateController.php:1937`; it is not runtime-observed.
- **created/recorded** is applicable only to `addToPool` / `ROUTE-1679` at `app/Http/Controllers/Hr/CandidateController.php:829`; it is not runtime-observed.
- **mutation outcome source gap (reactivatePool)** is applicable only to `reactivatePool` / `ROUTE-1680` at `app/Http/Controllers/Hr/CandidateController.php:855`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateTags` / `ROUTE-1681` at `app/Http/Controllers/Hr/CandidateController.php:449`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1683` at `app/Http/Controllers/Hr/CandidateController.php:50`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyDocument` / `ROUTE-1684` at `app/Http/Controllers/Hr/CandidateController.php:1999`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadDocument` / `ROUTE-1685` at `app/Http/Controllers/Hr/CandidateController.php:1986`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `deleteTag` / `ROUTE-1716` at `app/Http/Controllers/Hr/CandidateController.php:512`; it is not runtime-observed.
- **mutation outcome source gap (renameTag)** is applicable only to `renameTag` / `ROUTE-1717` at `app/Http/Controllers/Hr/CandidateController.php:488`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/candidates/create.tsx`, `resources/js/pages/hr/candidates/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1675` / `store`: fields `first_name`; success app/Http/Controllers/Hr/CandidateController.php:158 `return redirect()->back()->with('success', 'Candidate created successfully.');`; failure app/Http/Controllers/Hr/CandidateController.php:148 `return redirect()->back()->withErrors(['candidate' => $exception->getMessage()]);`; app/Http/Controllers/Hr/CandidateController.php:151 `return redirect()->back()->withErrors(['candidate' => 'Candidate could not be created.']);`; app/Http/Controllers/Hr/CandidateController.php:155 `return redirect()->back()->withErrors(['candidate' => 'Candidate could not be created.']);`.
- `ROUTE-1677` / `update`: fields `first_name`; success app/Http/Controllers/Hr/CandidateController.php:445 `return redirect()->back()->with('success', 'Candidate updated successfully.');`.
- `ROUTE-1678` / `storeDocument`: fields `file`; success app/Http/Controllers/Hr/CandidateController.php:1983 `return redirect()->back()->with('success', 'Document uploaded.');`.
- `ROUTE-1679` / `addToPool`: fields `reason`; success app/Http/Controllers/Hr/CandidateController.php:852 `return redirect()->back()->with('success', "{$candidate->full_name} added to the talent pool.");`.
- `ROUTE-1680` / `reactivatePool`: fields `requisition_id`; success app/Http/Controllers/Hr/CandidateController.php:895 `return redirect()->back()->with('success', "{$candidate->full_name} re-activated into {$requisition->title}.");`.
- `ROUTE-1681` / `updateTags`: fields `tags`; success app/Http/Controllers/Hr/CandidateController.php:467 `return redirect()->back()->with('success', 'Tags updated.');`.
- `ROUTE-1684` / `destroyDocument`: success app/Http/Controllers/Hr/CandidateController.php:2009 `return redirect()->back()->with('success', 'Document deleted.');`.
- `ROUTE-1716` / `deleteTag`: fields `tag`; success app/Http/Controllers/Hr/CandidateController.php:526 `return redirect()->back()->with('success', $affected === 0`.
- `ROUTE-1717` / `renameTag`: fields `from`; success app/Http/Controllers/Hr/CandidateController.php:506 `return redirect()->back()->with('success', $affected === 0`.

## Failure and recovery paths

- `store`: app/Http/Controllers/Hr/CandidateController.php:148 `return redirect()->back()->withErrors(['candidate' => $exception->getMessage()]);`; app/Http/Controllers/Hr/CandidateController.php:151 `return redirect()->back()->withErrors(['candidate' => 'Candidate could not be created.']);`; app/Http/Controllers/Hr/CandidateController.php:155 `return redirect()->back()->withErrors(['candidate' => 'Candidate could not be created.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/CandidateController.php:443 `$candidate->update($validated);`; app/Http/Controllers/Hr/CandidateController.php:1968 `HrCandidateDocument::create([`; app/Http/Controllers/Hr/CandidateController.php:892 `$candidate->update(['status' => 'new', 'current_stage_entered_at' => now(), 'updated_by' => $user->id]);`; app/Http/Controllers/Hr/CandidateController.php:893 `$candidate->talentPoolMembership()->delete();`; app/Http/Controllers/Hr/CandidateController.php:465 `$candidate->update(['tags' => $tags, 'updated_by' => $user->id]);`; app/Http/Controllers/Hr/CandidateController.php:2006 `\Illuminate\Support\Facades\Storage::disk('private')->delete($document->storage_path);`; app/Http/Controllers/Hr/CandidateController.php:2007 `$document->delete();`; responses app/Http/Controllers/Hr/CandidateController.php:148 `return redirect()->back()->withErrors(['candidate' => $exception->getMessage()]);`; app/Http/Controllers/Hr/CandidateController.php:151 `return redirect()->back()->withErrors(['candidate' => 'Candidate could not be created.']);`; app/Http/Controllers/Hr/CandidateController.php:155 `return redirect()->back()->withErrors(['candidate' => 'Candidate could not be created.']);`; app/Http/Controllers/Hr/CandidateController.php:158 `return redirect()->back()->with('success', 'Candidate created successfully.');`; app/Http/Controllers/Hr/CandidateController.php:207 `return [`; app/Http/Controllers/Hr/CandidateController.php:396 `return Inertia::render('hr/candidates/show', [`; app/Http/Controllers/Hr/CandidateController.php:445 `return redirect()->back()->with('success', 'Candidate updated successfully.');`; app/Http/Controllers/Hr/CandidateController.php:1983 `return redirect()->back()->with('success', 'Document uploaded.');`; app/Http/Controllers/Hr/CandidateController.php:852 `return redirect()->back()->with('success', "{$candidate->full_name} added to the talent pool.");`; app/Http/Controllers/Hr/CandidateController.php:877 `return redirect()->back()->with('error', 'This candidate already has an active application for that requisition.');`; app/Http/Controllers/Hr/CandidateController.php:889 `return redirect()->back()->with('error', $exception->getMessage());`; app/Http/Controllers/Hr/CandidateController.php:895 `return redirect()->back()->with('success', "{$candidate->full_name} re-activated into {$requisition->title}.");`; app/Http/Controllers/Hr/CandidateController.php:467 `return redirect()->back()->with('success', 'Tags updated.');`; app/Http/Controllers/Hr/CandidateController.php:66 `return Inertia::render('hr/candidates/create', [`; app/Http/Controllers/Hr/CandidateController.php:2009 `return redirect()->back()->with('success', 'Document deleted.');`; app/Http/Controllers/Hr/CandidateController.php:1996 `return $disk->download($document->storage_path, $document->original_name);`; app/Http/Controllers/Hr/CandidateController.php:521 `return redirect()->back()->with('error', 'Enter a tag to remove.');`; app/Http/Controllers/Hr/CandidateController.php:526 `return redirect()->back()->with('success', $affected === 0`; app/Http/Controllers/Hr/CandidateController.php:501 `return redirect()->back()->with('error', 'Both the current and new tag are required.');`; app/Http/Controllers/Hr/CandidateController.php:506 `return redirect()->back()->with('success', $affected === 0`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/recruitment/candidates` — `hr.candidates.store` — `App\Http\Controllers\Hr\CandidateController@store` — `app/Http/Controllers/Hr/CandidateController.php:73` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `GET|HEAD hr/recruitment/candidates/{candidate}` — `hr.candidates.show` — `App\Http\Controllers\Hr\CandidateController@show` — `app/Http/Controllers/Hr/CandidateController.php:165` — middleware `web, auth, permission:hr.recruitment.view`
- `PUT hr/recruitment/candidates/{candidate}` — `hr.candidates.update` — `App\Http\Controllers\Hr\CandidateController@update` — `app/Http/Controllers/Hr/CandidateController.php:415` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/candidates/{candidate}/documents` — `hr.candidate.documents.store` — `App\Http\Controllers\Hr\CandidateController@storeDocument` — `app/Http/Controllers/Hr/CandidateController.php:1937` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/candidates/{candidate}/pool` — `hr.pool.add` — `App\Http\Controllers\Hr\CandidateController@addToPool` — `app/Http/Controllers/Hr/CandidateController.php:829` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/candidates/{candidate}/reactivate` — `hr.pool.reactivate` — `App\Http\Controllers\Hr\CandidateController@reactivatePool` — `app/Http/Controllers/Hr/CandidateController.php:855` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/candidates/{candidate}/tags` — `hr.candidates.tags.update` — `App\Http\Controllers\Hr\CandidateController@updateTags` — `app/Http/Controllers/Hr/CandidateController.php:449` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `GET|HEAD hr/recruitment/candidates/create` — `hr.candidates.create` — `App\Http\Controllers\Hr\CandidateController@create` — `app/Http/Controllers/Hr/CandidateController.php:50` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `DELETE hr/recruitment/documents/{document}` — `hr.candidate.documents.destroy` — `App\Http\Controllers\Hr\CandidateController@destroyDocument` — `app/Http/Controllers/Hr/CandidateController.php:1999` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `GET|HEAD hr/recruitment/documents/{document}/download` — `hr.candidate.documents.download` — `App\Http\Controllers\Hr\CandidateController@downloadDocument` — `app/Http/Controllers/Hr/CandidateController.php:1986` — middleware `web, auth, permission:hr.recruitment.view`
- `POST hr/recruitment/tags/delete` — `hr.tags.delete` — `App\Http\Controllers\Hr\CandidateController@deleteTag` — `app/Http/Controllers/Hr/CandidateController.php:512` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/tags/rename` — `hr.tags.rename` — `App\Http\Controllers\Hr\CandidateController@renameTag` — `app/Http/Controllers/Hr/CandidateController.php:488` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/CandidateController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/candidates/create.tsx`, `resources/js/pages/hr/candidates/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
